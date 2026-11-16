<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Server;

use Doctrine\DBAL\Connection;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Server;
use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\ContaoMcpBundle;
use Netzhirsch\ContaoMcpBundle\Security\OperatorPolicy;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the MCP server (official `mcp/sdk`) that {@see \Netzhirsch\ContaoMcpBundle\Controller\McpController}
 * hands every request to.
 *
 * Two views of the same catalogue:
 *
 *   - {@see getServer()} serves MCP. The operator's disabled tools and those
 *     an operator switch rules out are gone from it entirely — from
 *     `tools/list`, `tools/call`, search, describe and `contao_call` alike.
 *   - {@see getRegistry()} is the unpruned catalogue the Backend tool panel
 *     renders, disabled tools included, with their descriptions.
 *
 * Tool discovery is cached in Symfony's cache.app pool: every request is a
 * fresh PHP process under FPM, and scanning ~40 classes for attributes each
 * time would cost more than the call itself.
 *
 * The build is memoised on the instance, so a request pays for it once.
 */
final class HttpDispatcherFactory
{
    private ?Server $server = null;

    private ?Registry $registry = null;

    private ?ReferenceHandler $referenceHandler = null;

    private bool $pruned = false;

    /**
     * @param list<string> $scanDirs
     * @param list<string> $extensionToolClasses FQCNs of third-party tool
     *        providers, collected from the `netzhirsch_mcp.tool` tag by
     *        {@see \Netzhirsch\ContaoMcpBundle\DependencyInjection\Compiler\McpToolProviderPass}.
     *        Empty by default; only enabled tools (per `extension_tools_enabled`)
     *        are actually registered.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly McpServerConfigStorage $configStorage,
        private readonly RegistryAccessor $registryAccessor,
        private readonly ToolFilter $toolFilter,
        private readonly ToolCallHandler $toolCallHandler,
        private readonly ExtensionToolRegistrar $extensionToolRegistrar,
        private readonly RequestStack $requestStack,
        private readonly OperatorPolicy $operatorPolicy,
        private readonly ServerInstructions $serverInstructions,
        private readonly Connection $connection,
        private readonly string $serverName,
        private readonly string $serverVersion,
        private readonly array $scanDirs,
        private readonly array $extensionToolClasses = [],
    ) {
    }

    /**
     * The server that answers MCP requests, its catalogue pruned to what this
     * installation offers.
     */
    public function getServer(): Server
    {
        $server = $this->build();

        if (!$this->pruned) {
            $this->pruned = true;
            $this->prune();
        }

        return $server;
    }

    /**
     * The whole catalogue, nothing pruned — for the Backend tool panel.
     * Call it before {@see getServer()} in the same request, or it is pruned.
     */
    public function getRegistry(): Registry
    {
        $this->build();
        \assert($this->registry !== null);

        return $this->registry;
    }

    /**
     * Removes operator-disabled tools from the catalogue — ONLY on the
     * MCP-serving path, never in build(): the Backend tool panel reads the
     * unpruned registry so it can still render disabled tools.
     *
     * The operator switches (read-only, no deletions, privacy mode) take
     * their tools out the same way: a tool that could only ever be refused
     * should not be offered.
     */
    private function prune(): void
    {
        \assert($this->registry !== null);

        $disabled = [
            ...($this->configStorage->load()['disabled_tools'] ?? []),
            ...$this->operatorPolicy->blockedTools(array_keys($this->registry->getTools()->references)),
        ];

        if ($disabled === []) {
            return;
        }

        $removed = (new ToolCatalog())->prune($this->registry, array_values(array_unique($disabled)));
        if ($removed !== []) {
            $this->logger->info('MCP tools disabled by operator config.', ['tools' => $removed]);
            // The accessor memoised the catalogue on set(); start it over.
            $this->registryAccessor->set($this->registry, $this->referenceHandler);
        }
    }

    private function build(): Server
    {
        if ($this->server !== null) {
            return $this->server;
        }

        $this->ensureRequestContext();

        $config = $this->configStorage->load();
        $paginationLimit = max(1, (int) ($config['pagination_limit'] ?? 500));

        $this->registry = new Registry(logger: $this->logger);
        $this->referenceHandler = new ReferenceHandler($this->container);

        $builder = Server::builder()
            ->setServerInfo($this->serverName, $this->serverVersion)
            ->setLogger($this->logger)
            ->setContainer($this->container)
            ->setPaginationLimit($paginationLimit)
            ->setInstructions($this->serverInstructions->text())
            ->setRegistry($this->registry)
            ->setReferenceHandler($this->referenceHandler)
            ->setSession(new DatabaseSessionStore($this->connection, $this->logger))
            // Ahead of the SDK's own handlers for the same requests, which the
            // SDK consults only when no custom handler claims a request.
            ->addRequestHandler($this->toolCallHandler)
            ->addRequestHandler(new ToolListHandler($this->registryAccessor, $this->toolFilter, $this->logger, $paginationLimit))
            ->setDiscovery($this->bundleRoot(), $this->scanDirs, [], $this->discoveryCache());

        // Discovery runs here: a registry handed to the builder is loaded
        // eagerly while the server is built.
        $this->server = $builder->build();

        // Register operator-allowlisted third-party tools AFTER core
        // discovery, so core tool names are already "taken" and always win
        // a collision. Runs before registryAccessor->set() so Discovery /
        // Lazy-Mode tools see extension tools too.
        $this->extensionToolRegistrar->register(
            $this->registry,
            $config['extension_tools_enabled'] ?? [],
            $this->extensionToolClasses,
        );

        // Flatten ["null", T] type-unions on optional params to a single type.
        // Fragile clients (mcp-remote, Claude Code deferred-tool loader) drop
        // union types entirely → param becomes typeless → -32602 on any value.
        // Runs over the full registry (core + cached + extension tools); the
        // rewritten schema feeds tools/list, describe AND server validation.
        (new NullableUnionFlattener())->flattenRegistry($this->registry);

        // Populate the shared RegistryAccessor so Discovery / Lazy-Mode tools
        // can introspect the catalogue and invoke tools.
        $this->registryAccessor->set($this->registry, $this->referenceHandler);

        if (!empty($config['lazy_mode'])) {
            $this->toolFilter->enable();
        }

        return $this->server;
    }

    /**
     * Symfony's cache.app pool as PSR-16, or none when it is not available —
     * discovery then simply scans every time.
     */
    private function discoveryCache(): ?Psr16Cache
    {
        if (!$this->container->has('cache.app')) {
            return null;
        }

        try {
            $pool = $this->container->get('cache.app');

            return $pool instanceof \Psr\Cache\CacheItemPoolInterface ? new Psr16Cache($pool) : null;
        } catch (\Throwable $e) {
            $this->logger->info('cache.app unavailable for MCP discovery cache: '.$e->getMessage());

            return null;
        }
    }

    private function bundleRoot(): string
    {
        return \dirname((string) (new \ReflectionClass(ContaoMcpBundle::class))->getFileName(), 2);
    }

    /**
     * Contao classes (Versions, etc.) read `request_stack->getCurrentRequest()`
     * unconditionally. When the factory is reached without a real request
     * (console commands, the smoke test), a synthetic one is pushed once. In
     * normal controller dispatch the RequestStack is already populated, so
     * this is usually a no-op.
     */
    private function ensureRequestContext(): void
    {
        if ($this->requestStack->getCurrentRequest() !== null) {
            return;
        }

        $request = Request::create('/_mcp', 'POST');
        $request->server->set('QUERY_STRING', '');
        $this->requestStack->push($request);
    }
}
