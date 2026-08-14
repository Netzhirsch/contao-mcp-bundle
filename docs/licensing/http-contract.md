# License server — HTTP contract

The MCP bundle ships the **client half** of a trial → paid-subscription license
(all-or-nothing: a valid token unlocks every tool, no free tier). The **server
half** — issuing + renewing tokens, enforcing "one trial per domain/account",
tying renewal to a paid subscription — is the vendor's own service. This
document is the contract between the two.

Token verification is fully **offline** (Ed25519 signature); the bundle only
talks to the server to **fetch** a freshly signed token (trial / renewal).

---

## 1. Token format

`base64url(payloadJson) + "." + base64url(signature)` — a detached Ed25519
signature over the exact payload JSON bytes.

Payload:

| Field | Type | Meaning |
|---|---|---|
| `product` | string | must equal `netzhirsch/contao-mcp-bundle` |
| `domain` | string | licensed host, normalised (lowercase, no scheme, no `www.`, no port) |
| `type` | string | `trial` or `full` |
| `license_id` | string | server-side id (audit / revocation) |
| `issued_at` | int | unix timestamp |
| `expires_at` | int | unix timestamp |

The bundle verifies: signature (vendor public key), `product`, `domain` ==
current host, `issued_at <= now <= expires_at` (with a forward-only high-water
mark against clock rollback, plus a 3-day grace past `expires_at` to absorb
renewal outages).

**Keypair (vendor, one-time):**

```bash
vendor/bin/contao-console contao:mcp:license keygen
```

Prints an Ed25519 keypair. The **public** key goes into
`src/License/LicenseToken.php` → `VENDOR_PUBLIC_KEY_B64` (shipped in the bundle,
safe — it can only verify). The **secret** key stays on the license server only.
Signing uses `LicenseToken::sign($payload, $secretKeyB64)` (Ed25519 detached).

---

## 2. `POST {license_server_url}/trial`

Start a trial. **This is where "cannot be restarted" lives:** the server MUST
refuse a second trial for the same `domain` OR `account_email`. Deleting
`var/mcp/license.json`, wiping the DB or reinstalling on the customer side does
**not** reset it — the record is on the server.

Request (JSON):
```json
{ "product": "netzhirsch/contao-mcp-bundle", "domain": "kunde.de", "account_email": "info@kunde.de" }
```

Response `200`:
```json
{ "token": "<base64url>.<base64url>", "expires_at": 1760000000 }
```

Errors: `409 {"error":"trial_already_used","message":"…"}` (domain/account seen
before), `422 {"error":"missing_fields"}`.

Reference server logic:
```php
$used = $db->fetchOne(
    'SELECT 1 FROM trials WHERE product = ? AND (domain = ? OR account = ?) LIMIT 1',
    [$product, $domain, $account]
);
if ($used !== false) { return json(409, ['error' => 'trial_already_used']); }
// else: insert row, sign a `type:trial` token with exp = now + 30d, return it.
```

---

## 3. `POST {license_server_url}/renew`

Renew the subscription token. The server issues a fresh token **only while the
subscription is paid**; otherwise it declines and the installed token runs out
its remaining lifetime + grace, then the tools lock.

Request (JSON):
```json
{ "product": "netzhirsch/contao-mcp-bundle", "domain": "kunde.de", "token": "<current token>" }
```

Response `200`: `{ "token": "…", "expires_at": … }` (issue a `type:full` token,
e.g. `exp = now + 35d` while paid — keep it a bit longer than the renewal
interval so a missed cron run doesn't lock a paying customer out).

Errors:
- `402 {"error":"subscription_inactive"}` — unpaid (cancelled / past-due). Do
  **not** issue. Graceful wind-down: the installed token runs out its remaining
  lifetime + 3-day grace, then the tools lock.
- `403 {"error":"revoked"}` — **authoritative kill switch.** The bundle clears
  the stored token **immediately** (no grace); the tools lock at the next call.
  This is how you cut off any license at once — including a long-lived internal
  one. A plain connectivity failure is NOT this, so a server outage never
  triggers it.
- `404 {"error":"unknown_license"}`, `409 {"error":"domain_mismatch"}`.

Every other non-200 is non-fatal: the stored token stays valid until it actually
expires (+ grace).

**Renewal is automatic.** The bundle's `LicenseRenewalCron` (Contao cron, hourly)
refreshes the token and picks up revocations, throttled to at most one real call
per 6h. Because each renew renews the *full* lifetime, the token almost always
has (nearly) its full ~35 days left — so the license server may be unreachable
for a long stretch before grace even matters. Frequent renewal + offline
verification is what turns a server outage into a non-event.

Low-traffic installs without Contao's web-triggered cron should run the standard
Contao system cron (which drives `LicenseRenewalCron`). A one-off manual renewal:

```bash
vendor/bin/contao-console contao:mcp:license renew
```

---

## 3b. Stripe-hosted payment (backend-driven)

The customer starts everything in their own Contao backend (MCP-Server →
"License"), but **card/SEPA data is entered only on Stripe-hosted pages** — never
in Contao or on the license server (PCI stays with Stripe).

`POST {license_server_url}/checkout-session`
```json
{ "product": "netzhirsch/contao-mcp-bundle", "domain": "kunde.de", "account_email": "info@kunde.de", "plan": "standard" }
```
→ `200 { "url": "https://checkout.stripe.com/…" }` — a Stripe Checkout Session for
the product/price (domain in metadata). The backend opens the URL; on success
Stripe fires `checkout.session.completed` → the server marks the subscription
active and issues the license token.

`POST {license_server_url}/portal-session`
```json
{ "product": "netzhirsch/contao-mcp-bundle", "domain": "kunde.de", "token": "<current token>" }
```
→ `200 { "url": "https://billing.stripe.com/…" }` — a Stripe Customer Portal
session (change card/SEPA, cancel, invoices). The backend opens the URL.

Errors (both): `400/404 {"error":"…","message":"…"}`. The bundle only opens the
returned URL; it stores nothing payment-related.

## 4. Bundle-side surface (already implemented)

| Piece | Where |
|---|---|
| Offline verify (Ed25519 + domain + clock) | `src/License/LicenseToken.php` |
| Token + high-water-mark storage (`var/mcp/license.json`) | `src/License/LicenseStore.php` |
| Gate decision + grace | `src/License/LicenseGate.php` |
| Renewal / trial HTTP client (+ clears token on `revoked`) | `src/License/RenewalClient.php` |
| Auto-renew + revocation pickup (hourly Contao cron) | `src/Cron/LicenseRenewalCron.php` |
| Tool-layer gate (per `tools/call`) | `src/Controller/McpController.php` |
| CLI (`status`/`activate`/`trial`/`renew`/`keygen`) | `src/Command/McpLicenseCommand.php` |
| Config `license_server_url` | `var/mcp/config.json` |

Config: the license server URL is **baked into the bundle**
(`RenewalClient::DEFAULT_LICENSE_SERVER_URL`) — customers never set it. The
`license_server_url` config field is only an **optional override** for dev/testing
(empty = the baked default). The vendor **public key** is likewise baked in, never
in config. Neither a customer-editable URL nor key is a bypass — a forged server
cannot sign valid tokens — it would only break that install's own licensing.
Enforcement is unconditional (no flag).

---

## 5. Enforcement & token tiers (single edition)

There is **one** edition. The real vendor public key is baked into the shipped
code, so enforcement is **unconditional** — every install runs the gate. There is
no config field, env var or build flag to toggle it (all customer-editable → a
one-line bypass). The only difference between a paying customer and a
Netzhirsch-hosted instance lives in the **token the server issues**, not in the
code:

| Tier | `type` | Lifetime | For |
|---|---|---|---|
| Trial | `trial` | ~30 d ("1 month free"), one per domain/account | any new install |
| Subscription | `full` | ~35 d, renewed while paid | self-pay customers |
| Internal | `full` | ~35 d, renewed while the entitlement stands | Netzhirsch-hosted sites |

"Perpetual" is a property of the **entitlement on the server** (the Internal plan
always renews), not a 100-year token — a token that never phoned home could never
be revoked. Every tier is short-lived and kept alive by `LicenseRenewalCron`, so
every tier — Internal included — stays **revocable** (see §3, `403 revoked`).

To operate it:

1. `keygen` once; keep the **secret** on the license server, bake the **public**
   key into `LicenseToken::VENDOR_PUBLIC_KEY_B64` (already done — safe to commit,
   it can only verify).
2. Stand up the license server implementing §2 + §3 + §3b (+ billing → mark
   subscriptions paid; e.g. a Stripe webhook flips a `paid` flag `/renew` reads).
3. Ship one public package `netzhirsch/contao-mcp-bundle` (Contao-Manager /
   catalog installable). No `-pro` variant, no fork.
4. On any install: set `license_server_url`; the customer starts a trial /
   subscribes from the backend. For a Netzhirsch-hosted site, issue an Internal
   token from the admin API instead (or `activate` a self-signed one).

Honest limit: the code is PHP on the customer's server — a determined actor can
patch the gate/key out (it then breaks on every `composer update`). The real
protection is that no valid token can be minted without the secret key; shipping
the package publicly is fine because without a token it does nothing.
