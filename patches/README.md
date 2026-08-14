# Vendor patches

The MCP server needs two small changes to `php-mcp/server` that are not yet
available upstream. They are applied automatically via
[`cweagans/composer-patches`](https://github.com/cweagans/composer-patches) on
`composer install` / `composer update`.

## Patches

| File | What it does | Affected file | Approx. lines |
|---|---|---|---|
| `transport-auth-and-oauth-metadata.patch` | Adds `setAuthValidator(\Closure)` and `setOAuthMetadata(array)` to the HTTP transport, plus the matching handling inside the request handler (HTTP 401 on rejected token, `/.well-known/oauth-authorization-server` discovery). | `src/Transports/StreamableHttpServerTransport.php` | +30 |
| `dispatcher-tool-filter.patch` | Lets the Dispatcher consult an optional `netzhirsch.mcp.tool_filter` service to filter `tools/list` (Lazy-Mode). Tools that don't pass the filter remain reachable via `tools/call`. | `src/Dispatcher.php` | +17 |

## How they get applied

`cweagans/composer-patches` reads patch declarations from the **root**
`composer.json` only (it ignores them from dependencies for security reasons).

The bundle's `composer.json` lists the patches too, but mostly as
documentation — Composer doesn't act on those. The host project's
`composer.json` MUST mirror the snippet below:

```jsonc
{
  "require": {
    "cweagans/composer-patches": "^1.7"
  },
  "config": {
    "allow-plugins": {
      "cweagans/composer-patches": true
    }
  },
  "extra": {
    "patches": {
      "php-mcp/server": {
        "Pluggable Bearer-auth + OAuth Authorization Server Metadata hooks":
          "vendor/netzhirsch/contao-mcp-bundle/patches/transport-auth-and-oauth-metadata.patch",
        "Optional tool-filter for tools/list (Lazy-Mode)":
          "vendor/netzhirsch/contao-mcp-bundle/patches/dispatcher-tool-filter.patch"
      }
    }
  }
}
```

## Smoke-test a patch round-trip

```bash
composer reinstall php-mcp/server  # drops the patched files, re-applies patches
vendor/bin/contao-console contao:mcp:smoke-test  # 162 asserts must stay green
```

## When a patch fails to apply

Most likely cause: `php-mcp/server` got a new release and the line numbers in
the `@@ -120,6 +120,16 @@` hunk-headers no longer line up. To regenerate the
patch against a newer version:

```bash
# 1. fetch the upstream original at the version Composer locked
curl -sSfL -o /tmp/Dispatcher.php \
  https://raw.githubusercontent.com/php-mcp/server/<commit-from-composer.lock>/src/Dispatcher.php

# 2. diff against the locally patched vendor file (your branch with the fix)
diff -u /tmp/Dispatcher.php vendor/php-mcp/server/src/Dispatcher.php \
  > patches/dispatcher-tool-filter.patch

# 3. rewrite the headers so cweagans/composer-patches (default -p1) finds the file:
#    --- a/src/Dispatcher.php
#    +++ b/src/Dispatcher.php

# 4. verify with a dry-run before committing:
patch -p1 --dry-run < patches/dispatcher-tool-filter.patch
```

## Upstream tracking (last checked 2026-05-26 against `php-mcp/server` 3.3.0 and `main`)

| Patch | Need in upstream | What happens after php-mcp/server `3.4.0` |
|---|---|---|
| `transport-auth-and-oauth-metadata` | **Becomes obsolete** when `3.4.0` lands — that release adds a `middlewares: []` constructor argument to `StreamableHttpServerTransport` (PR #59, already merged on `main`). | **Delete the patch** and refactor the Bundle to pass two middlewares (`authMiddleware`, `wellKnownOauthMiddleware`) when constructing the transport. |
| `dispatcher-tool-filter` | **Bug fix / feature** — Claude doesn't paginate `tools/list` so 150+ tools bloat the system prompt. | Still needed — `main` has not added a filter API. But `handleRequest` now takes a `Context $context` (new class), so regenerate the patch against 3.4.0. |

**Upstream issues to watch**:
- [php-mcp/server#78](https://github.com/php-mcp/server/issues/78) — "Request: new release (3.4.0)". When this closes / `3.4.0` is tagged, run the migration steps below.
- [php-mcp/server#59](https://github.com/php-mcp/server/pull/59) (already merged) — adds PSR-7 middleware support.

### Migration checklist for `3.4.0`

1. `composer require php-mcp/server:^3.4`
2. **transport-auth-and-oauth-metadata → middleware refactor**: in
   `HttpDispatcherFactory`, build middlewares for auth +
   `/.well-known/oauth-authorization-server` and pass them via the new
   constructor:
   ```php
   $transport = new StreamableHttpServerTransport(
       host: …, port: …, mcpPath: …,
       middlewares: [$authMiddleware, $wellKnownMiddleware],
   );
   ```
   Delete `transport-auth-and-oauth-metadata.patch` + the
   `setAuthValidator` / `setOAuthMetadata` calls.
3. **dispatcher-tool-filter regenerate**: `handleRequest` signature changed
   (`SessionInterface $session` → `Context $context`). The `handleToolList`
   body itself is untouched, but the hunk-header line numbers might shift.
   Re-diff.
4. Run `composer install` to verify patches still apply cleanly.
5. `vendor/bin/contao-console contao:mcp:smoke-test` must stay 162/162 green.

## Removed in v0.3.0 (no daemon = no patch)

`protocol-drop-stale-notifications.patch` — patched
`Protocol::handleNotification()` to drop notifications belonging to unknown
sessions instead of crashing the ReactPHP event loop. The Controller transport
boots Symfony fresh for every request, so session state is never carried
across requests and stale notifications cannot occur. Patch deleted with the
ReactPHP daemon mode.
