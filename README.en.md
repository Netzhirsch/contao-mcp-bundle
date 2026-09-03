# Contao MCP Bundle

[![CI](https://github.com/Netzhirsch/contao-mcp-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/Netzhirsch/contao-mcp-bundle/actions/workflows/ci.yml)

*🇩🇪 [Deutsche Fassung](README.md) — the German README is the reference version and
carries additional development notes.*

**Status:** Stable — `v1.8.2`
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
can script structural migrations — all through the same **186 tools**, and all
constrained by exactly the same backend permissions that apply when a person
clicks through the backend.

**Supported entities:** news, pages, articles, calendars, FAQs, members, forms,
newsletters, comments, themes, layouts, modules, image sizes, templates, files,
URL rewrites, form leads, maintenance and system settings.

## What you get

- **186 tools** across Contao core entities plus popular extensions.
- **Lazy-mode discovery**: three meta tools (`contao_search_tools`,
  `contao_describe_tool`, `contao_call`) hide the rest from `tools/list` — worth
  roughly 12 KB of system-prompt overhead per turn in Claude Desktop.
- **OAuth 2.1** with PKCE, Client ID Metadata Documents (CIMD), Dynamic Client
  Registration (RFC 7591) and Protected Resource Metadata (RFC 9728). With CIMD
  Claude connects **with no preparation in the backend at all** — no pairing
  window, no open registration. Registration is still there for clients that
  want it: in the default `restricted` mode only while the 15-minute pairing
  window is open.
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
- **Build in one call instead of a list of steps**: `pages_create_tree` and
  `pages_delete_tree` for the page tree, `content_create_tree` for a whole block
  of content elements including nested containers. Everything checkable is
  checked before the first write; `dry_run` shows the plan.
- **`entity_field_patch`** replaces one passage inside a text column instead of
  resending the whole value. `old` has to occur exactly as often as expected, or
  the call refuses without touching the record — and the write still goes through
  the table's own `*_update` tool, with its Versions snapshot.
- **`html_filter_info` + `html_filter_preview`** show what Contao's output filter
  will leave of your markup BEFORE it is written. Stored is not rendered: a
  read-back returns the markup unchanged while `<input type>` and `<label for>`
  are long gone in the frontend.
- **External IDs** make repeated imports idempotent — the same source row updates
  the same record instead of creating duplicates.
- **Optional extension tools** appear automatically once the matching bundle is
  installed (and report a clean `extension_not_available` error otherwise):
  newsletter, comments, `url_rewrite_*` (terminal42), — read-only —
  `leads_list` + `lead_get` for form submissions (`terminal42/contao-leads`),
  and **DeepL translation** (`numero2/contao-deepl`, see below).
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

> **Connecting a client with `oauth_registration_mode: restricted` (the default):**
> Click **MCP-Server → Status → "Open registration for 15 minutes"** in the
> backend. The window stays open for the full 15 minutes, however many attempts
> that takes (up to 1.4.0 it closed after the first successful registration,
> which is why retries and second clients failed). Refused attempts are listed
> with reason and IP under **MCP-Server → Aktivität**.

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
- **Contao** 5.3 through 6.0 (CI runs the smoke test against 5.3, 5.7 **and**
  6.0). Contao 4.13 is not supported.
- **Symfony** ≥ 6.4, 7.x or 8.x

### Contao 6

Runs unchanged. Two things to know when installing:

**Contao 6 requires PHP ≥ 8.4.** The bundle's own floor stays at 8.1 so Contao
5.3 instances keep working — on PHP 8.1 only the 5.x line is installable anyway.

**The install needs `-W`:**

```bash
composer require netzhirsch/contao-mcp-bundle -W
```

The reason is not this bundle but `php-mcp/server`: it pins
`phpdocumentor/reflection-docblock` to `^5.6` and `symfony/finder` to `^7.2`,
while a Contao 6 app resolves both higher. `-W` lets Composer move them back
down, which both packages tolerate; without it the resolution fails. On Contao 5
the flag is unnecessary.
- **MySQL** ≥ 8.0 or MariaDB ≥ 10.6 (strict mode supported)
- **Storage for `var/mcp/`**: writable, nothing more. Since 1.9.1 the bundle
  writes its state files atomically via `rename()` and needs **no working file
  locking** — NFS mounts without `lockd`/`statd` are fine. On ≤ 1.9.0 `flock()`
  could block there indefinitely and cause a gateway timeout (see CHANGELOG
  1.9.1).
- **HTTPS** in production — required in practice by OAuth 2.1.

Shared hosting is fine: the bundle is HTTP-only, needs no daemon, no open port
and no shell access (install through the Contao Manager in that case).

## Transport and protocol

Streamable HTTP on a single endpoint, `POST /mcp`. There is **no SSE channel** —
no GET stream and no server-initiated messages, just request/response JSON.
Protocol revision **2025-03-26**; clients speaking **2024-11-05** are accepted as
well. Any MCP client that speaks Streamable HTTP with OAuth should work; Claude
is what we test against.

## Connecting without pairing: CIMD

Since 1.11.0 a client may identify itself with an HTTPS URL instead of
registering — the server reads the client's details from that URL
([Client ID Metadata Document](https://datatracker.ietf.org/doc/html/draft-ietf-oauth-client-id-metadata-document-00)).
For the customer that means **no pairing window, nothing to prepare**. Claude
picks this route by itself when the instance advertises it.

Switchable in the backend under **MCP server → Configuration**:

| Mode | Meaning |
|---|---|
| `trusted` *(default)* | only `claude.ai`, `claude.com` and their subdomains |
| `open` | any HTTPS `client_id`, the specification's open-server posture |
| `off` | not advertised; clients register as before (DCR) |

The default is `trusted` because "accept any HTTPS URL" means "fetch any HTTPS
URL a caller names". On a customer's production CMS that is a bigger promise
than the feature is worth — the clients Contao talks to here are known ones.

**What the fetch does.** The document is retrieved before anyone is
authenticated, from a URL the caller chose. The rules are correspondingly
narrow:

- `https` only, with a path, no fragment, no credentials, no `.`/`..` segments,
  no IP literals
- the host is resolved, **every** answer must be publicly routable, and the
  connection is pinned to the address that was checked (DNS rebinding)
- blocked alongside RFC 1918 and loopback: CGNAT, `169.254.169.254`, NAT64 and
  IPv4-in-IPv6
- no redirects, a 5-second limit, a 5 KB cap enforced while streaming, and the
  `Content-Type` must be JSON
- rate limited per `client_id` host, on cache misses only
- the document's `client_id` field must equal the fetched URL exactly
- `logo_uri` is ignored

**Redirect URIs** are matched exactly. The single exception is RFC 8252 §7.3:
for loopback addresses the port is ignored, because a native client cannot know
its port in advance. Everything else — scheme, host, path, query — must match,
and `http://localhost.attacker.example/callback` does not.

When every redirect URI a client declares is a loopback address, the consent
screen warns as well: a metadata document cannot stop another program on the
same machine from binding a port and claiming the real client's name.

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
| `oauth_registration_mode` | `restricted` | `restricted` (registration only while the pairing window is open) or `open` |
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

**Nothing to do.** `composer update netzhirsch/contao-mcp-bundle` goes through
even if your root `composer.json` still carries the former patch block. The
`patches/` files stay in the package until 2.0.0 for exactly that reason —
nothing applies them any more.

To clean up (recommended, not urgent): delete `extra.patches`,
`"cweagans/composer-patches"` from `require` and its `allow-plugins` entry, then
run `composer update`. The vendor stays patched afterwards — a shrinking patch
list does not make the plugin reinstall `php-mcp/server` on its own. That is
harmless, because `ContaoDispatcher` overrides the patched methods; for a
pristine vendor add `composer reinstall php-mcp/server`. Details:
[`patches/README.md`](patches/README.md).

## Maintenance

```bash
composer update netzhirsch/contao-mcp-bundle
```

**No vendor patches are applied.** What the bundle needs from the dispatcher
(the lazy-mode tool filter and the post-call cleanup) lives in
`Server\ContaoDispatcher`, a subclass. After a `php-mcp/server` major bump,
check there that `handleToolList()` and `handleToolCall()` still line up.

### When an update aborts with "no merge base"

This hits any instance where the bundle sits in vendor as a **git checkout** (a
"source install"). Two things lead there, and it is BOTH of them, not just the
first:

1. The constraint is a branch (`dev-master`) — Composer installs branches from
   source by default.
2. The root `composer.json` still carries a `repositories` entry of type `vcs`
   pointing at the GitHub repository. Without a GitHub token that provides NO
   dist archive, so Composer installs even a TAG from source.

The log tells you which you have: an archive reads `Downloading
netzhirsch/contao-mcp-bundle`, a source install reads `Syncing
netzhirsch/contao-mcp-bundle … into cache`.

Before every update Composer inspects the checkout for local changes with
`git diff --name-status origin/master...master`. That three-dot form needs a
common ancestor, and once the Composer cache has been rebuilt in between, the
vendor clone no longer shares one with its `origin`:

```
In GitDownloader.php line 236:
  Failed to execute git diff --name-status origin/master...master --
  fatal: origin/master...master: no merge base
```

Composer files this error under whichever package was being processed at the
time — observed as `php-mcp/server`. The branch in the command names the real
culprit: `php-mcp/server` lives on `main`, `master` is THIS bundle.

**With a shell** — throw the broken checkout away and let Composer fetch it again:

```bash
rm -rf vendor/netzhirsch/contao-mcp-bundle
composer install --no-dev --optimize-autoloader
```

**The Contao Manager alone cannot do it.** Neither updating nor removing the
package helps, because Composer inspects the checkout BEFORE doing anything to
it — in `VcsDownloader::prepare()`, for both cases:

```php
if ($type === 'update')         { $this->cleanChanges($prevPackage, $path, true); }
elseif ($type === 'uninstall')  { $this->cleanChanges($package, $path, false); }
```

`prepare()` runs before any per-package output, which is why `composer remove`
aborts without printing a single `- Removing …` line. So this needs FILE
ACCESS: FTP/SFTP, the hosting file manager, or SSH.

**Smallest intervention** (FTP/SFTP, hosting file manager): delete just the
`vendor/netzhirsch/contao-mcp-bundle/.git` folder — enable "show hidden files"
in the client. Composer recognises a git checkout solely by
`is_dir($path.'/.git')`; without it the check returns early and the next
operation in the Manager goes through. The code stays in place and the instance
keeps running meanwhile.

Alternatively delete the whole `vendor/netzhirsch/contao-mcp-bundle` directory
and add the package again in the Manager. The database (`tl_mcp_oauth_*`), the
license and `var/mcp/` are untouched, and the connector reconnects unchanged.

**Clearing the Composer cache does not help.** "no merge base" means both refs
resolved and share no history — the problem sits in the vendor clone, which
clearing the cache does not touch.

To avoid it altogether, install the bundle as an archive instead of a git
checkout — then `GitDownloader` is not involved at all:

```bash
composer config preferred-install.netzhirsch/contao-mcp-bundle dist
composer update netzhirsch/contao-mcp-bundle
```

**Remove the actual cause:** the bundle is on
[Packagist](https://packagist.org/packages/netzhirsch/contao-mcp-bundle), which
serves a zip for every tag. A `repositories` entry of type `vcs` pointing at
GitHub is therefore redundant — and as long as it is there it wins over
Packagist and forces the git checkout. Drop it from the root `composer.json`:

```jsonc
"repositories": [
    { "type": "vcs", "url": "git@github.com:Netzhirsch/contao-mcp-bundle.git" }  // ← remove
]
```

Then `composer update netzhirsch/contao-mcp-bundle`. A tag now arrives as an
archive and `GitDownloader` is out of the picture entirely.

| Command | Purpose | Suggested cadence |
|---|---|---|
| `contao:mcp:license status\|trial\|activate\|renew` | manage license and trial | as needed (renewal runs via cron) |
| `contao:mcp:oauth:cleanup` | purge expired auth codes, tokens and IATs | daily, as a cron job |
| `contao:mcp:oauth:rotate-keys` | rotate the OAuth RSA signing keys (dual-key, nobody is logged out) | monthly |
| `contao:mcp:permission-debug` | find out why a backend user may or may not use a tool | when troubleshooting |
| `contao:mcp:smoke-test` | end-to-end self-test of the tool layer | after updates or a server move |

The Contao cron must be running (`contao:cron` or the web cron) — automatic
license renewal depends on it.

### When Composer trips over `psr/http-message`

The message looks like this:

```
- php-mcp/server 3.3.0 requires react/http ^1.11 -> satisfiable by react/http[v1.11.0].
- react/http v1.11.0 requires psr/http-message ^1.0 -> found psr/http-message[1.0, 1.0.1, 1.1]
  but these were not loaded, likely because it conflicts with another require.
```

**As of version 1.9.0 this no longer happens**: the bundle does not pull in
`react/http` any more (see the CHANGELOG). On an installation running ≤ 1.8.x,
updating to `^1.9` is the fix.

If the message shows up anyway, the cause is always the same: some package in
the project requires `psr/http-message ^2.0` while another insists on `^1.0`.
The lock file names the culprit:

```bash
php -r '$l=json_decode(file_get_contents("composer.lock"),true); foreach($l["packages"] as $p){$c=$p["require"]["psr/http-message"]??null; if($c)printf("%-42s %s\n",$p["name"],$c);}'
```

Look for the line without a `^1.` in it — that is the blocker.

One trap while fixing it: `composer update <package> --with-all-dependencies`
does **not** help here. A partial update may only move dependencies *of the
listed packages*, and the blocker is usually a sibling, not a child. It has to
be named on the command line too, or `-W` changes nothing.

## Translating with DeepL

Needs [`numero2/contao-deepl`](https://github.com/numero2/contao-deepl) and a
DeepL API key. Both are configured **once**, where that bundle already expects
them:

```bash
composer require numero2/contao-deepl
```

```dotenv
DEEPL_API_KEY="…"
```

> The key is mandatory as soon as the bundle is installed: `numero2` sets
> `%env(DEEPL_API_KEY)%` with no fallback, so a missing value already breaks
> `cache:clear` with *"Environment variable not found"*.

Four tools then appear. With either piece missing they answer
`extension_not_available` or `deepl_not_configured` and name what is missing —
`deepl_status` answers that directly, along with the list of target languages.

| Tool | What it does |
|---|---|
| `deepl_status` | availability, target languages, optionally the account counter |
| `deepl_translate` | free text in, translation out — touches no record |
| `deepl_translate_records` | one or more records of a **single** table |
| `deepl_translate_page_tree` | a page plus meta, articles, content and every page below it |

**Translatable tables** are `tl_page`, `tl_article`, `tl_content`, `tl_news`,
`tl_news_archive`, `tl_calendar_events`, `tl_calendar`, `tl_faq`,
`tl_faq_category`, `tl_form`, `tl_form_field` and `tl_module` — in each case only
the columns that actually hold prose. Contao's structural values survive: a
headline keeps its `h2`, a list element its order, a table element its row
layout, and rich text goes out with DeepL's `tag_handling=html` so markup and
attributes stay intact.

### Three modes, two switches

Because "translate", "spend money" and "overwrite content" are three different
decisions:

- **`dry_run: true`** — plan only. No API call, no write, no cost. Answers with
  the records in scope, the fields, and the exact number of characters the real
  run would submit.
- **both `false`** (default) — translate and **return** the values. Nothing is
  written. Capped at 50 records, because every source and translation comes back.
- **`save: true`** — translate and write through the table's own `*_update`
  tool: Versions snapshot, `tl_log` entry, `changed_fields`, and a permission
  check per record, exactly as a direct update would.

On top of that, `max_characters` (default 100,000) refuses **before** the first
API call if the plan would cost more than allowed.

### What a call costs

Every answer carries what it spent:

```json
"usage": { "characters_submitted": 482, "characters_reused": 16, "api_requests": 2 }
```

`characters_submitted` is the number DeepL bills on — source characters actually
sent. Translations are cached for 30 days (our own cache, keyed on target
language, source language **and** tag handling), so the recommended sequence
*plan → look → save* is paid for once. The account counter from `deepl_status` is
a billing-period total that lags behind reality; it is **not** the price of your
last call.

### The usual route to a second-language tree

Translation happens **in place**: the record you name is the record that changes.
For a second language, copy first and translate the copy:

1. `entity_duplicate(table: "tl_page", id: 42, into_pid: <target root>, with_children: true, overrides: {"published": false})`
2. `deepl_translate_page_tree(id: <the copy>, target_lang: "EN-GB", dry_run: true)` — what will this cost?
3. the same call with `save: true`
4. `entity_language_link(...)` to wire it up with changelanguage

Step 1 copies into a tree that may already be live — hence `published: false`,
or the untranslated source stands publicly readable for as long as the
translation takes. The returned `tree` is the complete source→target id map you
need for step 2 anyway.

The same applies outside the page tree. `entity_duplicate` covers:

```
tl_page, tl_article, tl_content,
tl_module, tl_layout,
tl_news_archive, tl_news,
tl_calendar, tl_calendar_events,
tl_faq_category, tl_faq,
tl_form, tl_form_field
```

This is the route for anything you would otherwise retype column by column into
a `*_create` call — a `tl_module` row has between 114 and well over 250 columns
depending on the extensions installed. Copying a **collection** takes every
entry with it through the `ctable` cascade: `entity_duplicate(table:
"tl_news_archive", id: 1)` creates the archive along with its 95 entries and
their content elements. For a language rollout that is the point, but `copied`
reports the total — budget for it before the call.

Copying follows the backend's copy button: `doNotCopy` fields are not carried
over but refilled from the DCA `default` (so a copied news entry is dated today,
not 1970), the alias is regenerated from the right field (`headline` for news,
`question` for FAQs) and follows an `overrides` that renames the copy. Names and
titles are **not** made unique — that is what `overrides` is for.

`tl_user` and `tl_member` are deliberately absent: Contao's copy button lands
you in the edit mask there, so a human can make the username and e-mail unique
before anything is saved.

#### Both halves of a translation link

`terminal42/contao-changelanguage` records a translation in **two** places, and
without the second the first is never evaluated:

| Level | Column | Tables |
|---|---|---|
| Record | `languageMain` | `tl_page`, `tl_article`, `tl_news`, `tl_calendar_events`, `tl_faq` |
| Collection | `master` | `tl_news_archive`, `tl_calendar`, `tl_faq_category` |

With `master` missing, the language switcher falls back to the language root and
no `hreflang` alternate is emitted — visible only in the rendered page; the
database looks correct.

`entity_language_link` covers both levels and completes the collection half
itself where that is unambiguous and legal:

```
entity_language_link(table: "tl_news", default_id: 8, translations: {"en": 16})
→ linked: 1
  collections_linked: [{table: "tl_news_archive", id: 3, master: 1}]
  warnings: []
```

Where it cannot — the target archive is itself a translation, or another
collection on the same reader page already claims that master — `warnings` says
what is missing and which call sets it. Collections can also be linked directly:
`entity_language_link(table: "tl_news_archive", default_id: 1, translations: {"en": 3})`.

Root pages link through `languageRoot` rather than `languageMain` and are
refused here; `page_update` owns that one.

**Aliases are deliberately not translated.** DeepL returns prose, not a slug, and
"Our Services" does not belong in a URL. For translated URLs, translate the title
first and then send an **empty** alias to `page_update` — Contao regenerates it
from the new title through the Slug service.

## How tools report errors

A tool that cannot do what it was asked returns a structured result rather than
throwing — with `error`, a plain-language `message` and, where it helps, the
list of what is allowed. Two cases worth knowing:

**A field the record type does not have** is refused, naming the type:

```
Field "gibtsNicht" is not valid for content type "text".
Use content_palette_get("text") to see allowed fields. Currently allowed: pid, ptable, …
```

**A parameter the tool does not have** likewise — with a suggestion on a typo
(since 1.10.0; before that it was dropped in silence and the call reported
success while changing nothing):

```
Tool "page_update" has no parameter "pageTitel" (did you mean "pageTitle"?).
Nothing was changed. Allowed parameters: id, pid, title, type, sorting, …
```

Both apply to direct `tools/call` **and** to the lazy-mode `contao_call` proxy.

## Known limitations

As of `v1.8.2`:

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
