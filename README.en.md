# Contao MCP Bundle

[![CI](https://github.com/Netzhirsch/contao-mcp-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/Netzhirsch/contao-mcp-bundle/actions/workflows/ci.yml)

*🇩🇪 [Deutsche Fassung](README.md) — the German README is the reference version.*

**Status:** Stable — the current version and every change are in the [CHANGELOG](CHANGELOG.md)
**License:** proprietary, commercially licensed — 30-day free trial, then
€49/month per Contao installation (see [License & trial](#license--trial) and
[LICENSE](LICENSE))

A [Model Context Protocol](https://modelcontextprotocol.io/) server packaged as a
bundle for Contao 5.3 LTS, 5.7 LTS and 6.0. It connects Claude Desktop, Claude on the web, Claude Code, the
MCP Inspector or any other MCP-capable AI directly to the Contao backend — with
no REST endpoints of your own, no middleware and no extra port.

Instead of building a bespoke API endpoint for every AI task, the AI session gets
structured access to the whole DCA stack: editors can create content by
describing it, pipelines can populate pages from third-party systems, developers
can script structural migrations — all through the same **196 tools**, and all
constrained by exactly the same backend permissions that apply when a person
clicks through the backend.

**Supported entities:** pages, articles, content elements, news, calendars,
FAQs, members and member groups, backend users and groups (read-only), forms and
form fields, newsletters, comments, themes, layouts, modules, image sizes,
templates, files, URL rewrites, form leads (read-only), OpenGraph/X card data,
the search index, maintenance and system settings.

## What you get

- **196 tools** across Contao core entities plus popular extensions.
- **Lazy-mode discovery** (switched on with `lazy_mode`, off by default):
  `tools/list` shows only the three meta tools `contao_search_tools`,
  `contao_describe_tool` and `contao_call` plus `ping`, `contao_version` and
  `installed_bundles`; everything else stays callable through `contao_call`.
  Instead of about 180 KB of tool schemas, about 3 KB go to the client per turn.
- **OAuth 2.1** with PKCE, Client ID Metadata Documents (CIMD), Dynamic Client
  Registration (RFC 7591) and Protected Resource Metadata (RFC 9728). With CIMD
  Claude connects **without a registration step** — no pairing window, no open
  registration. Registration is still there for clients that
  want it: in the default `restricted` mode only while the 15-minute pairing
  window is open.
- **Permission parity**: every backend user's rights apply to the AI 1:1 —
  enforced through Contao's own voters, not reimplemented. Writing a field
  additionally requires its `alexf` right ("allowed fields" in the user group)
  wherever Contao requires it — since Contao 5 that is every field with an input
  unless its DCA opts out with `exclude => false`. A value that changes nothing
  (a default on create, the stored value on update) needs no right, just as in
  the backend. Neither does `rsce_data`: RSCE writes the column through virtual
  fields that never ask for a field right. Non-administrators additionally need
  the **"Allow MCP server access"** checkbox on the user or one of their groups
  (off by default); without it every call is refused with `mcp_access_denied`.
- **Full-text site search**: `search_query` queries Contao's own search index
  (`tl_search`), so it also finds text produced by modules, includes or
  extensions that the CRUD tools cannot see. Protected pages are always excluded,
  and a restricted user only gets hits from their own page mounts
  (`out_of_scope_skipped` counts the rest); `search_index_status` tells you
  whether the index was ever populated.
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
- **Optional bundles**: their tools are always registered and work once the
  package is installed — news, calendars, FAQs, newsletters and comments (Contao
  bundles that ship with the Managed Edition), `url_rewrite_*` (terminal42),
  read-only `leads_list` + `lead_get` for form submissions
  (`terminal42/contao-leads`), **OpenGraph & X cards**
  (`numero2/contao-opengraph3`, see below), **DeepL translation**
  (`numero2/contao-deepl`, see below) and the language link
  `entity_language_link` (`terminal42/contao-changelanguage`). Without their
  package they answer `extension_not_available`, naming the missing package —
  the generic tools too, when handed a table such as `tl_news`.
- **File uploads**, large ones included: `file_upload_begin`/`_chunk`/`_finish`
  move a file in pieces without it having to sit anywhere public first; size,
  `sha256` and magic bytes are checked before anything is written (see below).
- **Third-party text is marked**: read answers name the fields that hold visitor
  or editorial text under `_untrusted_fields`, so an agent does not mistake
  instructions in them for instructions.
- **A guide as an MCP prompt**, `contao_guide`, generated from the state of this
  installation: versions, tool count, lazy mode, installed and missing
  extensions.
- **Extensible**: other bundles can contribute their own tools (switched on one
  by one in the tool panel) and fields — see [EXTENDING.md](EXTENDING.md).
- **RockSolid Custom Elements**: RSCE elements (`rsce_*`) can be created **and**
  configured as content elements, frontend modules and form fields. `rsce_data`
  is checked against the
  `rsce_*_config.php`, merged into what is stored and saved the way the backend
  saves it (see below).
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
- **Backend module** "MCP server" with four areas: status (license and
  trial/subscription, update notice, pairing window, OAuth clients),
  configuration, activity log and the tool panel (every tool individually
  switchable except `contao_search_tools`, `contao_describe_tool`, `contao_call`
  and `ping`) — **administrators only**.
- **Tested on Linux and Windows** (Laragon for development, Debian in production).

## Installation

### 1. Composer

```bash
composer require netzhirsch/contao-mcp-bundle
```

That is all (on Contao 6 with `-W`, see [Contao 6](#contao-6)) — no
`repositories` entry, no patch block, no `allow-plugins`. The bundle is on [Packagist](https://packagist.org/packages/netzhirsch/contao-mcp-bundle).

Or search for "Contao MCP Bundle" in the **Contao Manager** and install it there.

### 2. Register the bundle

Handled by the Contao Manager Plugin — there is nothing to add to
`config/bundles.php`.

### 3. Schema migrations and initial config

```bash
vendor/bin/contao-console contao:migrate --env=prod
```

This creates the OAuth tables (`tl_mcp_oauth_*`) and adds the external-ID columns
to 24 entity tables. **The endpoint is closed out of the box.** Since 1.22.0 `auth_mode` defaults to
`oauth`; an instance whose configuration has never been saved answers `/mcp`
with **503** and names the backend module. That is where the mode is chosen —
`none` is still available, but only as a deliberate choice for a private or
loopback host.

The endpoint is there right after the migration, at `<backend_url>/mcp` (the
path can be changed, see [Configuration](#configuration)) — Apache/PHP-FPM
serves it like any other Symfony route. No daemon, no port, no reverse proxy. It
answers once the configuration has been saved and a license or trial is
active.

### 4. Activate the license (30 days free)

Without an active license every tool answers `license_inactive` — Contao itself
keeps running normally. In the backend under **MCP server → Status**, click
**"Start trial"**: 30 days, no payment details. See
[License & trial](#license--trial).

### 5. Connect a client

Guides in this repository: [docs/installation.md](docs/installation.md)
(connecting a client, online and locally) and
[docs/dokumentation.md](docs/dokumentation.md) (complete feature reference).
Both are written in German.

> **Connecting a client with `oauth_registration_mode: restricted` (the default):**
> Claude connectors need nothing opened while CIMD is on (the default, see
> [Connecting without pairing: CIMD](#connecting-without-pairing-cimd)). For
> clients that sign up through Dynamic Client Registration — `mcp-remote` or the
> MCP Inspector, say — click **MCP server → Status → "Open registration for 15
> minutes"** in the backend. The window stays open for the full 15 minutes,
> however many attempts that takes (up to 1.4.0 it closed after the first
> successful registration, which is why retries and second clients failed).
> Refused attempts are listed with reason and IP under **MCP server → Activity**.

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

**Ordering happens in the backend** — everything sits under **MCP server →
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

**What the renewal reports.** The product, the domain, the current license
token, a random instance secret as proof of ownership — and, since 1.26.0, three
version values: **bundle, Contao and PHP version.** Nothing else. No content, no user
data, no page or usage counts, no list of installed extensions; the server does
not accept such fields either. The versions answer two questions that otherwise
accompany every support case: which version a customer runs, and whether a
compatibility branch for old bundles is still needed.

**What comes back.** Besides the token, since 1.27.0 an optional pointer to a
newer release: the version number, a link to the release notes, and whether it
is a **security release**. It then shows in the backend under MCP server →
Status and in `contao:mcp:license status`. It informs and nothing else: it
updates nothing by itself, blocks nothing, and on a development installation
(`dev-master`) it does not appear at all. Whether a version is announced is
decided by hand at Netzhirsch — a new tag is not automatically an announcement.

**Renewal is automatic.** The `LicenseRenewalCron` job (hourly, throttled)
refreshes the token, while verification itself is **offline** (Ed25519). An
outage of the license server therefore locks nobody out — and there are 3 days of
grace after expiry on top. A running Contao cron is the prerequisite.

> The license server is `https://license.netzhirsch.de`, baked into the bundle —
> **nothing to configure**. Transmitted are the domain, the product id, the
> license token, an installation secret, the three version values and — only
> when a trial or a subscription is started — the e-mail address of the backend
> user doing so, or the one passed to `contao:mcp:license trial`. No content, no editorial data, no
> visitor data, no telemetry.

## Requirements

- **PHP** `^8.1` with the extensions `openssl`, **`sodium`**, `pdo_mysql`,
  `mbstring`, `intl`. `sodium` is mandatory for license verification — without it
  every tool stays locked. The 8.1 floor covers Contao 5.3 LTS; 5.7 needs PHP
  ≥ 8.3, 6.0 PHP ≥ 8.4. CI runs the smoke test on PHP 8.1 (Contao 5.3), 8.3 and
  8.4 (Contao 5.7) and 8.4 (Contao 6.0).
- **Contao** 5.3 LTS, 5.7 LTS and 6.0 — exactly the lines CI tests. 5.4, 5.5 and
  5.6 are end of life at Contao and not supported; an installation on them stays
  on version 1.37.1 of the bundle until Contao is updated. Contao 4.13 is not
  supported.
- **Symfony** 6.4 (Contao 5.3), 7.4 (Contao 5.7 and 6.0) or 8.x (Contao 6.0)
- **MySQL** ≥ 8.0 or MariaDB ≥ 10.6 (strict mode supported)
- **Storage for `var/mcp/`**: writable, nothing more. Since 1.9.1 the bundle
  writes its state files atomically via `rename()` and needs **no working file
  locking** — NFS mounts without `lockd`/`statd` are fine. On ≤ 1.9.0 `flock()`
  could block there indefinitely and cause a gateway timeout (see CHANGELOG
  1.9.1).
- **HTTPS** in production — required in practice by OAuth 2.1.

Shared hosting is fine: the bundle is HTTP-only, needs no daemon, no open port
and no shell access (install through the Contao Manager in that case).

### Contao 6

Runs unchanged — on Symfony 8 since 1.37.1; before that, `/mcp`, `/mcp/healthz`
and the `.well-known` metadata answered 404 there, and the backend pages under
"MCP server" failed with error 500. Two things to know when installing:

**Contao 6 requires PHP ≥ 8.4.** The bundle's own floor stays at 8.1 so Contao
5.3 instances keep working — on PHP 8.1 and 8.2 only Contao 5.3 installs.

**The install needs `-W`:**

```bash
composer require netzhirsch/contao-mcp-bundle -W
```

The reason is not this bundle but `php-mcp/server`: it pins
`phpdocumentor/reflection-docblock` to `^5.6` and `symfony/finder` to `^6.4 || ^7.2`,
while a Contao 6 app resolves both higher. `-W` lets Composer move them back
down, which both packages tolerate; without it the resolution fails. On Contao 5
the flag is unnecessary.

## Transport and protocol

Streamable HTTP on a single endpoint, `POST /mcp` (or the configured path).
There is **no SSE channel** —
no GET stream and no server-initiated messages, just request/response JSON.
Protocol revision **2025-03-26**; clients speaking **2024-11-05** are accepted as
well. Any MCP client that speaks Streamable HTTP with OAuth should work; Claude
is what we test against. A JSON-RPC batch takes at most 50 calls.

## Smoke test

```bash
vendor/bin/contao-console contao:mcp:smoke-test --env=dev
```

Runs about 500 checks against the tool layer (CRUD on members/groups/forms/
newsletters/comments/themes/layouts/templates/maintenance, the content tree,
permission parity, external IDs, audit regressions, key rotation, rate limiting,
the MCP activity log), creates its own test data and removes it again at the
end. It should pass.

If one of the optional Contao bundles is missing (news, calendar, FAQ,
comments, newsletter), it skips the checks that need it instead of aborting —
each on its own ⊝ line. The summary counts them apart from the passes
(`17 section(s) skipped — not installed: …`): not a failure, but a sign that
less was checked than on a full installation.

For a few checks the test switches `var/mcp/config.json` over briefly —
`auth_mode=none` and an open pairing window among them — and always restores the
file at the end, also when a section aborts. For those seconds the values do
apply, though, so run it on staging or an instance that is not publicly
reachable. It does not check the backend pages or HTTP routing — it calls the
tools in-process; CI covers that.

On a **fresh installation** (no root page, no administrator or no file) it
seeds the missing fixtures for the duration of the run: a page tree with an
article (with the news bundle, a news archive as well), an administrator with a
random password that is never shown, and a file. It removes them again
afterwards, also when a section aborts, so CI runs the same sections as a
maintained installation. `--keep` leaves the fixtures in place as well.

## Local development & HTTPS

The bundle does **not** terminate TLS — HTTPS comes from the web server in front
of it (Laragon locally, Plesk/Let's Encrypt or similar in production). The OAuth
endpoints it advertises are built from the configured `backend_url`, not from
the request scheme, which keeps it robust behind a reverse proxy.

For local MCP tests `backend_url: "http://localhost"` is usually enough
(loopback is exempt from the redirect-URI rules and from the HTTPS warning) — no
certificate needed. Real local HTTPS (`https://<host>.test`), including the
Node/CA trap with MCP clients: see
**[docs/lokales-https.md](docs/lokales-https.md)** (in German).

## Connecting without pairing: CIMD

Since 1.11.0 a client may identify itself with an HTTPS URL instead of
registering — the server reads the client's details from that URL
([Client ID Metadata Document](https://datatracker.ietf.org/doc/html/draft-ietf-oauth-client-id-metadata-document-00)).
For the customer that means **no pairing window to open**. The instance still
needs a saved configuration with `backend_url` and an active license. Claude
picks this route by itself when the instance advertises it.

Switchable in the backend under **MCP server → Configuration**:

| Mode | Meaning |
|---|---|
| `trusted` *(default)* | only the hosts in `cimd_trusted_hosts` (default: `claude.ai`, `claude.com`) and their subdomains; the list lives in `var/mcp/config.json` only |
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
- rate limited per `client_id` host (30 per hour) and overall (120 per hour), on
  cache misses only
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

File: `var/mcp/config.json` (mode `0600`). It is written the **first time
settings are saved** in the backend — configuration, tool panel or pairing
button; until then `/mcp` answers 503. The bundle options further down are the
only YAML and environment settings.

> The four **MCP-Server backend modules are restricted to administrators** — they
> switch `auth_mode` (and with it the entire permission check), hand out OAuth
> registrations, revoke clients and start paid subscriptions. A non-admin gets
> "access denied" even with the module right granted.

| Key | Default | Meaning |
|---|---|---|
| `path` | `mcp` | URL path of the endpoint, no leading slash: `ki/mcp` gives `<backend_url>/ki/mcp`. `/healthz` and the OAuth metadata move with it, on save, without `cache:clear`; connected clients then need the new URL, their tokens stay valid. Lower-case letters, digits, `-` and `_`, several segments allowed — not inside the backend, not in a directory of `public/` and not the alias of a page the endpoint would otherwise hide |
| `pagination_limit` | `500` | Max tools per `tools/list` (irrelevant in lazy mode) |
| `auth_mode` | `oauth` | `oauth`, or `none` for a private or loopback host only |
| `backend_url` | `""` | Public base URL of the Contao backend (required for OAuth) |
| `oauth_registration_mode` | `restricted` | `restricted` (registration only while the pairing window is open) or `open` |
| `cimd_mode` | `trusted` | `trusted`, `open` or `off` (see [CIMD](#connecting-without-pairing-cimd)) |
| `cimd_trusted_hosts` | `["claude.ai", "claude.com"]` | Hosts (and their subdomains) trusted in `trusted` mode; no form field, file only |
| `lazy_mode` | `false` | When `true`, `tools/list` shows only the three meta tools plus `ping`, `contao_version`, `installed_bundles` |
| `disabled_tools` | `[]` | Tools switched off in the tool panel |
| `extension_tools_enabled` | `[]` | Tools of other bundles switched on, also in the tool panel ([EXTENDING.md](EXTENDING.md)) |
| `registration_open_until` | `0` | End of the pairing window (Unix time), set by the button on the status page |
| `license_server_url` | `""` | Development only: replaces the built-in license server |

Bundle configuration in `config/config.yaml` — the Contao Managed Edition does
not read `config/packages/`:

```yaml
contao_mcp:
    write:
        # Author for write tools when no signed-in user is known
        # (auth_mode=none); empty = the administrator with the lowest id
        default_author_id: 1
    preview:
        # Only needed when the instance sits behind HTTP basic auth.
        # Defaults to the environment variable; without it nothing changes.
        basic_auth: '%env(default::MCP_PREVIEW_BASIC_AUTH)%'
```

`page_preview` fetches the page over its **public** URL. If basic auth sits in
front of it (typical on staging), the web server answers 401 before Contao even
runs. Then, in the instance's `.env.local`:

```dotenv
MCP_PREVIEW_BASIC_AUTH="user:pass"
```

The tool points this out itself on a 401/403. The credentials live only in
`.env.local`, never in an answer or a log.

## Uploading files

For small files `file_upload` with `content_base64` is enough. Above roughly
50 KB that breaks: the MCP transport truncates long base64 strings. The only way
out used to be `source_url` — the server fetches the file itself — which needs
the file on a publicly reachable host. For something a client has only just
produced, that is no option.

That is what the chunked route is for:

```
file_upload_begin(parent_path, name, total_size_bytes, overwrite?, meta?, sha256?)
  → { upload_id, chunk_size_recommended, next_sequence }

file_upload_chunk(upload_id, sequence, content_base64)   ← repeatedly, in order
  → { received_bytes, remaining_bytes, next_sequence, complete }

file_upload_finish(upload_id)
  → like file_upload

file_upload_abort(upload_id)
```

**When splitting:** slice the **raw bytes** and base64-encode every slice on its
own. Do not encode the whole file and then cut the base64 string.

What is checked when:

| When | Check |
|---|---|
| `begin` | target folder, file name, extension against `tl_settings.uploadTypes`, announced size against `maxFileSize`, `meta`, existing file (without `overwrite`) |
| every `chunk` | order (no gaps, each exactly once), running total ≤ announced size |
| `finish` | total matches, `sha256` matches, **magic bytes against the extension**, active markup, overwrite |

The content check sits at the end on purpose: a single chunk says nothing about
the file it ends up in. A `.png` that is really HTML is therefore only caught at
the end — but it is caught, and nothing is written.

The buffer lives in `var/mcp/uploads/<id>/`, directory `0700`, files `0600`,
outside the web root. A session belongs to the backend user who opened it and
expires after an hour; the next `begin` removes expired leftovers. No cron job
is needed for that.

When `finish` cannot store the file — it exists by now, the content does not
match the extension — **the session stays**, so nothing has to be sent again.
When the target folder has disappeared or the total or `sha256` do not match, it
is discarded; start again with `file_upload_begin`.

## OpenGraph & X cards

Needs [`numero2/contao-opengraph3`](https://github.com/numero2/contao-opengraph3)
(v5 or later, Contao 5.7 and 6.0 only — there is no matching release for 5.3).
Without the extension the tools answer `extension_not_available`.

```bash
composer require numero2/contao-opengraph3
vendor/bin/contao-console contao:migrate
```

The extension adds its fields to `tl_page`, `tl_news`, `tl_calendar_events` and
`tl_faq`. The catch: of its roughly 60 fields only nine are real columns — **all
the others live in the `og_properties` column**, a serialised list of
`[field name, value]` pairs. Which of them are valid depends on the chosen
`og_type`, and the backend widget **silently drops** every property that does
not fit the type the next time the record is saved.

Hence three tools:

| Tool | Purpose |
|---|---|
| `opengraph_get(table, id)` | columns **and** properties as *one* flat map, plus `allowed_types`, `valid_properties` and `stale_properties` |
| `opengraph_set(table, id, fields, dry_run)` | writes the same flat map back; splits it between column and blob itself |
| `opengraph_types(table)` | which `og_type` values the table allows and which properties each one unlocks |

`stale_properties` shows damage that is already there: properties that are
stored but do not belong to the current `og_type` — they disappear on the next
backend save.

A property that does not fit is **refused rather than written**, naming the type
that would allow it:

> og_type "article" does not keep "og_product_brand" (needs og_type "product").
> This is refused rather than written because the Backend widget discards
> properties outside the current type the next time the record is saved …

Type and properties can be set in **one** call — the type from that call then
decides:

```json
{ "table": "tl_news", "id": 12, "fields": {
    "og_type": "article",
    "og_title": "New hall opened",
    "og_description": "Short version for social networks",
    "og_article_author": "Editorial team",
    "og_image": "files/og/hall.jpg"
}}
```

Tables can restrict the type: `tl_news` accepts only `article`,
`tl_calendar_events` only `website`. `og_image` and `twitter_image` take a hex
UUID, a UUID with dashes **or** a file path.

The fields are also reachable the generic way: `page_get`, `news_get`,
`calendar_event_get` and `faq_get` return them, and the matching
`*_create`/`*_update` tools write them through `extras: {...}` — with the same
type check, because all of them go through the same field provider.

## Health check before a production deploy

```jsonc
// MCP call
{"tool": "system_health_check"}
```

Returns a structured report on the PHP setup, `var/mcp/` permissions and the
OAuth configuration, plus `warnings: [...]` with concrete fix commands. Worth
running before every site move or server change; the tool needs an
administrator.

For monitoring without a token there is `GET /mcp/healthz` (with another
`path`: `/<path>/healthz`): 200 when the
database answers, `var/mcp/` is writable, the OAuth keys are present (in `oauth`
mode) and at least 50 MB are free — otherwise 503 naming the checks that failed.

## Rate limiting

- `/mcp`: 600 requests per minute per OAuth client (sliding window). Every POST
  counts, `tools/list` and `initialize` included, and each call in a batch
  counts on its own (at most 50 per batch); over the limit the answer is 429
  with `Retry-After`. No limit with `auth_mode: none`.
- Per IP: `/_mcp_oauth/register` 10 per hour, `/_mcp_oauth/token` 60 per minute,
  `/_mcp_oauth/authorize` 30 per minute.
- CIMD document fetches: 30 per hour per `client_id` host, 120 per hour overall.

## Backup

The bundle persists five separate surfaces. A complete restore needs all five —
otherwise OAuth tokens become invalid (keys gone), the license cannot be renewed
(license file gone) or tool calls can no longer resolve external references
(external IDs gone).

| Surface | Path | Restore behaviour |
|---|---|---|
| OAuth RSA keys + encryption key | `var/mcp/oauth/*.pem`, `var/mcp/oauth/encryption.key` | Mandatory. Missing → all refresh tokens invalid, all access tokens must be reissued. Private keys and `encryption.key` belong at `0600` (`public.pem` may be `0644`); `system_health_check` reports deviations. |
| License | `var/mcp/license.json` | Mandatory. Token and instance secret, mode `0600`. Missing → tools locked, and activating the same domain again fails with `instance_mismatch` until Netzhirsch releases the binding. |
| Bundle config | `var/mcp/config.json` | Mandatory. Missing → `/mcp` answers 503 until the configuration is saved again; `backend_url`, tool selection, CIMD and lazy mode have to be set again. |
| OAuth tables | `tl_mcp_oauth_client`, `tl_mcp_oauth_access_token`, `tl_mcp_oauth_refresh_token`, `tl_mcp_oauth_authcode`, `tl_mcp_oauth_iat` | Mandatory for a seamless migration. Missing → clients must register again (DCR); CIMD clients such as Claude just sign in again. |
| External-ID columns | `external_id_namespace` + `external_id_key` on 24 entity tables | Mandatory for integrations. Missing → updates have to go through Contao primary keys instead of external references. |

Recommended: `tar -p` over `var/mcp/` (without `uploads/`), a mysqldump of the five `tl_mcp_oauth_*`
tables, and a dump of the full Contao schema (the external-ID columns live on the
entity tables, so they cannot be backed up separately).

## Updating from 1.4.0 or older

**Nothing to do.** `composer update netzhirsch/contao-mcp-bundle` goes through
even if your root `composer.json` still carries the former patch block. The
`patches/` files stay in the package until 2.0.0 for exactly that reason. Where
the old block is still in place, `cweagans/composer-patches` keeps applying
them — to no effect, because `ContaoDispatcher` overrides the patched methods.

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

The Contao Manager and `composer update` clear the cache while doing so. If
you deploy without the Composer scripts, run `vendor/bin/contao-console
cache:clear` afterwards — Contao caches the template hierarchy, and after the
update to 1.37.1 the backend pages under "MCP server" otherwise answered with
error 500. Overrides of the former `be_mcp_*.html5` templates no longer apply on
Contao 6; the change belongs in `be_mcp_*.html.twig`.

**The bundle needs no vendor patches.** What it needs from the dispatcher
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

### Console commands

| Command | Purpose | Suggested cadence |
|---|---|---|
| `contao:mcp:license status\|trial\|activate\|renew` | manage license and trial | as needed (renewal runs via cron) |
| `contao:mcp:oauth:cleanup` | deletes expired (older than 24 h) and all revoked auth codes and refresh tokens, plus expired access tokens and IATs | daily, as a system cron job of its own |
| `contao:mcp:oauth:rotate-keys` | rotates the RSA signing keys once they are older than 90 days (`--max-age`; `--force` right away) and drops the previous pair after 30 days (`--prune-old`) — dual-key, nobody is logged out | monthly, as a system cron job of its own |
| `contao:mcp:permission-debug` | find out why a backend user may or may not use a tool | when troubleshooting |
| `contao:mcp:smoke-test` | end-to-end self-test of the tool layer; skips what missing optional bundles would need and briefly switches `config.json` over for a few checks (see [Smoke test](#smoke-test)) | after updates or a server move, on staging |

The Contao cron must be running (`contao:cron` or the web cron) — automatic
license renewal (hourly) depends on it. Cleanup and key rotation do **not** run
through it; they need a cron entry of their own.

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

> In `numero2/contao-deepl` 1.2.0, `DEEPL_API_KEY` has an empty default: without
> a key the installation keeps running and the `deepl_*` tools answer
> `deepl_not_configured`. Older releases set the variable without a fallback —
> there a missing value already breaks `cache:clear` with *"Environment variable
> not found"*.

Four tools then appear. With either piece missing they answer
`extension_not_available` or `deepl_not_configured` and name what is missing —
`deepl_status` answers that directly, along with the list of target languages.

| Tool | What it does |
|---|---|
| `deepl_status` | availability, target languages, glossary setup, optionally the account counter |
| `deepl_translate` | free text in, translation out — touches no record |
| `deepl_translate_records` | one or more records of a **single** table |
| `deepl_translate_page_tree` | a page plus meta, articles, content and every page below it |

### Glossaries

From `numero2/contao-deepl` **1.2.0** on, DeepL glossaries can be configured —
and they apply **over MCP too**. Until then they only applied to the backend
button: whoever translated through the tools did not get their own terminology.
Configuration still happens in one place only:

```yaml
contao:
    deepl:
        source_lang: de
        glossaries:
            de-en: "a1b2c3d4-…"   # glossary id from the DeepL web interface
```

The rules for the pair are the backend's, deliberately down to the detail:
regional variants drop out (`en-US` and `en-GB` both use the `de-en` glossary),
the pair is compared case-insensitively, and a pair of one and the same language
gets no glossary. One difference remains: the backend button derives the source
language from the site's fallback language, MCP only takes it from the call
(`source_lang`) or the configuration.

**No source language, no glossary.** DeepL needs the pair; with `source_lang`
empty and none given in the call, the translation runs without a glossary.
`deepl_status` reports exactly that under `glossary` — including the case
"glossaries configured, but `source_lang` empty", which otherwise looks like a
working setup.

The translation cache tells glossary translations apart from ones without. A
glossary configured later therefore gives new results at once instead of
replaying old ones from the cache.

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

On top of that, `max_characters` (default 250,000, `0` switches it off) refuses
**before** the first API call if the plan would cost more than allowed. A call
takes at most 1,000 records.

### What a call costs

Every answer carries what it spent:

```json
"usage": { "characters_submitted": 482, "characters_reused": 16, "api_requests": 2 }
```

`characters_submitted` is the number DeepL bills on — source characters actually
sent. Translations are cached for 30 days (our own cache, keyed on target
language, source language, tag handling **and** glossary), so the recommended sequence
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

`overrides` is not a raw path around the checks. `id`, `pid` and `ptable` are
refused, because the parent is `into_pid`/`into_ptable` and that is where it is
checked. The account's field permissions apply as they do on the `*_update`
tools. On `tl_content`, `tl_module` and `tl_form_field`, overrides take exactly
the fields the table's `*_update` tool takes for the copy's type, on `tl_content`
also for its **new** parent, and `rsce_data` is merged into the source's
settings. That is how a prepared RSCE element becomes a copy with a
different button URL.

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

## RockSolid Custom Elements (RSCE)

An RSCE element keeps its whole configuration (grid, button URL, background) in
**one** JSON column, `rsce_data`. RSCE registers every element as a content
element, a frontend module and a form field unless its config restricts `types`,
and keeps the column in `tl_content`, `tl_module` and `tl_form_field`. It only
builds the palette and the fields in the edit mask. With
`madeyourday/contao-rocksolid-custom-elements` installed, `content_create`,
`content_update`, `content_create_tree`, `module_create`, `module_update`,
`form_field_create`, `form_field_update` and the overrides of `entity_duplicate`
still write that column on every `rsce_*` type:

```
content_update(id: 812, fields: {"rsce_data": {"buttonUrl": "{{link_url::12}}", "bgColor": null}})
module_create(theme_id: 1, type: "rsce_teaser", name: "Home teaser", fields: {"rsce_data": {"grid": "grid3Col"}})
```

- **Merged, not replaced.** A key you send replaces that key, `null` removes
  one, and everything you leave out stays. A list (`inputType: list`) is replaced
  as a whole.
- **Checked.** A key the type does not have according to its `rsce_*_config.php`
  is refused, and the message names the ones it has. So is a value a select,
  radio or checkbox field with fixed options does not offer, as in the backend.
  What is already stored passes even when the config no longer knows it.
  Options from an `options_callback` or `foreignKey` only exist at edit time and
  are not checked.
- **Stored the way the backend stores it.** Lists of values are serialised,
  files become UUIDs (the hex form `content_get` prints is converted),
  `true`/`false` becomes `"1"`/`""`, and date fields become timestamps (ISO 8601
  is converted).

For an `rsce_*` type, `content_palette_get`, `module_palette_get` and
`form_field_palette_get` list every key under `rsce_data`, with input type,
options and the expected value format. `fields` holds the regular columns the
type's edit mask shows in that table: `headline`, image and `customTpl` on a
content element, name, `headline` and `customTpl` on a module, `text`, CSS class
and `customTpl` on a form field. RSCE reads the config itself, so theme folders
and Twig templates resolve as they do in the backend.

## How tools report errors

A tool that cannot do what it was asked returns a structured result rather than
throwing — with `error`, a plain-language `message` and, where it helps, the
list of what is allowed. Four cases worth knowing:

**A field the record type does not have** is refused, naming the type:

```
Field "gibtsNicht" is not valid for content type "text".
Use content_palette_get("text") to see allowed fields. Currently allowed: pid, ptable, …
```

**A field that comes from the parent element** only applies there. Contao adds
`sectionHeadline` (the title of an accordion section) only to the palette of
elements **inside** an accordion. `content_palette_get` lists such fields under
`context_fields`, and anywhere else the refusal says where the field applies:

```
Field "sectionHeadline" only exists on an element inside an element of type "accordion" — …
```

**A parameter the tool does not have** likewise — with a suggestion on a typo
(since 1.10.0; before that it was dropped in silence and the call reported
success while changing nothing):

```
Tool "page_update" has no parameter "pageTitel" (did you mean "pageTitle"?).
Nothing was changed. Allowed parameters: id, pid, title, type, sorting, …
```

**An error the tool cannot explain** — usually from the database — comes
without the raw message (since 1.32.0): the same `error` code (`save_failed`,
say), a `message` with `reference <hex>` and, where there is one, the `sqlstate`
(`23000` constraint, `22007` value does not fit the column). The details are in
the Contao log under that reference.

All of this applies to direct `tools/call` **and** to the lazy-mode
`contao_call` proxy.

## Known limitations

- **An MCP token with the `tpl_editor` right is a token for running code.** A
  `.html5` template is plain PHP that Contao executes when it renders, so
  whoever may write templates may replace `fe_page.html5`. This is not a gap in
  the bundle — the same is true in the backend for the same user. What differs
  is reach: a backend user clicks themselves, an agent can be talked into it by
  text it read somewhere. **Grant `tpl_editor` only to users whose token you
  would also hand out for a deployment.** The same goes for the layout fields
  `head`, `script` and `onload`, which are rendered verbatim into every page of
  that layout. On Contao 6, which no longer renders `.html5` templates,
  `tpl_editor` is still a right to run code: Twig templates run without a
  sandbox there and can call PHP functions.
- **Test coverage:** PHPUnit covers what can be tested without a database; the
  smoke test exercises the tool layer end-to-end, in CI against Contao 5.3, 5.7
  and 6.0 (also without the optional bundles). A separate CI step checks the
  backend pages, saving the configuration and the endpoint over HTTP.
- **Refresh-token replay detection** (since 1.30.0) works only while the rotated
  row exists. `contao:mcp:oauth:cleanup` deletes revoked refresh tokens right
  away; a token presented after that is refused, but no longer ends the whole
  session.
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
- relevant entries from the Contao log — `var/logs/prod-<date>.log` on Contao 5,
  `var/log/prod-<date>.log` on Contao 6; for a message with `reference …`, the
  line with that reference

## Support

<netzhirsch@netzhirsch.de>, response within 24 hours, included in the
subscription. Paid setup assistance is planned as a separate, bookable service.

## Development

```bash
composer verify
```

Runs PHPStan and PHPUnit — the fast part of CI. CI also validates
`composer.json` and the PHP syntax, runs `composer audit`, the smoke test
against Contao 5.3, 5.7 and 6.0 (also without the optional bundles) with an HTTP
check of the backend modules and the endpoint, an update from the latest release
and an install with the old patch block. Once per clone, run `composer
setup-hooks`: a `pre-push` hook will then refuse any push PHPStan or PHPUnit
fails on (`git push --no-verify` bypasses it in an emergency).

The **smoke test** (see [Smoke test](#smoke-test)) needs a running Contao with a
database and is therefore not part of `composer verify`. CI runs it on every
run; locally it still belongs before every release tag:

```bash
vendor/bin/contao-console contao:mcp:smoke-test --env=dev
```

Release order: `composer verify` → smoke test → commit → push → **wait for CI to
go green** → only then tag.

Tag locally (`git tag -a vX.Y.Z -m "vX.Y.Z: …"`) or with the **Tag** workflow
(Actions → Tag → Run workflow: version, summary, optionally the commit). It sets
the annotated tag and nothing else, no GitHub release, and refuses when the
commit is not on master, the tag exists already or CHANGELOG.md at that commit
has no section for the version.

---
*Maintainer: Jan-Philipp Kalus &lt;kalus@netzhirsch.de&gt; — Netzhirsch*
