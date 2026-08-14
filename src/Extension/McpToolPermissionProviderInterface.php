<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Extension;

/**
 * Optional opt-in for extension tools that want backend-permission PARITY for
 * non-admin MCP callers.
 *
 * Why this exists: the core enforcer maps every core tool to the backend
 * permission it implies (table + CRUD op, owning module, admin-only) and asks
 * Contao's own voters. It cannot know the semantics of YOUR tool, so by
 * default an extension tool is restricted to administrators (the secure
 * fallback for an unmapped tool — see {@see \Netzhirsch\ContaoMcpBundle\Security\McpPermissionEnforcer}).
 *
 * Implement this interface (in addition to {@see McpToolProviderInterface},
 * which {@see AbstractMcpTool} already does) to DECLARE the requirement for
 * each of your `#[McpTool]` methods. The core enforcer then applies exactly
 * the same parity it applies to a core tool — including module-level
 * visibility in `tools/list` and per-record voter checks — so a non-admin
 * backend user reaches your tool iff their account may perform the underlying
 * backend operation.
 *
 * Tools you do NOT list here keep the admin-only default. There is no way to
 * accidentally widen access: you must name a tool AND give it a requirement.
 *
 * Per-record / list-result scoping (e.g. only returning rows the caller may
 * read) is STILL your responsibility inside the tool body — declaring the
 * requirement gates the call, it does not filter your output. Use the
 * permission guard exposed by {@see AbstractMcpTool::permissionGuard()} for
 * that (filterReadable / mayRead / accessiblePageIds / mayAccessRecord).
 *
 * @see \Netzhirsch\ContaoMcpBundle\Security\ToolPermissionMap for the shape of
 *      a requirement and the runtime id/fields hydration.
 */
interface McpToolPermissionProviderInterface extends McpToolProviderInterface
{
    /**
     * Map of `#[McpTool]` name → permission requirement.
     *
     * A requirement is one of:
     *   ['kind' => 'none']                                  — read-only meta, no check
     *   ['kind' => 'admin']                                 — administrators only
     *   ['kind' => 'module', 'module' => 'files']           — backend module access
     *   ['kind' => 'dc', 'table' => 'tl_acme', 'op' => '…'] — DataContainer voter,
     *                                                          op ∈ create|read|update|delete
     *
     * For 'dc' requirements the runtime row id (from an `id`/`row_id` argument)
     * and the written fields (for create/update, driving field-level checks)
     * are filled in automatically from the call arguments — declare only the
     * static table + op.
     *
     * Keep this method pure and cheap: it is called to build the permission
     * map and must not perform I/O or rely on request state.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getMcpToolPermissions(): array;
}
