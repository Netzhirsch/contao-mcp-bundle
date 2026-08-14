# Delivery & token tiers (one edition, no fork)

The bundle ships as a **single** edition from a single codebase — no fork, no
`-pro` build, no baked-in "free vs commercial" switch. The real vendor public key
is committed, so enforcement is **unconditional**: every install runs the license
gate. What separates a paying customer from a Netzhirsch-hosted site is only the
**token the license server issues**.

## One package

`netzhirsch/contao-mcp-bundle` — public, `type: contao-bundle`, installable in one
step from the Contao Manager or the extension catalog. Because the gate blocks
usage until a valid token is present, a public package is safe: without a token it
does nothing. Gating updates/support behind a paid Composer channel is optional
belt-and-suspenders, not the paywall — the license server is.

## Token tiers (all issued by the server)

| Tier | `type` | Lifetime | Who |
|---|---|---|---|
| **Trial** | `trial` | ~14 d, one per domain/account (server-enforced, non-restartable) | any new install |
| **Subscription** | `full` | ~35 d, auto-renewed while paid (Stripe) | self-pay customers |
| **Internal** | `full` | ~35 d, auto-renewed while the entitlement stands | Netzhirsch-hosted sites |

"Unlimited / internal" is a property of the **server-side entitlement** (the
Internal plan always renews), **not** a long-lived token. A token that never
renewed could never be revoked — so every tier is short-lived and kept fresh by
`LicenseRenewalCron` (hourly). That same renewal call is the revocation channel.

## Renewal & revocation

- **Auto-renew:** `src/Cron/LicenseRenewalCron.php` renews on the Contao cron,
  throttled to one real call / 6h. A frequently-renewed token almost always has
  its full lifetime left, so the license server can be down for a long stretch
  before the 3-day grace even matters.
- **Revoke (fast):** the server answers `/renew` with `403 {"error":"revoked"}`;
  the bundle clears the token immediately (no grace). Works for any tier —
  including Internal.
- **Cancellation (graceful):** `402 subscription_inactive` → the token simply runs
  out its remaining lifetime + grace, then the tools lock (core Contao keeps
  running).

## Rollout per customer

- **Self-pay:** install the public package (Contao Manager) → set
  `license_server_url` → start the trial in the backend → subscribe (Stripe-hosted).
- **Netzhirsch-hosted:** install the same package → issue an **Internal** token
  from the license-server admin API for the domain (or `activate` a self-signed
  `type:full` token). Nothing else differs.

## Honest limit

The gate/key are PHP source on the customer's server, so a determined actor can
patch them out — but that breaks on every `composer update` and is unambiguous
circumvention. The real protection is the signature (no valid token without the
vendor secret key). An encoder (ionCube/SourceGuardian) is the only true
anti-tamper option and is deliberately **not** used here (loader dependency +
community friction outweigh the benefit for a Contao bundle).
