<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Server;

use Netzhirsch\ContaoMcpBundle\Security\McpPermissionEnforcer;

/**
 * Decides which MCP tools appear in `tools/list`. Used by the Lazy-Mode
 * patch in the php-mcp/server Dispatcher.
 *
 * Two filters compose:
 *   1. Lazy-Mode (static): hide everything but the discovery + cheap probe
 *      tools from `tools/list` so Claude's system prompt stays small.
 *   2. Per-user visibility: hide tools the authenticated backend user could
 *      not use anyway (module/admin level — see {@see McpPermissionEnforcer::
 *      isToolVisible()}). Under auth_mode=none this is a no-op.
 *
 * Tools hidden by (1) remain reachable via `contao_call`; tools hidden by (2)
 * are not — the user lacks the rights, so the same check also blocks
 * execution and `contao_search_tools` / `contao_describe_tool`.
 */
final class ToolFilter
{
    /**
     * Tools that the client sees. Everything else is hidden from
     * `tools/list` but still callable.
     *
     * Kept short on purpose: Discovery + health-check basics.
     *
     * @var list<string>
     */
    private const DEFAULT_EXPOSED = [
        // Discovery — the way to reach all other tools.
        'contao_search_tools',
        'contao_describe_tool',
        'contao_call',

        // Cheap, free-to-call health/probe tools that Claude likes to use.
        'ping',
        'contao_version',
        'installed_bundles',
    ];

    private bool $enabled = false;

    /**
     * @var array<string, true>
     */
    private array $exposed;

    /**
     * @param list<string>|null $exposed Allow override (env-specific configs).
     */
    public function __construct(
        private readonly McpPermissionEnforcer $permissionEnforcer,
        ?array $exposed = null,
    ) {
        $this->exposed = array_fill_keys($exposed ?? self::DEFAULT_EXPOSED, true);
    }

    /**
     * Switches lazy filtering on. Called by HttpDispatcherFactory when config
     * says `lazy_mode=true`. While disabled (the default), the filter is a
     * no-op — every tool is exposed, matching the classic php-mcp behaviour.
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isExposed(string $toolName): bool
    {
        // (1) Lazy-Mode catalog gate.
        if ($this->enabled && !isset($this->exposed[$toolName])) {
            return false;
        }

        // (2) Per-user visibility — only show what the caller may use.
        return $this->permissionEnforcer->isToolVisible($toolName);
    }

    /**
     * @return list<string>
     */
    public function exposedNames(): array
    {
        return array_keys($this->exposed);
    }
}
