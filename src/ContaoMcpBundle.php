<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle;

use Netzhirsch\ContaoMcpBundle\DependencyInjection\Compiler\McpToolProviderPass;
use Netzhirsch\ContaoMcpBundle\Extension\McpToolProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class ContaoMcpBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    /**
     * Wire up the third-party tool extension point:
     *
     *   - Any service implementing {@see McpToolProviderInterface} is
     *     auto-tagged `netzhirsch_mcp.tool` (requires `autoconfigure: true`
     *     on the consuming service — the Symfony bundle default). Bundles
     *     that don't autoconfigure can add the tag manually instead.
     *   - {@see McpToolProviderPass} collects the tagged services, marks them
     *     public, and feeds their class names to the HttpDispatcherFactory.
     *
     * Tool *registration* is still gated at runtime by the
     * `extension_tools_enabled` allowlist — tagging alone never makes a tool
     * callable. See EXTENDING.md.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerForAutoconfiguration(McpToolProviderInterface::class)
            ->addTag(McpToolProviderPass::TAG);

        $container->addCompilerPass(new McpToolProviderPass());
    }
}
