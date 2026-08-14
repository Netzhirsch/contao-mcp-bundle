<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Server;

use Netzhirsch\ContaoMcpBundle\Server\NullableUnionFlattener;
use Netzhirsch\ContaoMcpBundle\Tool\Page\Tool as PageTool;
use PhpMcp\Schema\Tool;
use PhpMcp\Server\Registry;
use PhpMcp\Server\Utils\DocBlockParser;
use PhpMcp\Server\Utils\SchemaGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Guards the CWA-26 rest fix: fragile MCP clients (mcp-remote, Claude Code
 * deferred-tool loader) DROP array/union `type`s from tool schemas, making the
 * param typeless client-side → its value arrives as `unknown` → -32602 on any
 * value. The flattener must rewrite every `["null", T]` union the
 * SchemaGenerator derives from `?T $x = null` params to the bare `T`, across
 * the whole registry — without touching single types, real multi-type unions
 * or required params.
 */
#[CoversClass(NullableUnionFlattener::class)]
final class NullableUnionFlattenerTest extends TestCase
{
    private NullableUnionFlattener $flattener;

    protected function setUp(): void
    {
        $this->flattener = new NullableUnionFlattener();
    }

    public function testFlattensAllNullableUnionForms(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'language' => ['type' => ['null', 'string'], 'default' => null],
                'fallback' => ['type' => ['null', 'boolean'], 'default' => null],
                'layout' => ['type' => ['null', 'integer'], 'default' => null],
                'groups' => ['type' => ['array', 'null'], 'default' => null],
                'args' => ['type' => ['null', 'object'], 'default' => null],
                'ratio' => ['type' => ['null', 'number'], 'default' => null],
            ],
            'required' => [],
        ];

        $out = $this->flattener->flattenSchema($schema);

        self::assertSame('string', $out['properties']['language']['type']);
        self::assertSame('boolean', $out['properties']['fallback']['type']);
        self::assertSame('integer', $out['properties']['layout']['type']);
        self::assertSame('array', $out['properties']['groups']['type']);
        self::assertSame('object', $out['properties']['args']['type']);
        self::assertSame('number', $out['properties']['ratio']['type']);
        // Optionality markers stay intact.
        self::assertNull($out['properties']['language']['default']);
        self::assertSame([], $out['required']);
    }

    public function testSingleTypesAndRequiredParamsUntouched(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'sorting' => ['type' => 'integer'],
            ],
            'required' => ['title', 'sorting'],
        ];

        self::assertSame($schema, $this->flattener->flattenSchema($schema));
    }

    public function testRealMultiTypeUnionIsKept(): void
    {
        // Flattening ["string","integer"] would change semantics — keep it.
        $schema = [
            'type' => 'object',
            'properties' => ['id' => ['type' => ['string', 'integer']]],
        ];

        self::assertSame(['string', 'integer'], $this->flattener->flattenSchema($schema)['properties']['id']['type']);
    }

    public function testNestedItemsAndPropertiesAreFlattened(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'list' => [
                    'type' => ['null', 'array'],
                    'items' => ['type' => ['null', 'string']],
                ],
                'shape' => [
                    'type' => ['null', 'object'],
                    'properties' => ['width' => ['type' => ['null', 'integer']]],
                ],
            ],
        ];

        $out = $this->flattener->flattenSchema($schema);

        self::assertSame('array', $out['properties']['list']['type']);
        self::assertSame('string', $out['properties']['list']['items']['type']);
        self::assertSame('object', $out['properties']['shape']['type']);
        self::assertSame('integer', $out['properties']['shape']['properties']['width']['type']);
    }

    public function testIdempotent(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['x' => ['type' => ['null', 'boolean'], 'default' => null]],
        ];

        $once = $this->flattener->flattenSchema($schema);

        self::assertSame($once, $this->flattener->flattenSchema($once));
    }

    public function testPageCreateRealSchemaIsFullyFlattened(): void
    {
        // End-to-end against php-mcp's REAL generator on the REAL tool method:
        // exactly the schema the briefing showed failing (fallback/language/
        // layout/groups as ["null", T] unions).
        $generator = new SchemaGenerator(new DocBlockParser(new NullLogger()));
        $raw = $generator->generate(new \ReflectionMethod(PageTool::class, 'create'));

        self::assertSame(['null', 'boolean'], $raw['properties']['fallback']['type'] ?? null, 'Upstream generator no longer emits the union — flattener may be obsolete.');

        $flat = $this->flattener->flattenSchema($raw);

        self::assertSame('boolean', $flat['properties']['fallback']['type']);
        self::assertSame('string', $flat['properties']['language']['type']);
        self::assertSame('integer', $flat['properties']['layout']['type']);
        self::assertSame('array', $flat['properties']['groups']['type']);

        // No union survives anywhere in the flattened page_create schema.
        foreach ($flat['properties'] as $name => $prop) {
            if (isset($prop['type'])) {
                self::assertIsString($prop['type'], "page_create.$name still carries a type array");
            }
        }

        // Required params unaffected.
        self::assertEqualsCanonicalizing(['pid', 'title', 'type', 'sorting'], $flat['required'] ?? []);
    }

    public function testFlattenRegistryRewritesToolsInPlace(): void
    {
        $registry = new Registry(new NullLogger());
        $registry->registerTool(
            Tool::make('demo_tool', [
                'type' => 'object',
                'properties' => ['flag' => ['type' => ['null', 'boolean'], 'default' => null]],
                'required' => [],
            ], 'demo'),
            handler: 'Some\\Handler',
            isManual: true,
        );

        $this->flattener->flattenRegistry($registry);

        $tool = $registry->getTool('demo_tool');
        self::assertNotNull($tool);
        self::assertSame('boolean', $tool->schema->inputSchema['properties']['flag']['type']);
        // Handler + manual flag survive the re-registration.
        self::assertSame('Some\\Handler', $tool->handler);
        self::assertTrue($tool->isManual);
    }
}
