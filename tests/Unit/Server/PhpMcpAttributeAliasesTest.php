<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Server;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use Netzhirsch\ContaoMcpBundle\Server\PhpMcpAttributeAliases;
use Netzhirsch\ContaoMcpBundle\Tests\Fixtures\Extension\GreetingTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * An extension bundle written before 1.41 imports php-mcp's attribute names.
 * Once aliased, the SDK — which looks its attributes up with IS_INSTANCEOF —
 * reads them as its own, so neither the tool nor a `#[Schema]` on one of its
 * parameters is lost.
 */
#[CoversClass(PhpMcpAttributeAliases::class)]
final class PhpMcpAttributeAliasesTest extends TestCase
{
    public function testTheOldToolAttributeIsReadAsTheSdkOne(): void
    {
        PhpMcpAttributeAliases::register();

        $method = new \ReflectionMethod(GreetingTool::class, 'greet');

        self::assertCount(1, $method->getAttributes(McpTool::class, \ReflectionAttribute::IS_INSTANCEOF));
        self::assertInstanceOf(McpTool::class, $method->getAttributes(McpTool::class, \ReflectionAttribute::IS_INSTANCEOF)[0]->newInstance());
    }

    public function testTheOldSchemaAttributeShapesTheGeneratedSchema(): void
    {
        PhpMcpAttributeAliases::register();

        $schema = (new SchemaGenerator(new DocBlockParser()))->generate(new \ReflectionMethod(LegacySchemaTool::class, 'run'));

        self::assertSame('object', $schema['properties']['options']['type'] ?? null);
        self::assertSame('Settings for the run.', $schema['properties']['options']['description'] ?? null);
    }

    public function testRegisteringTwiceIsHarmless(): void
    {
        PhpMcpAttributeAliases::register();
        PhpMcpAttributeAliases::register();

        self::assertTrue(class_exists('PhpMcp\\Server\\Attributes\\McpTool'));
    }
}

/**
 * An extension tool from before 1.41, parameter schema and all.
 */
final class LegacySchemaTool
{
    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    #[\PhpMcp\Server\Attributes\McpTool(name: 'legacy_run', description: 'Runs.')]
    public function run(
        #[\PhpMcp\Server\Attributes\Schema(type: 'object', description: 'Settings for the run.')]
        array $options,
    ): array {
        return $options;
    }
}
