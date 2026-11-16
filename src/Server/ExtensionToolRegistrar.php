<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Server;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\ElementMetadataResolver;
use Mcp\Capability\Discovery\SchemaGenerator;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool as ToolSchema;
use Mcp\Schema\ToolAnnotations;
use Netzhirsch\ContaoMcpBundle\Extension\ExtensionToolGate;
use Psr\Log\LoggerInterface;

/**
 * Registers third-party `#[McpTool]` methods into the SDK registry, gated by
 * the operator allowlist.
 *
 * Split out of {@see HttpDispatcherFactory} so the security-relevant
 * registration path (allowlist + collision handling + schema derivation) can
 * be tested against a real Registry without booting the whole MCP Server.
 *
 * Reuses the SDK's own {@see SchemaGenerator}/{@see DocBlockParser} so
 * extension tools get byte-for-byte identical schema derivation to the core
 * tools — a third-party author writes `#[McpTool]` methods exactly the way the
 * bundle's own tools are written.
 *
 * Both attribute namespaces are accepted: the SDK's
 * `Mcp\Capability\Attribute\McpTool`, and php-mcp/server's
 * `PhpMcp\Server\Attributes\McpTool` that EXTENDING.md documented up to 1.40.
 * The attribute is read from its arguments rather than instantiated, so the
 * old name works whether {@see PhpMcpAttributeAliases} aliased it or php-mcp
 * is still installed by another package.
 *
 * Extension tools are registered per worker boot and never reach the
 * discovery cache, which only holds what the scan of src/Tool found.
 */
final class ExtensionToolRegistrar
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        PhpMcpAttributeAliases::register();
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
        $docBlockParser = new DocBlockParser(logger: $this->logger);
        $candidates = [];

        foreach ($providerClasses as $class) {
            if (!class_exists($class)) {
                continue;
            }

            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $attribute = self::toolAttribute($method);
                if ($attribute === null) {
                    continue;
                }

                $candidates[] = [
                    'name' => ElementMetadataResolver::resolveName($method, $attribute['name']),
                    'description' => ElementMetadataResolver::resolveDescription($method, $attribute['description'], $docBlockParser) ?? '',
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
    public function register(RegistryInterface $registry, array $enabledNames, array $providerClasses): array
    {
        if ($providerClasses === []) {
            return [];
        }

        // Names already present after core discovery — core (and earlier
        // extensions) win every collision. Built once, updated as we go.
        $taken = [];
        foreach (array_keys($registry->getTools()->references) as $name) {
            $taken[(string) $name] = true;
        }

        $docBlockParser = new DocBlockParser(logger: $this->logger);
        $schemaGenerator = new SchemaGenerator($docBlockParser);

        $registered = [];

        foreach ($providerClasses as $class) {
            if (!class_exists($class)) {
                $this->logger->error('MCP extension tool class not found — skipped.', ['class' => $class]);
                continue;
            }

            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $attribute = self::toolAttribute($method);
                if ($attribute === null) {
                    continue;
                }

                $name = ElementMetadataResolver::resolveName($method, $attribute['name']);

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
                    $tool = new ToolSchema(
                        name: $name,
                        title: $attribute['title'],
                        inputSchema: $schemaGenerator->generate($method),
                        description: ElementMetadataResolver::resolveDescription($method, $attribute['description'], $docBlockParser),
                        annotations: $attribute['annotations'],
                    );
                    $registry->registerTool($tool, [$class, $method->getName()]);

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

    /**
     * The tool attribute on a method, read from its arguments, or null when
     * the method has none.
     *
     * Read rather than instantiated: php-mcp's attribute took
     * `(name, description, annotations)` and the SDK's takes
     * `(name, title, description, annotations, …)`, so the same positional
     * call means different things, and php-mcp's annotations object is not
     * the SDK's. Named arguments carry over unchanged.
     *
     * @return array{name: ?string, title: ?string, description: ?string, annotations: ?ToolAnnotations}|null
     */
    private static function toolAttribute(\ReflectionMethod $method): ?array
    {
        foreach ($method->getAttributes() as $attribute) {
            $attributeName = ltrim($attribute->getName(), '\\');
            if (!\in_array($attributeName, PhpMcpAttributeAliases::toolAttributeNames(), true)) {
                continue;
            }

            $arguments = $attribute->getArguments();
            $isSdk = $attributeName === McpTool::class;

            $name = $arguments['name'] ?? $arguments[0] ?? null;
            $title = $arguments['title'] ?? ($isSdk ? ($arguments[1] ?? null) : null);
            $description = $arguments['description'] ?? ($isSdk ? ($arguments[2] ?? null) : ($arguments[1] ?? null));
            $annotations = $arguments['annotations'] ?? ($isSdk ? ($arguments[3] ?? null) : ($arguments[2] ?? null));

            return [
                'name' => \is_string($name) ? $name : null,
                'title' => \is_string($title) ? $title : null,
                'description' => \is_string($description) ? $description : null,
                'annotations' => $annotations instanceof ToolAnnotations ? $annotations : null,
            ];
        }

        return null;
    }
}
