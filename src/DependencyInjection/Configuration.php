<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('netzhirsch_contao_mcp');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('write')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('default_author_id')
                            ->defaultNull()
                            ->info('tl_user.id used as author for MCP write tools when no explicit author_id is passed. Falls back to the lowest-id admin user if null.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('preview')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('basic_auth')
                            ->defaultValue('%env(default::MCP_PREVIEW_BASIC_AUTH)%')
                            ->info('Credentials as "user:pass" for page_preview when the site sits behind HTTP basic auth (typical staging protection). Defaults to the MCP_PREVIEW_BASIC_AUTH env var; leave unset for sites without basic auth. Belongs in .env.local, never in the repository.')
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
