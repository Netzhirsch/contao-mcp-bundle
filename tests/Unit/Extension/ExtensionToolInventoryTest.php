<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Extension;

use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\Extension\ExtensionToolInventory;
use Netzhirsch\ContaoMcpBundle\Server\ExtensionToolRegistrar;
use PhpMcp\Server\Attributes\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A disabled extension tool is absent from tools/list by design, which from
 * the outside is indistinguishable from a tool that was never written. That
 * ambiguity was reported to a customer as "this cannot be done through MCP"
 * while the tools sat there, installed and switched off.
 *
 * So the inventory has one job: say that they exist AND that they are off.
 */
#[CoversClass(ExtensionToolInventory::class)]
final class ExtensionToolInventoryTest extends TestCase
{
    private function inventory(string $enabledJson): ExtensionToolInventory
    {
        $projectDir = sys_get_temp_dir().'/mcp-eti-'.bin2hex(random_bytes(5));
        mkdir($projectDir.'/var/mcp', 0o777, true);
        file_put_contents($projectDir.'/var/mcp/config.json', $enabledJson);

        return new ExtensionToolInventory(
            new ExtensionToolRegistrar(new NullLogger()),
            new McpServerConfigStorage($projectDir),
            [ExtensionToolInventoryTestTools::class],
        );
    }

    public function testToolsAreListedWithTheirEnabledState(): void
    {
        $inventory = $this->inventory((string) json_encode(['extension_tools_enabled' => ['component_instance_update']]));

        $tools = $inventory->all();

        self::assertSame(
            ['component_instance_create', 'component_instance_update'],
            array_column($tools, 'name'),
            'sorted by name, so a listing does not reshuffle between calls',
        );
        self::assertFalse($tools[0]['enabled']);
        self::assertTrue($tools[1]['enabled']);
        self::assertSame(['component_instance_create'], $inventory->disabledNames());
    }

    /**
     * The state of a fresh install: tools present, allowlist empty. This is
     * exactly the situation that produced the wrong answer in the field.
     */
    public function testWithAnEmptyAllowlistEverythingIsReportedAsDisabled(): void
    {
        $inventory = $this->inventory((string) json_encode(['auth_mode' => 'oauth']));

        self::assertSame(
            ['component_instance_create', 'component_instance_update'],
            $inventory->disabledNames(),
        );
    }

    /**
     * config.json is operator-editable, so the allowlist can be anything.
     * Nothing here may throw — this runs inside a tool call.
     */
    public function testAMalformedAllowlistDoesNotBreakTheListing(): void
    {
        $inventory = $this->inventory((string) json_encode(['extension_tools_enabled' => ['component_instance_create', 5, ['nested']]]));

        $tools = $inventory->all();

        self::assertCount(2, $tools);
        self::assertTrue($tools[0]['enabled'], 'the one valid entry still counts');
        self::assertSame(['component_instance_update'], $inventory->disabledNames());
    }

    public function testAnInstallationWithoutExtensionToolsListsNothing(): void
    {
        $projectDir = sys_get_temp_dir().'/mcp-eti-'.bin2hex(random_bytes(5));
        mkdir($projectDir.'/var/mcp', 0o777, true);
        file_put_contents($projectDir.'/var/mcp/config.json', '{}');

        $inventory = new ExtensionToolInventory(
            new ExtensionToolRegistrar(new NullLogger()),
            new McpServerConfigStorage($projectDir),
            [],
        );

        self::assertSame([], $inventory->all());
        self::assertSame([], $inventory->disabledNames());
    }
}

/**
 * Stand-in for a third-party tool provider — two `#[McpTool]` methods, declared
 * the way an extension declares them.
 */
final class ExtensionToolInventoryTestTools
{
    #[McpTool(name: 'component_instance_update', description: 'Updates one component instance.')]
    public function update(int $id): array
    {
        return ['id' => $id];
    }

    #[McpTool(name: 'component_instance_create', description: 'Creates a component instance.')]
    public function create(): array
    {
        return [];
    }
}
