<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Server;

use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\ContaoMcpBundle;
use Netzhirsch\ContaoMcpBundle\Service\ArgumentGuard;
use PhpMcp\Server\Server;
use PhpMcp\Server\Dispatcher;
use PhpMcp\Server\Session\SubscriptionManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds and caches the php-mcp Server + Dispatcher for the Symfony-controller
 * MCP transport (the alternative to the long-running ReactPHP daemon).
 *
 * Reasons this is a separate factory rather than reusing
 * {@see McpServerFactory}:
 *   - The daemon factory does NOT set up a Symfony-cache layer for tool
 *     discovery. In daemon mode discover() runs once at boot — the
 *     in-memory Registry survives. In controller mode each PHP-FPM
 *     request is a fresh process, so we MUST cache the discovered
 *     registry to disk via Symfony's cache.app pool. Otherwise every
 *     tool call would re-scan ~30 PHP files for #[McpTool] attributes.
 *   - The factory ALSO populates RegistryAccessor and (if lazy_mode is on)
 *     enables ToolFilter — both daemon and controller paths share these
 *     services so the Discovery / Lazy-Mode behaviour is identical.
 *
 * The build is memoised on the instance, so a single PHP-FPM worker only
 * pays the cost once across many requests.
 */
final class HttpDispatcherFactory
{
    private ?Dispatcher $dispatcher = null;
    private ?Server $server = null;

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
        private readonly PostCallHook $postCallHook,
        private readonly ArgumentGuard $argumentGuard,
        private readonly ExtensionToolRegistrar $extensionToolRegistrar,
        private readonly RequestStack $requestStack,
        private readonly string $serverName,
        private readonly string $serverVersion,
        private readonly array $scanDirs,
        private readonly array $extensionToolClasses = [],
    ) {
    }

    public function getDispatcher(): Dispatcher
    {
        if ($this->dispatcher !== null) {
            return $this->dispatcher;
        }

        $server = $this->buildServer();

        // Remove operator-disabled tools from the catalogue — ONLY on the
        // MCP-serving path (this method), never in buildServer(): the Backend
        // tool panel reads the unpruned registry via getServer() so it can
        // still render disabled tools with their descriptions. A pruned tool
        // is gone from tools/list, tools/call, search, describe and
        // contao_call alike.
        $disabled = $this->configStorage->load()['disabled_tools'] ?? [];
        if ($disabled !== []) {
            $removed = (new ToolCatalog())->prune($server->getRegistry(), $disabled);
            if ($removed !== []) {
                $this->logger->info('MCP tools disabled by operator config.', ['tools' => $removed]);
            }
        }

        // Build OUR dispatcher rather than using the one Server::build()
        // constructed internally. ContaoDispatcher adds the Lazy-Mode tool
        // filter and the post-call cleanup that used to live in a vendor
        // patch — see that class for why patching had to go.
        //
        // It also takes the object-aware SchemaValidator straight through the
        // constructor, instead of swapping the internally-built one in by
        // reflection afterwards (it rejects empty object params like
        // `fields: {}` — see ObjectAwareSchemaValidator).
        $this->dispatcher = new ContaoDispatcher(
            $server->getConfiguration(),
            $server->getRegistry(),
            $this->subscriptionManagerOf($server),
            new ObjectAwareSchemaValidator($this->logger),
            $this->toolFilter,
            $this->postCallHook,
            $this->argumentGuard,
            $this->logger,
        );

        return $this->dispatcher;
    }

    /**
     * The SubscriptionManager the Server built for itself. Reused rather than
     * newly constructed so a subscription made through the Protocol and one
     * seen by the Dispatcher stay the same object — the bundle exposes no MCP
     * resources today, but a fresh instance would be a silent trap the day it
     * does. Falls back to a new instance if the internals ever move.
     */
    private function subscriptionManagerOf(Server $server): SubscriptionManager
    {
        try {
            $dispatcherProp = (new \ReflectionObject($protocol = $server->getProtocol()))->getProperty('dispatcher');
            $dispatcherProp->setAccessible(true);
            $vendorDispatcher = $dispatcherProp->getValue($protocol);

            $smProp = (new \ReflectionObject($vendorDispatcher))->getProperty('subscriptionManager');
            $smProp->setAccessible(true);
            $subscriptionManager = $smProp->getValue($vendorDispatcher);

            if ($subscriptionManager instanceof SubscriptionManager) {
                return $subscriptionManager;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Could not reuse the server SubscriptionManager.', ['exception' => $e]);
        }

        return new SubscriptionManager($this->logger);
    }

    public function getServer(): Server
    {
        $this->buildServer();
        \assert($this->server !== null);
        return $this->server;
    }

    private function buildServer(): Server
    {
        if ($this->server !== null) {
            return $this->server;
        }

        $this->ensureRequestContext();

        $paginationLimit = (int) ($this->configStorage->load()['pagination_limit'] ?? 500);

        $builder = Server::make()
            ->withServerInfo($this->serverName, $this->serverVersion)
            ->withLogger($this->logger)
            ->withContainer($this->container)
            ->withPaginationLimit($paginationLimit);

        // Tool-discovery cache: Symfony's cache.app pool wrapped as PSR-16.
        // First request: scans + writes cache. Subsequent requests: reads.
        if ($this->container->has('cache.app')) {
            try {
                $pool = $this->container->get('cache.app');
                if ($pool instanceof \Psr\Cache\CacheItemPoolInterface) {
                    $simple = new \Symfony\Component\Cache\Psr16Cache($pool);
                    $builder = $builder->withCache($simple);
                }
            } catch (\Throwable $e) {
                $this->logger->info('cache.app unavailable for MCP discovery cache: '.$e->getMessage());
            }
        }

        $this->server = $builder->build();

        // discover() is internally cache-aware when withCache() was called.
        $this->server->discover(
            basePath: $this->bundleRoot(),
            scanDirs: $this->scanDirs,
        );

        $registry = $this->server->getRegistry();

        // Register operator-allowlisted third-party tools AFTER core
        // discovery, so core tool names are already "taken" and always win
        // a collision. Runs before registryAccessor->set() so Discovery /
        // Lazy-Mode tools see extension tools too.
        $enabled = $this->configStorage->load()['extension_tools_enabled'] ?? [];
        $this->extensionToolRegistrar->register($registry, $enabled, $this->extensionToolClasses);

        // Flatten ["null", T] type-unions on optional params to a single type.
        // Fragile clients (mcp-remote, Claude Code deferred-tool loader) drop
        // union types entirely → param becomes typeless → -32602 on any value.
        // Runs over the full registry (core + cached + extension tools); the
        // rewritten schema feeds tools/list, describe AND server validation.
        (new NullableUnionFlattener())->flattenRegistry($registry);

        // Populate the shared RegistryAccessor so Discovery / Lazy-Mode tools
        // can introspect the catalogue.
        $this->registryAccessor->set($registry);

        // Enable Lazy-Mode if configured.
        if (!empty($this->configStorage->load()['lazy_mode'])) {
            $this->toolFilter->enable();
        }

        return $this->server;
    }

    private function bundleRoot(): string
    {
        return \dirname((new \ReflectionClass(ContaoMcpBundle::class))->getFileName(), 2);
    }

    /**
     * Contao classes (Versions, etc.) read `request_stack->getCurrentRequest()`
     * unconditionally. In a fresh PHP-FPM worker without a real Symfony
     * request boot (e.g. when McpController is invoked outside the normal
     * kernel.request flow), we push a synthetic request once. In normal
     * controller dispatch the RequestStack is already populated, so this
     * is usually a no-op.
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
