# Contao MCP Bundle

[![CI](https://github.com/Netzhirsch/contao-mcp-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/Netzhirsch/contao-mcp-bundle/actions/workflows/ci.yml)

*🇩🇪 [Deutsche Fassung](README.md) — the German README is the reference version and
carries additional development notes.*

**Status:** Stable — `v1.4.1`
**License:** proprietary, commercially licensed — 30-day free trial, then
€49/month per Contao installation (see [License & trial](#license--trial) and
[LICENSE](LICENSE))

A [Model Context Protocol](https://modelcontextprotocol.io/) server packaged as a
Contao 5 bundle. It connects Claude Desktop, Claude on the web, Claude Code, the
MCP Inspector or any other MCP-capable AI directly to the Contao backend — with
no REST endpoints of your own, no middleware and no extra port.

Instead of building a bespoke API endpoint for every AI task, the AI session gets
structured access to the whole DCA stack: editors can create content by
describing it, pipelines can populate pages from third-party systems, developers
can script structural migrations — all through the same **175 tools**, and all
constrained by exactly the same backend permissions that apply when a person
clicks through the backend.

**Supported entities:** news, pages, articles, calendars, FAQs, members, forms,
newsletters, comments, themes, layouts, modules, image sizes, templates, files,
URL rewrites, form leads, maintenance and system settings.

## What you get

- **175 tools** across Contao core entities plus popular extensions.
- **Lazy-mode discovery**: three meta tools (`contao_search_tools`,
  `contao_describe_tool`, `contao_call`) hide the rest from `tools/list` — worth
  roughly 12 KB of system-prompt overhead per turn in Claude Desktop.
- **OAuth 2.1** with PKCE and Dynamic Client Registration (RFC 7591), including
  an Initial-Access-Token gate for `restricted` mode.
- **Permission parity**: every backend user's rights apply to the AI 1:1 —
  enforced through Contao's own voters, not reimplemented. Writing a DCA field
  marked `excluded` additionally requires the `alexf` right for that field.
- **Full-text site search**: `search_query` queries Contao's own search index
  (`tl_search`), so it also finds text produced by modules, includes or
  extensions that the CRUD tools cannot see. Protected pages are always excluded;
  `search_index_status` tells you whether the index was ever populated.
- **Filesystem search**: `files_search` (recursive glob over the upload tree,
  POSIX syntax plus `**`, basename matching for patterns without a slash).
- **Site-building helpers**: `entity_move`, `page_cache_invalidate`,
  `system_settings_update`, `insert_tags_list`, `page_preview`, `maintenance_run`,
  `dbafs_sync` (reconcile `tl_files` against the disk).
- **External IDs** make repeated imports idempotent — the same source row updates
  the same record instead of creating duplicates.
- **Optional extension tools** appear automatically once the matching bundle is
  installed (and report a clean `extension_not_available` error otherwise):
  newsletter, comments, `url_rewrite_*` (terminal42) and — read-only —
  `leads_list` + `lead_get` for form submissions (`terminal42/contao-leads`).
- **Author pass-through**: writes are recorded under the real OAuth user in
  `tl_log` and `tl_version`, with a distinct log source so AI actions can be told
  apart from manual ones.
- **Deletions are recoverable**: whatever the AI deletes is mirrored into
  `tl_undo` together with its child records, restorable through Contao's own
  *Undo* in the backend. Restoring stays deliberately manual — the AI can delete,
  but it cannot quietly bring something back.
- **Deletions that would break something are refused**: `usage_find` answers
  "where is this used?" for pages, files, images, articles, modules, forms,
  templates, image sizes and more — and **the same check runs automatically
  before every `*_delete`**. It looks in four places: database fields (derived
  from the DCA, so extension fields are covered too), **insert tags in any text
  column** (`{{link::42}}`, by alias as well, `{{file::…}}`,
  `{{insert_module::…}}`), **inside files** (`@import`/`url()` in SCSS/CSS,
  hardcoded paths in templates), and for **templates** every `customTpl`/`…Tpl`
  column pointing at it plus `{% extends %}` / `$this->extend()` from other
  templates. Only findings that are both provable and breaking refuse a deletion;
  backend permission mounts and mere name mentions are reported but never block.
  Override with `ignore_references=true` (recorded in `tl_log`).
- **Renames and moves are checked too — but only where they actually break**:
  `file_rename`, `file_move` and `template_rename` run through the same check.
  Contao keeps the row, its id and its UUID across a rename and only rewrites
  `tl_files.path`, so `singleSRC = <uuid>` and `{{file::<uuid>}}` survive it,
  while `{{file::files/x.svg}}`, an SCSS `@import` and a hardcoded template path
  do not. Moving a legacy `.html5` template into another folder is therefore not
  blocked at all — Contao finds it by basename, which does not change.
- **Backend module** "MCP-Server" with four areas: status (license, start
  trial/subscription, OAuth clients, IATs), configuration, activity log and the
  tool panel (every tool individually switchable) — **administrators only**.
- **Tested on Linux and Windows** (Laragon for development, Debian in production).

## Installation

### 1. Composer

```bash
composer require netzhirsch/contao-mcp-bundle
```

That is all — no `repositories` entry, no patch block, no `allow-plugins`. The
bundle is on [Packagist](https://packagist.org/packages/netzhirsch/contao-mcp-bundle).

Or search for "Contao MCP Bundle" in the **Contao Manager** and install it there.

### 2. Register the bundle

Handled by the Contao Manager Plugin — there is nothing to add to
`config/bundles.php`.

### 3. Schema migrations and initial config

```bash
vendor/bin/contao-console contao:migrate --env=prod
```

This creates the OAuth tables (`tl_mcp_oauth_*`) and adds the external-ID columns
to 24 entity tables. The default configuration runs **unauthenticated** — for
production, switch `auth_mode` to `oauth` (backend module or
`var/mcp/config.json`).

The MCP endpoint is live immediately after the migration at
`https://<backend_url>/mcp` — Apache/PHP-FPM serves it like any other Symfony
route. No daemon, no port, no reverse proxy.

### 4. Activate the license (30 days free)

Without an active license every tool answers `license_inactive` — Contao itself
keeps running normally. In the backend under **MCP-Server → Status**, click
**"Start trial"**: 30 days, no payment details. See
[License & trial](#license--trial).

### 5. Connect a client

Guides in this repository: [docs/installation.md](docs/installation.md)
(connecting a client, online and locally) and
[docs/dokumentation.md](docs/dokumentation.md) (complete feature reference).
Both are written in German.

> **Gotcha with `oauth_registration_mode: restricted` (the default):** Claude,
> `mcp-remote` and friends cannot send an Initial Access Token during
> registration, so the IAT button does not help there. Instead click
> **MCP-Server → Status → "Open registration for 10 minutes"** in the backend and
> connect the client **immediately**. The window closes after the **first**
> successful registration; reopen it for each further attempt.

A step-by-step walkthrough for local connector setup (`mcp-remote` bridge,
`claude_desktop_config.json`, OAuth, schema cache and the usual traps) is in
**[docs/mcp-client-lokal-einrichten.md](docs/mcp-client-lokal-einrichten.md)**.

## License & trial

The bundle is commercially licensed. The tool layer is what the license protects:
without a valid license every `tools/call` returns a `license_inactive` error
(`ping` excepted). **Contao itself is never affected** — frontend, backend and all
other extensions keep running unchanged.

| | |
|---|---|
| **Trial** | 30 days, **no payment details**, one per domain/account |
| **Price** | **€49/month** or **€539/year** (12 for the price of 11), net, plus VAT |
| **Unit** | per **Contao installation** — regardless of how many front-end domains it serves |
| **Payment** | card or SEPA direct debit, exclusively on **Stripe-hosted** pages |
| **Staging/dev** | free (local hosts and subdomains of a paid domain) |

**Ordering happens in the backend** — everything sits under **MCP-Server →
Status** in the button bar at the top:

1. **"Start trial"** → unlocks the tools for 30 days.
2. **"Subscribe"** → opens the Stripe payment page. Card and SEPA details are
   entered **only at Stripe** and never stored in Contao.
3. **"Manage subscription"** → Stripe customer portal (payment method, invoices,
   cancellation).

**Or via CLI:**

```bash
vendor/bin/contao-console contao:mcp:license status           # current state
vendor/bin/contao-console contao:mcp:license trial <email>    # start the trial
vendor/bin/contao-console contao:mcp:license activate <token> # install a token
```

**Renewal is automatic.** The `LicenseRenewalCron` job (hourly, throttled)
refreshes the token, while verification itself is **offline** (Ed25519). An
outage of the license server therefore locks nobody out — and there are 3 days of
grace after expiry on top. A running Contao cron is the prerequisite.

> The license server is `https://license.netzhirsch.de`, baked into the bundle —
> **nothing to configure**. Only the domain, the product id, an installation
> secret and the ordering backend user's e-mail address are transmitted. No
> content, no editorial data, no visitor data, no telemetry.

## Requirements

- **PHP** `^8.1` with the extensions `openssl`, **`sodium`**, `pdo_mysql`,
  `mbstring`, `intl`. `sodium` is mandatory for license verification — without it
  every tool stays locked. CI tests PHP 8.1 (against Contao 5.3) as well as 8.3
  and 8.4 (against Contao 5.7).
- **Contao** ≥ 5.3. Contao 4.13 is not supported.
- **Symfony** ≥ 6.4 or 7.x
- **MySQL** ≥ 8.0 or MariaDB ≥ 10.6 (strict mode supported)
- **HTTPS** in production — required in practice by OAuth 2.1.

Shared hosting is fine: the bundle is HTTP-only, needs no daemon, no open port
and no shell access (install through the Contao Manager in that case).

## Transport and protocol

Streamable HTTP on a single endpoint, `POST /mcp`. There is **no SSE channel** —
no GET stream and no server-initiated messages, just request/response JSON.
Protocol revision **2025-03-26**; clients speaking **2024-11-05** are accepted as
well. Any MCP client that speaks Streamable HTTP with OAuth should work; Claude
is what we test against.

## Configuration

File: `var/mcp/config.json` (created the first time the backend module is
opened). There is no `config.yaml` to edit and no environment variable to set.

> The four **MCP-Server backend modules are restricted to administrators** — they
> switch `auth_mode` (and with it the entire permission check), hand out OAuth
> registrations, revoke clients and start paid subscriptions. A non-admin gets
> "access denied" even with the module right granted.

| Key | Default | Meaning |
|---|---|---|
| `path` | `mcp` | URL path (no leading slash) |
| `pagination_limit` | `500` | Max tools per `tools/list` (irrelevant in lazy mode) |
| `auth_mode` | `none` | `none` or `oauth` |
| `backend_url` | `""` | Public base URL of the Contao backend (required for OAuth) |
| `oauth_registration_mode` | `restricted` | `restricted` (IAT required) or `open` |
| `lazy_mode` | `false` | When `true`, only 6 discovery tools appear in `tools/list` |

Bundle configuration in `config/packages/netzhirsch_contao_mcp.yaml`:

```yaml
netzhirsch_contao_mcp:
    write:
        default_author_id: 1   # fallback when auth_mode=none
```

## Health check before a production deploy

```jsonc
// MCP call
{"tool": "system_health_check"}
```

Returns a structured report on the PHP setup, `var/mcp/` permissions and the
OAuth configuration, plus `warnings: [...]` with concrete fix commands. Worth
running before every site move or server change.

## Rate limiting

600 tool calls per minute per client (sliding window).

## Backup

The bundle persists four separate surfaces. A complete restore needs all four —
otherwise either OAuth tokens become invalid (keys gone) or tool calls can no
longer resolve external references (external IDs gone).

| Surface | Path | Restore behaviour |
|---|---|---|
| OAuth RSA keys + encryption key | `var/mcp/oauth/*.pem`, `var/mcp/oauth/encryption.key` | Mandatory. Missing → all refresh tokens invalid, all access tokens must be reissued. Mode 0600 required. |
| Bundle config | `var/mcp/config.json` | Optional. Missing → defaults apply, the operator has to re-enable `auth_mode=oauth`. |
| OAuth tables | `tl_mcp_oauth_client`, `tl_mcp_oauth_access_token`, `tl_mcp_oauth_refresh_token`, `tl_mcp_oauth_auth_code`, `tl_mcp_oauth_iat` | Mandatory for a seamless migration. Missing → clients must register again. |
| External-ID columns | `external_id_namespace` + `external_id_key` on 24 entity tables | Mandatory for integrations. Missing → updates have to go through Contao primary keys instead of external references. |

Recommended: `tar` over `var/mcp/`, a mysqldump of the five `tl_mcp_oauth_*`
tables, and a dump of the full Contao schema (the external-ID columns live on the
entity tables, so they cannot be backed up separately).

## Updating from 1.4.0 or older

The patch files are gone as of 1.4.1. If your **root** `composer.json` still
carries the former patch block, remove it **before** updating — otherwise
`cweagans/composer-patches` looks for files that no longer ship and aborts:

- the `extra.patches` entry for `php-mcp/server`
- `"cweagans/composer-patches"` from `require` (unless something else needs it)
- `"cweagans/composer-patches": true` from `config.allow-plugins`

Then update as usual with `composer update netzhirsch/contao-mcp-bundle`.

## Maintenance

```bash
composer update netzhirsch/contao-mcp-bundle
```

**No vendor patches are applied.** What the bundle needs from the dispatcher
(the lazy-mode tool filter and the post-call cleanup) lives in
`Server\ContaoDispatcher`, a subclass. After a `php-mcp/server` major bump,
check there that `handleToolList()` and `handleToolCall()` still line up.

| Command | Purpose | Suggested cadence |
|---|---|---|
| `contao:mcp:license status\|trial\|activate\|renew` | manage license and trial | as needed (renewal runs via cron) |
| `contao:mcp:oauth:cleanup` | purge expired auth codes, tokens and IATs | daily, as a cron job |
| `contao:mcp:oauth:rotate-keys` | rotate the OAuth RSA signing keys (dual-key, nobody is logged out) | monthly |
| `contao:mcp:permission-debug` | find out why a backend user may or may not use a tool | when troubleshooting |
| `contao:mcp:smoke-test` | end-to-end self-test of the tool layer | after updates or a server move |

The Contao cron must be running (`contao:cron` or the web cron) — automatic
license renewal depends on it.

## Known limitations

As of `v1.4.1`:

- **PHPUnit coverage** focuses on OAuth crypto, the permission map and the usage
  scanner. The tool layer is exercised end-to-end by the smoke test instead.
- **Encryption-key rotation** is not implemented. `var/mcp/oauth/encryption.key`
  protects refresh-token payloads at rest; rotating it would invalidate every
  refresh token. The RSA **signing** keys can be rotated (see
  `contao:mcp:oauth:rotate-keys`).
- **License domain binding** evaluates the configured `backend_url`. That is a
  commercial boundary, not a cryptographic one — it keeps honest installations
  apart but the operator of the instance can influence it.

## Reporting a vulnerability

Please do **not** open a public issue. Use the [security policy](SECURITY.md) —
GitHub Security Advisory or <kalus@netzhirsch.de>.

## Bug reports

Issues go to this repository. Please attach:

- the output of `system_health_check`
- the backend user's role and the Contao version
- relevant entries from `var/log/prod.log` (the standard Symfony log)

## Support

<netzhirsch@netzhirsch.de>, response within 24 hours, included in the
subscription. Paid setup assistance is planned as a separate, bookable service.

## Development

```bash
composer verify
```

Runs PHPStan and PHPUnit — exactly what CI runs. Once per clone, run
`composer setup-hooks`: a `pre-push` hook will then refuse any push that would
turn CI red (`git push --no-verify` bypasses it in an emergency).

The **smoke test** needs a running Contao with a database and is therefore not
part of it. It belongs before every release tag:

```bash
vendor/bin/contao-console contao:mcp:smoke-test --env=dev
```

Release order: `composer verify` → smoke test → commit → push → **wait for CI to
go green** → only then tag.

---
*Maintainer: Jan-Philipp Kalus &lt;kalus@netzhirsch.de&gt; — Netzhirsch*
