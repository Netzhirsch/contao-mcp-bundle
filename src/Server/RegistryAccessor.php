<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Server;

use PhpMcp\Server\Registry;

/**
 * Process-wide cache for the php-mcp/server {@see Registry} so any service
 * (in particular the Discovery tools) can list and invoke every registered
 * MCP tool — including those hidden from `tools/list` by the Lazy-Mode
 * filter.
 *
 * Filled by {@see \Netzhirsch\ContaoMcpBundle\Server\HttpDispatcherFactory}
 * right after `Server::create()->discover()` runs.
 */
final class RegistryAccessor
{
    private ?Registry $registry = null;

    /**
     * Memoised tool catalogue. The registry is immutable after the daemon's
     * `discover()` call, so we can build this once and re-use it for every
     * `tools/list`, `contao_search_tools` and `contao_describe_tool` request.
     * At 156+ tools the schema array is non-trivial to rebuild on each call.
     *
     * @var array<string, mixed>|null
     */
    private ?array $cachedTools = null;

    public function set(Registry $registry): void
    {
        $this->registry = $registry;
        $this->cachedTools = null;  // invalidate (defensive — set() should run only once)
    }

    public function get(): Registry
    {
        if ($this->registry === null) {
            throw new \RuntimeException(
                'McpServerRegistry has not been initialised — Discovery tools must run inside the MCP daemon, not in CLI/HTTP scope.',
            );
        }

        return $this->registry;
    }

    /**
     * Cached view of `Registry::getTools()`. Returns the same array reference
     * across calls (consumer-safe because the registry doesn't mutate after
     * discovery). At 150+ tools this saves an array_map() per discovery turn.
     *
     * @return array<string, \PhpMcp\Server\Elements\RegisteredTool>
     */
    public function getToolsCached(): array
    {
        if ($this->cachedTools === null) {
            $this->cachedTools = $this->get()->getTools();
        }
        return $this->cachedTools;
    }

    public function isReady(): bool
    {
        return $this->registry !== null;
    }
}
