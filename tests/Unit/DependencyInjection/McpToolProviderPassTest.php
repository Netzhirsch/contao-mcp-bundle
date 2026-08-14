<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\DependencyInjection;

use Netzhirsch\ContaoMcpBundle\DependencyInjection\Compiler\McpToolProviderPass;
use Netzhirsch\ContaoMcpBundle\Server\HttpDispatcherFactory;
use Netzhirsch\ContaoMcpBundle\Tests\Fixtures\Extension\GreetingTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoversClass(McpToolProviderPass::class)]
final class McpToolProviderPassTest extends TestCase
{
    public function testCollectsTaggedServicesMakesThemPublicAndFeedsTheFactory(): void
    {
        $container = new ContainerBuilder();

        // Stand-in for the real factory — the pass only ever setArgument()s it.
        $container->register(HttpDispatcherFactory::class, HttpDispatcherFactory::class)
            ->setArgument('$extensionToolClasses', []);

        // A third-party tool service: private + tagged (mirrors what an
        // extension bundle registers, or what autoconfiguration produces).
        $container->register(GreetingTool::class, GreetingTool::class)
            ->setPublic(false)
            ->addTag(McpToolProviderPass::TAG);

        (new McpToolProviderPass())->process($container);

        // 1. Forced public so php-mcp can container->get(FQCN) the handler.
        self::assertTrue(
            $container->getDefinition(GreetingTool::class)->isPublic(),
            'Tagged tool services must be made public.',
        );

        // 2. Collected into the introspection parameter.
        self::assertSame(
            [GreetingTool::class],
            $container->getParameter(McpToolProviderPass::PARAM),
        );

        // 3. Injected into the factory argument.
        self::assertSame(
            [GreetingTool::class],
            $container->getDefinition(HttpDispatcherFactory::class)->getArgument('$extensionToolClasses'),
        );
    }

    public function testNoTaggedServicesYieldsEmptyList(): void
    {
        $container = new ContainerBuilder();
        $container->register(HttpDispatcherFactory::class, HttpDispatcherFactory::class)
            ->setArgument('$extensionToolClasses', []);

        (new McpToolProviderPass())->process($container);

        self::assertSame([], $container->getParameter(McpToolProviderPass::PARAM));
        self::assertSame(
            [],
            $container->getDefinition(HttpDispatcherFactory::class)->getArgument('$extensionToolClasses'),
        );
    }

    public function testDeterministicSortedOrder(): void
    {
        $container = new ContainerBuilder();
        $container->register(HttpDispatcherFactory::class, HttpDispatcherFactory::class)
            ->setArgument('$extensionToolClasses', []);

        // Register two providers out of alphabetical order.
        $container->register('z_tool', GreetingTool::class)->addTag(McpToolProviderPass::TAG);
        $container->register('a_tool', \stdClass::class)->addTag(McpToolProviderPass::TAG);

        (new McpToolProviderPass())->process($container);

        $classes = $container->getParameter(McpToolProviderPass::PARAM);
        $sorted = $classes;
        sort($sorted);
        self::assertSame($sorted, $classes, 'Collected classes must be in deterministic sorted order.');
    }
}
