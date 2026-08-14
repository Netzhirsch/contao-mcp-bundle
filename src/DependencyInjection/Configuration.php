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
            ->end()
        ;

        return $treeBuilder;
    }
}
