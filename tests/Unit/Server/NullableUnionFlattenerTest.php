<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Server;

use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use Mcp\Capability\Registry;
use Mcp\Schema\Tool;
use Netzhirsch\ContaoMcpBundle\Server\NullableUnionFlattener;
use Netzhirsch\ContaoMcpBundle\Tool\Page\Tool as PageTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
        // End-to-end against the SDK's REAL generator on the REAL tool method:
        // exactly the schema the briefing showed failing (fallback/language/
        // layout/groups as ["null", T] unions).
        $generator = new SchemaGenerator(new DocBlockParser());
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
        $registry = new Registry();
        $registry->registerTool(
            new Tool('demo_tool', 'Demo', [
                'type' => 'object',
                'properties' => ['flag' => ['type' => ['null', 'boolean'], 'default' => null]],
                'required' => [],
            ], 'demo', null),
            'Some\\Handler',
        );

        $this->flattener->flattenRegistry($registry);

        $tool = $registry->getTool('demo_tool');
        self::assertSame('boolean', $tool->tool->inputSchema['properties']['flag']['type']);
        // Handler and the rest of the definition survive the re-registration.
        self::assertSame('Some\\Handler', $tool->handler);
        self::assertSame('Demo', $tool->tool->title);
        self::assertSame('demo', $tool->tool->description);
    }
}
