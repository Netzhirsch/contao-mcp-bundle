# Extending the Contao MCP Server with your own tools

Other Contao bundles can contribute additional MCP tools to the server
shipped by `netzhirsch/contao-mcp-bundle`. Your tools join the core ones on
the same `/mcp` endpoint — same OAuth gate, same rate limit, same Discovery
and Lazy-Mode behaviour. You write `#[McpTool]` methods exactly the way the
core tools are written.

This document is for **developers of a tool-provider bundle**. For operating
the server itself, see `README.md`.

> **Stability:** the extension API described here (the marker interface, the
> `netzhirsch_mcp.tool` tag, the `AbstractMcpTool` base, the
> `extension_tools_enabled` config key) is part of the bundle's frozen public
> surface from `v1.0.0` on. Treat it as a contract.

---

## TL;DR

```php
// src/Mcp/InvoiceTool.php in your bundle
namespace Acme\InvoiceBundle\Mcp;

use Netzhirsch\ContaoMcpBundle\Extension\AbstractMcpTool;
use PhpMcp\Server\Attributes\McpTool;

final class InvoiceTool extends AbstractMcpTool
{
    #[McpTool(
        name: 'acme_invoice_get',
        description: 'Fetches a single Acme invoice by its number.',
    )]
    public function get(string $invoiceNumber): array
    {
        // ... your logic
        return ['number' => $invoiceNumber, 'status' => 'paid'];
    }
}
```

```yaml
# config/services.yaml in your bundle (autoconfigure: true is the default)
services:
    _defaults:
        autowire: true
        autoconfigure: true

    Acme\InvoiceBundle\Mcp\InvoiceTool: ~
```

Then the **site operator** enables it (it is OFF until they do — see
[Security model](#security-model)). The normal way is the Backend:
**MCP-Server → Tools** lists your tool automatically — with an
`EXT` badge, grouped by its name prefix, description as tooltip — and one
checkbox activates it. You do NOT have to build any UI for this; tagging the
service is enough.

The equivalent low-level switch is the config file:

```jsonc
// var/mcp/config.json
{
    "extension_tools_enabled": ["acme_invoice_get"]
}
```

Done. `acme_invoice_get` is now callable over MCP.

> The same panel also lets operators disable any **core** tool per
> instance (`disabled_tools`, opt-out). Your extension tools follow the
> stricter opt-in rule — see [Security model](#security-model).

---

## How it works

1. **Tagging.** Any service implementing
   `Netzhirsch\ContaoMcpBundle\Extension\McpToolProviderInterface` is
   automatically tagged `netzhirsch_mcp.tool` (via Symfony
   `registerForAutoconfiguration`). `AbstractMcpTool` implements that
   interface, so extending it is enough. If your bundle disables
   autoconfiguration, add the tag by hand:

   ```yaml
   Acme\InvoiceBundle\Mcp\InvoiceTool:
       tags: ['netzhirsch_mcp.tool']
   ```

2. **Collection.** A compiler pass (`McpToolProviderPass`) gathers every
   tagged service, forces it public (php-mcp resolves tool handlers from the
   container by FQCN), and hands the class list to the server factory.

3. **Registration.** On each server boot, after the core tools are
   discovered, the factory reflects your classes for `#[McpTool]` methods and
   registers the **allowlisted** ones into the live registry — reusing
   php-mcp's own schema generator, so your input schema is derived from your
   method signature identically to a core tool.

You never call discovery or registration yourself. You write the class and
register it as a service; the operator flips it on.

---

## Security model

This is the part that matters. Making a tool *LLM-callable over HTTP* is a
bigger deal than adding a normal service — a prompt-injected model could try
to call it. The bundle defends that with three mechanisms.

### 1. Disabled by default (the allowlist)

**Installing your bundle exposes nothing.** An extension tool is only ever
registered if its `#[McpTool]` name appears in the operator's
`extension_tools_enabled` list in `var/mcp/config.json`. The default is an
empty list.

This is deliberate: a `composer require` must never silently widen the
LLM-reachable attack surface. The operator makes a conscious, per-tool
decision — normally via the Backend tool panel (MCP-Server →
Tools), where every offered-but-disabled extension tool shows up as an
unchecked `EXT` row. Checking it writes the allowlist; unchecking removes
the entry again. Core tools in the same panel work the other way around
(opt-out via `disabled_tools`) — the asymmetry is intentional: core ships
enabled, third-party ships disabled.

When the server boots and finds a tagged-but-not-enabled tool, it logs
(info level) so the operator can discover what is available:

```
MCP extension tool available but not enabled — add its name to
extension_tools_enabled to activate. {"tool":"acme_invoice_get","class":"..."}
```

### 2. Core always wins a name collision

If your tool's name matches an existing tool (a core tool, or another
extension registered earlier), **yours is skipped** and an error is logged.
Core tools can never be shadowed or hijacked by an extension. This is why you
must **namespace your tool names** with a vendor prefix
(`acme_invoice_get`, not `invoice_get`).

### 3. Same auth + throttle as core

Your tool sits behind the same OAuth Bearer gate (when `auth_mode=oauth`) and
shares the per-client rate limit. You inherit the bundle's transport-level
protections for free — but they are shared, so a pathologically expensive
tool can still consume a client's budget. Keep tools cheap, or document the
cost.

### 4. Backend-permission parity (admin-only by default)

The bundle enforces that a non-admin MCP caller may only do through the server
what their Contao backend account is allowed to do — it maps every **core**
tool to the backend permission it implies (table + CRUD op, owning module, or
admin-only) and asks Contao's own voters.

It cannot infer the semantics of *your* tool. So the secure default is:
**an extension tool is restricted to administrators** (and hidden from
non-admins in `tools/list`). A `composer require` can never hand a non-admin
new powers you didn't think through.

To make a tool usable by non-admins *with parity*, you **declare its
requirement** — implement `McpToolPermissionProviderInterface` (in addition to
the marker interface `AbstractMcpTool` already gives you) and return a map of
tool-name → requirement:

```php
use Netzhirsch\ContaoMcpBundle\Extension\AbstractMcpTool;
use Netzhirsch\ContaoMcpBundle\Extension\McpToolPermissionProviderInterface;

final class InvoiceTool extends AbstractMcpTool implements McpToolPermissionProviderInterface
{
    public function getMcpToolPermissions(): array
    {
        return [
            // DataContainer voter — same parity Contao applies in the backend.
            // The row id (from an `id`/`row_id` arg) and written fields are
            // filled in automatically; declare only the static table + op.
            'acme_invoice_get'    => ['kind' => 'dc', 'table' => 'tl_acme_invoice', 'op' => 'read'],
            'acme_invoice_delete' => ['kind' => 'dc', 'table' => 'tl_acme_invoice', 'op' => 'delete'],
            // Other kinds: ['kind' => 'none'] (read-only meta),
            //              ['kind' => 'module', 'module' => 'files'],
            //              ['kind' => 'admin'].
        ];
    }
    // ... #[McpTool] methods
}
```

A declaration gates **whether the call runs at all**. It does **not** filter
your output — that is the second half of parity and stays your job:

> **List-result parity.** A tool that lists/returns records must drop the ones
> the caller couldn't see in the backend. Use the guard from
> `permissionGuard()` (see the helper table) — `filterReadable()` /
> `mayRead()` for voter-scoped tables, `accessiblePageIds()` for
> page/article scoping, `mayAccessRecord()` for a single record before a
> write. The core list tools (`news_list`, `pages_list`, …) all do this; mirror
> it. Returning a row the caller can't reach is a leak, even if the *call* was
> allowed.

Rules of the road:

- **Core always wins.** If you declare a requirement for a name that collides
  with a core tool, your declaration is ignored (and the name collision means
  your tool isn't registered anyway — see §2). Namespace your names.
- **Undeclared = admin-only.** No declaration, no non-admin access. There is no
  way to *accidentally* widen access: you must name the tool AND give it a
  requirement.
- **Keep `getMcpToolPermissions()` pure.** It builds a static map; no I/O, no
  request state.

### What the bundle does NOT do for you

- It does not validate your tool's input. MCP arguments arrive as
  JSON-decoded values; **you** must validate them before touching SQL, the
  filesystem, or outbound HTTP.
- It does not sandbox your code. A tool runs with the full privileges of the
  PHP process — same as any installed bundle. The allowlist controls
  *exposure to the LLM*, not *what your code may do*.

---

## The author contract

Follow these or your tool will be a liability:

1. **Validate every argument.** Treat all input as hostile. Cast, range-check,
   allowlist. Never interpolate input into SQL — use parameter binding.
2. **Namespace your tool names.** Vendor prefix, always. `acme_*`,
   `mycustomer_*`. Protects you from core collisions now and in future versions.
3. **Attribute your writes.** Use `resolveAuthorId()` / `authorResolver()` so
   changes land in `tl_log` + `tl_version` under the calling identity instead
   of as anonymous mutations. An unattributed write is invisible in the
   MCP-Activity panel and the version history.
4. **Gate destructive actions.** Use `requireConfirmation()` so a hallucinated
   delete cannot fire without an explicit `confirm_destructive=true`.
5. **Return structured data.** Return arrays/scalars that serialise to JSON.
   On a handled error, return `['error' => '<code>', 'message' => '<human>']`
   rather than throwing — it reads better to the model.
6. **Keep tools focused and cheap.** One job per tool. No unbounded loops, no
   multi-minute external calls.
7. **Mirror backend rights.** If your tool touches Contao records and you want
   non-admins to use it, declare its permission requirement
   (`McpToolPermissionProviderInterface`) AND filter every list result /
   authorise every record-write with `permissionGuard()`. Without the
   declaration your tool is admin-only; without the filtering it leaks rows the
   caller couldn't reach in the backend. See [Security model §4](#4-backend-permission-parity-admin-only-by-default).
8. **Write for the tool panel.** Your `#[McpTool]` name and description ARE
   your operator UI: the Backend panel (MCP-Server → Tools) renders
   the name grouped by its prefix and the description's first sentence as the
   tooltip an operator reads before deciding to enable you. Start the
   description with one plain sentence stating what the tool does and what it
   touches ("Reads invoice headers from tl_acme_invoice — no writes."), then
   the LLM-facing detail. Treat the name as frozen API: operators persist it
   in `extension_tools_enabled`; renaming a tool silently disables it on
   every instance until the operator re-enables the new name.

---

## `AbstractMcpTool` helper reference

Extend `AbstractMcpTool` and you get, via protected methods:

| Method | Purpose |
|---|---|
| `callContext(): McpCallContext` | Who is calling — OAuth user id, client id/name. Empty under `auth_mode=none`. Read-only. |
| `authorResolver(): AuthorResolver` | Resolve tl_user id + log username/source for attributing writes. |
| `resolveAuthorId(): int` | Shortcut: the tl_user id to attribute writes to (OAuth user, or configured default). |
| `withDeadlockRetry(Connection, callable): mixed` | Run a transaction with deadlock/lock-wait retry instead of bubbling a raw MySQL error. |
| `requireConfirmation(bool): ?array` | The `confirm_destructive` gate. Returns an error array when the flag is missing, `null` when safe to proceed. |
| `permissionGuard(): McpPermissionGuard` | Contao-parity helpers for **list-result filtering** and per-record authorisation: `filterReadable(table, rows)` / `mayRead(table, row)` (voter-scoped tables), `accessiblePageIds()` (pagemount scope for page/article lists, `null` = unrestricted), `mayAccessRecord(table, row)` (combined see/edit check before a write), `ensureCan(table, op, id, fields)` (full per-op check). No-op for admins / trusted mode. |

Dependencies are injected via a single `#[Required]` setter, so your subclass
keeps a clean constructor for its own collaborators:

```php
final class InvoiceTool extends AbstractMcpTool
{
    public function __construct(
        private readonly Connection $db,          // your own deps
        private readonly InvoiceRepository $repo,
    ) {
        // No parent::__construct() needed — the base has none.
    }
}
```

(You only need to extend `AbstractMcpTool`. If you can't — e.g. you already
extend something else — implement `McpToolProviderInterface` directly and
inject `McpCallContext` / `AuthorResolver` / `DbalRetry` yourself.)

---

## A complete example with a write + destructive gate

```php
namespace Acme\InvoiceBundle\Mcp;

use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Extension\AbstractMcpTool;
use Netzhirsch\ContaoMcpBundle\Extension\McpToolPermissionProviderInterface;
use PhpMcp\Server\Attributes\McpTool;

final class InvoiceTool extends AbstractMcpTool implements McpToolPermissionProviderInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Backend-permission parity: without this, both tools are admin-only.
     * Declaring the DataContainer requirement lets a non-admin with rights on
     * tl_acme_invoice use them, gated by Contao's own voter.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getMcpToolPermissions(): array
    {
        return [
            'acme_invoice_get'    => ['kind' => 'dc', 'table' => 'tl_acme_invoice', 'op' => 'read'],
            'acme_invoice_delete' => ['kind' => 'dc', 'table' => 'tl_acme_invoice', 'op' => 'delete'],
        ];
    }

    /**
     * @return array{number: string, status: string}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'acme_invoice_get',
        description: 'Fetches one invoice by number. Read-only.',
    )]
    public function get(string $invoiceNumber): array
    {
        $number = trim($invoiceNumber);
        if ($number === '' || !preg_match('/^INV-\d{4,}$/', $number)) {
            return ['error' => 'invalid_input', 'message' => 'invoiceNumber must look like INV-1234.'];
        }

        $row = $this->db->fetchAssociative(
            'SELECT number, status FROM tl_acme_invoice WHERE number = ?',
            [$number],
        );
        if ($row === false) {
            return ['error' => 'not_found', 'message' => "No invoice {$number}."];
        }

        return ['number' => (string) $row['number'], 'status' => (string) $row['status']];
    }

    /**
     * @return array{deleted: true, number: string}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'acme_invoice_delete',
        description: 'Deletes an invoice. Requires confirm_destructive=true.',
    )]
    public function delete(string $invoiceNumber, bool $confirm_destructive = false): array
    {
        if ($err = $this->requireConfirmation($confirm_destructive)) {
            return $err;
        }

        $number = trim($invoiceNumber);
        if (!preg_match('/^INV-\d{4,}$/', $number)) {
            return ['error' => 'invalid_input', 'message' => 'invoiceNumber must look like INV-1234.'];
        }

        $this->withDeadlockRetry($this->db, function (Connection $db) use ($number): void {
            $db->executeStatement('DELETE FROM tl_acme_invoice WHERE number = ?', [$number]);
        });

        return ['deleted' => true, 'number' => $number];
    }
}
```

---

## Integration details

- **Lazy-Mode.** When the operator runs in Lazy-Mode, extension tools are
  hidden from `tools/list` (like most core tools) but remain reachable via the
  `contao_call` Discovery tool. They show up in `contao_search_tools` /
  `contao_describe_tool`.
- **Discovery cache.** Extension tools are registered fresh on each worker
  boot (not written to the discovery cache). For a handful of tools this is
  negligible; memoised once per worker.
- **`contao_version` / `installed_bundles`.** Your bundle appears in
  `installed_bundles` like any other — useful for a tool that should only run
  when a companion extension is present.

---

## Testing your tool

Your own bundle's test suite should cover your tool logic directly (it's just
a class with methods — call them). To verify it registers correctly against
the real server, the host bundle's own test pattern is:

```php
use Netzhirsch\ContaoMcpBundle\Server\ExtensionToolRegistrar;
use PhpMcp\Server\Registry;
use Psr\Log\NullLogger;

$registry = new Registry(new NullLogger());
$registrar = new ExtensionToolRegistrar(new NullLogger());

// Enabled → registered.
$registrar->register($registry, ['acme_invoice_get'], [InvoiceTool::class]);
self::assertNotNull($registry->getTool('acme_invoice_get'));

// Not enabled → absent.
$registry2 = new Registry(new NullLogger());
$registrar->register($registry2, [], [InvoiceTool::class]);
self::assertNull($registry2->getTool('acme_invoice_get'));
```

---

## Troubleshooting

**My tool doesn't appear.**

1. Is it enabled? Open **MCP-Server → Tools** — your tool must
   appear there with an `EXT` badge and a checked box. Not listed at all →
   the service isn't tagged/collected (steps 2–3). Listed but unchecked →
   check it, or verify `extension_tools_enabled` in `var/mcp/config.json`
   contains the exact `#[McpTool]` name.
2. Clear the Symfony cache after adding the service (`cache:clear`).
3. Check `var/log/prod.log` for `MCP extension tool ...` lines — they tell you
   whether the tool was found, skipped (disabled / collision), or failed.
4. Is the service registered and autowired? `debug:container Acme\\...\\InvoiceTool`
   must show it, public, tagged `netzhirsch_mcp.tool`.

**"name collides" in the log.** Your tool name matches a core or earlier
extension tool. Rename with a vendor prefix.

**"class not found" / "registration failed".** Autoload or reflection issue —
the log line carries the reason. Usually a typo in the FQCN or a method that
isn't public.
