<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\DependencyInjection\Compiler;

use Netzhirsch\ContaoMcpBundle\Server\HttpDispatcherFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Collects third-party MCP tool providers and hands their class names to the
 * {@see HttpDispatcherFactory}.
 *
 * A tool provider is any service tagged `netzhirsch_mcp.tool`. Bundles get
 * the tag automatically by implementing
 * {@see \Netzhirsch\ContaoMcpBundle\Extension\McpToolProviderInterface}
 * (via `registerForAutoconfiguration` in
 * {@see \Netzhirsch\ContaoMcpBundle\ContaoMcpBundle::build()}), or they can
 * add the tag by hand.
 *
 * Two things happen here, both required for the tool to actually work:
 *
 *   1. The tagged service is forced PUBLIC. php-mcp/server resolves a tool's
 *      handler instance through the PSR-11 container by its FQCN at call
 *      time; Symfony only exposes public services that way. (The core tools
 *      get this from the `Tool\` wildcard in services.yaml; extension tools
 *      live in other bundles, so we apply it here.)
 *
 *   2. The collected FQCN list is injected into the factory's
 *      `$extensionToolClasses` argument. The factory reflects each class for
 *      `#[McpTool]` methods AFTER core discovery and registers the
 *      operator-allowlisted ones.
 *
 * No allowlist filtering happens here — collection is build-time, the
 * allowlist is a runtime config value (`extension_tools_enabled`) read on
 * each server boot. Keeping them separate means an operator can enable a
 * tool without a container rebuild.
 */
final class McpToolProviderPass implements CompilerPassInterface
{
    public const TAG = 'netzhirsch_mcp.tool';

    public const PARAM = 'netzhirsch_contao_mcp.extension_tool_classes';

    public function process(ContainerBuilder $container): void
    {
        $classes = [];

        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $definition = $container->findDefinition($id);

            // Required so php-mcp's container->get(FQCN) can resolve the
            // handler instance (with all autowiring/#[Required] applied).
            $definition->setPublic(true);

            $class = $definition->getClass() ?? $id;
            if (str_contains($class, '%')) {
                /** @var string $class */
                $class = $container->getParameterBag()->resolveValue($class);
            }
            if ($class !== '') {
                // Dedupe via keys; a class could be tagged + interface-auto-
                // configured and show up twice.
                $classes[$class] = true;
            }
        }

        $classes = array_keys($classes);
        sort($classes); // deterministic order → stable registration + cache

        // Expose as a parameter for introspection / debugging.
        $container->setParameter(self::PARAM, $classes);

        // Inject into the factory if it is present (it always is in the real
        // container; guarded for isolated test containers).
        if ($container->hasDefinition(HttpDispatcherFactory::class)) {
            $container->getDefinition(HttpDispatcherFactory::class)
                ->setArgument('$extensionToolClasses', $classes);
        }
    }
}
