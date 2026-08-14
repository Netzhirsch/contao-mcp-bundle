<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Server;

use Netzhirsch\ContaoMcpBundle\Extension\ExtensionToolGate;
use PhpMcp\Schema\Tool as ToolSchema;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Registry;
use PhpMcp\Server\Utils\DocBlockParser;
use PhpMcp\Server\Utils\HandlerResolver;
use PhpMcp\Server\Utils\SchemaGenerator;
use Psr\Log\LoggerInterface;

/**
 * Registers third-party `#[McpTool]` methods into a php-mcp {@see Registry},
 * gated by the operator allowlist.
 *
 * Split out of {@see HttpDispatcherFactory} so the security-relevant
 * registration path (allowlist + collision handling + schema derivation) can
 * be tested against a real Registry without booting the whole MCP Server.
 *
 * Reuses php-mcp's own {@see SchemaGenerator}/{@see DocBlockParser}/
 * {@see HandlerResolver} so extension tools get byte-for-byte identical schema
 * derivation to the core tools — a third-party author writes `#[McpTool]`
 * methods exactly the way the bundle's own tools are written.
 *
 * Tools are registered as `isManual: true`: they survive a later
 * `Registry::clear()` and are never written to the discovery cache (cheaply
 * re-registered per worker boot, the same lifecycle php-mcp gives its own
 * manually-registered tools).
 */
final class ExtensionToolRegistrar
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Lists every `#[McpTool]` an installed extension bundle OFFERS — enabled
     * or not, registered or not. Data source for the Backend tool panel,
     * which must render not-yet-enabled extension tools with a toggle so the
     * operator can activate them without editing config.json.
     *
     * Same attribute scan as {@see register()}, but read-only: no schema
     * generation, no handler resolution, no registry mutation — broken tools
     * still show up here (they fail loudly at registration time instead).
     *
     * @param list<string> $providerClasses FQCNs of tool provider classes
     *
     * @return list<array{name: string, description: string, class: string}>
     */
    public function candidates(array $providerClasses): array
    {
        $docBlockParser = new DocBlockParser($this->logger);
        $candidates = [];

        foreach ($providerClasses as $class) {
            if (!class_exists($class)) {
                continue;
            }

            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $attributes = $method->getAttributes(McpTool::class);
                if ($attributes === []) {
                    continue;
                }

                /** @var McpTool $mcpTool */
                $mcpTool = $attributes[0]->newInstance();

                $candidates[] = [
                    'name' => $mcpTool->name ?? $method->getName(),
                    'description' => $mcpTool->description
                        ?? $docBlockParser->getSummary($docBlockParser->parseDocBlock($method->getDocComment() ?: null))
                        ?? '',
                    'class' => $class,
                ];
            }
        }

        return $candidates;
    }

    /**
     * @param list<string> $enabledNames   operator allowlist (extension_tools_enabled)
     * @param list<string> $providerClasses FQCNs of tool provider classes
     *
     * @return list<string> names of the tools that were actually registered
     */
    public function register(Registry $registry, array $enabledNames, array $providerClasses): array
    {
        if ($providerClasses === []) {
            return [];
        }

        // Names already present after core discovery — core (and earlier
        // extensions) win every collision. Built once, updated as we go.
        $taken = [];
        foreach (array_keys($registry->getTools()) as $name) {
            $taken[$name] = true;
        }

        $docBlockParser = new DocBlockParser($this->logger);
        $schemaGenerator = new SchemaGenerator($docBlockParser);

        $registered = [];

        foreach ($providerClasses as $class) {
            if (!class_exists($class)) {
                $this->logger->error('MCP extension tool class not found — skipped.', ['class' => $class]);
                continue;
            }

            $reflectionClass = new \ReflectionClass($class);
            foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $attributes = $method->getAttributes(McpTool::class);
                if ($attributes === []) {
                    continue;
                }

                /** @var McpTool $mcpTool */
                $mcpTool = $attributes[0]->newInstance();
                $name = $mcpTool->name ?? $method->getName();

                $decision = ExtensionToolGate::decide($name, $enabledNames, $taken);
                if ($decision === ExtensionToolGate::SKIP_DISABLED) {
                    $this->logger->info(
                        'MCP extension tool available but not enabled — add its name to extension_tools_enabled to activate.',
                        ['tool' => $name, 'class' => $class],
                    );
                    continue;
                }
                if ($decision === ExtensionToolGate::SKIP_DUPLICATE) {
                    $this->logger->error(
                        'MCP extension tool name collides with an already-registered tool — skipped (core/earlier registration wins).',
                        ['tool' => $name, 'class' => $class],
                    );
                    continue;
                }

                try {
                    $handler = [$class, $method->getName()];
                    $reflection = HandlerResolver::resolve($handler);
                    $inputSchema = $schemaGenerator->generate($reflection);
                    $description = $mcpTool->description
                        ?? $docBlockParser->getSummary($docBlockParser->parseDocBlock($method->getDocComment() ?: null));

                    $tool = ToolSchema::make($name, $inputSchema, $description, $mcpTool->annotations);
                    $registry->registerTool($tool, $handler, true);

                    $taken[$name] = true;
                    $registered[] = $name;
                    $this->logger->info('MCP extension tool registered.', ['tool' => $name, 'class' => $class]);
                } catch (\Throwable $e) {
                    $this->logger->error('MCP extension tool registration failed — skipped.', [
                        'tool' => $name,
                        'class' => $class,
                        'reason' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $registered;
    }
}
