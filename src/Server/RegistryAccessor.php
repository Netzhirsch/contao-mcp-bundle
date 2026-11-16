<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Server;

use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Tool;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionInterface;

/**
 * Process-wide handle on the SDK registry so any service (in particular the
 * Discovery tools) can list and invoke every registered MCP tool — including
 * those hidden from `tools/list` by the Lazy-Mode filter.
 *
 * Filled by {@see HttpDispatcherFactory} once discovery, extension
 * registration and schema flattening are done.
 */
final class RegistryAccessor
{
    private ?RegistryInterface $registry = null;

    private ?ReferenceHandlerInterface $referenceHandler = null;

    /**
     * Memoised tool catalogue. The registry does not change after the factory
     * has built and pruned it, so this is built once and reused for every
     * `tools/list`, `contao_search_tools` and `contao_describe_tool` request.
     * At 190+ tools the schema array is non-trivial to rebuild on each call.
     *
     * @var array<string, Tool>|null
     */
    private ?array $cachedTools = null;

    public function set(RegistryInterface $registry, ?ReferenceHandlerInterface $referenceHandler = null): void
    {
        $this->registry = $registry;
        $this->referenceHandler = $referenceHandler;
        $this->cachedTools = null;
    }

    public function get(): RegistryInterface
    {
        if ($this->registry === null) {
            throw new \RuntimeException(
                'The MCP tool registry has not been initialised — Discovery tools must run inside an MCP request.',
            );
        }

        return $this->registry;
    }

    /**
     * Every registered tool by name.
     *
     * @return array<string, Tool>
     */
    public function getToolsCached(): array
    {
        if ($this->cachedTools === null) {
            $this->cachedTools = [];
            foreach ($this->get()->getTools()->references as $name => $tool) {
                $this->cachedTools[(string) $name] = $tool;
            }
        }

        return $this->cachedTools;
    }

    /**
     * One tool, or null when there is none by that name. The SDK registry
     * throws for an unknown name; every caller here wants to answer that
     * case itself.
     */
    public function find(string $name): ?ToolReference
    {
        try {
            return $this->get()->getTool($name);
        } catch (ToolNotFoundException) {
            return null;
        }
    }

    /**
     * Runs a tool's handler and returns what it returned — the raw PHP value,
     * before it is wrapped into MCP content.
     *
     * Nothing else happens here: no permission check, no argument guard, no
     * undo snapshot. Callers that take requests from outside bring those
     * themselves ({@see ToolCallHandler}, `contao_call`).
     *
     * @param array<string, mixed> $arguments
     */
    public function invoke(ToolReference $reference, array $arguments, ?SessionInterface $session = null): mixed
    {
        if ($this->referenceHandler === null) {
            throw new \RuntimeException('The MCP tool registry has no handler to invoke tools with.');
        }

        // The SDK's handler resolves injectable parameters from these two keys
        // and expects the session to be there.
        $arguments['_session'] = $session ?? new Session(new InMemorySessionStore());

        return $this->referenceHandler->handle($reference, $arguments);
    }

    public function isReady(): bool
    {
        return $this->registry !== null;
    }
}
