<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Backend;

use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip coverage for the two tool lists the Backend tool panel manages.
 * The rest of the config fields have been stable since v0.3 — these tests
 * pin the parts the panel depends on: persistence, sanitisation and the
 * absent-input → empty-list rule that makes pass-through mandatory.
 */
#[CoversClass(McpServerConfigStorage::class)]
final class McpServerConfigStorageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'mcp-config-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $file = $this->dir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'mcp'.\DIRECTORY_SEPARATOR.'config.json';
        @unlink($file);
        @rmdir(\dirname($file));
        @rmdir(\dirname($file, 2));
        @rmdir($this->dir);
    }

    public function testDisabledToolsDefaultsToEmptyList(): void
    {
        $storage = new McpServerConfigStorage($this->dir);

        self::assertSame([], $storage->defaults()['disabled_tools']);
        self::assertSame([], $storage->load()['disabled_tools']);
    }

    public function testDisabledToolsRoundTrip(): void
    {
        $storage = new McpServerConfigStorage($this->dir);

        $result = $storage->save([
            ...$storage->defaults(),
            'disabled_tools' => ['news_delete', 'url_rewrite_delete', '  ', 'news_delete'],
            'extension_tools_enabled' => ['acme_invoice_get'],
        ]);

        self::assertTrue($result['saved']);
        // Trimmed, de-duplicated.
        self::assertSame(['news_delete', 'url_rewrite_delete'], $result['values']['disabled_tools']);

        $loaded = $storage->load();
        self::assertSame(['news_delete', 'url_rewrite_delete'], $loaded['disabled_tools']);
        self::assertSame(['acme_invoice_get'], $loaded['extension_tools_enabled']);
    }

    public function testAbsentInputResetsBothLists(): void
    {
        // Documents WHY handleSaveConfig must pass the stored lists through:
        // saving without them wipes both.
        $storage = new McpServerConfigStorage($this->dir);
        $storage->save([
            ...$storage->defaults(),
            'disabled_tools' => ['news_delete'],
        ]);

        $storage->save($storage->defaults());

        self::assertSame([], $storage->load()['disabled_tools']);
    }

    public function testRegistrationOpenUntilRoundTrip(): void
    {
        $storage = new McpServerConfigStorage($this->dir);

        self::assertSame(0, $storage->defaults()['registration_open_until']);

        $until = time() + 600;
        $result = $storage->save([...$storage->defaults(), 'registration_open_until' => $until]);

        self::assertTrue($result['saved']);
        self::assertSame($until, $storage->load()['registration_open_until']);

        // Closing the window persists 0 again.
        $storage->save([...$storage->load(), 'registration_open_until' => 0]);
        self::assertSame(0, $storage->load()['registration_open_until']);
    }

    public function testRegistrationOpenUntilSanitisesGarbage(): void
    {
        $storage = new McpServerConfigStorage($this->dir);
        $dir = $this->dir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'mcp';
        mkdir($dir, 0o775, true);
        file_put_contents($dir.\DIRECTORY_SEPARATOR.'config.json', json_encode([
            'registration_open_until' => 'not-a-timestamp',
        ]));

        self::assertSame(0, $storage->load()['registration_open_until']);

        // Negative values clamp to closed as well.
        file_put_contents($dir.\DIRECTORY_SEPARATOR.'config.json', json_encode([
            'registration_open_until' => -5,
        ]));
        self::assertSame(0, $storage->load()['registration_open_until']);
    }

    public function testGarbageInConfigFileIsSanitised(): void
    {
        $storage = new McpServerConfigStorage($this->dir);
        $dir = $this->dir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'mcp';
        mkdir($dir, 0o775, true);
        file_put_contents($dir.\DIRECTORY_SEPARATOR.'config.json', json_encode([
            'disabled_tools' => 'not-a-list',
            'extension_tools_enabled' => [42, null, 'ok_tool'],
        ]));

        $loaded = $storage->load();

        self::assertSame([], $loaded['disabled_tools']);
        self::assertSame(['ok_tool'], $loaded['extension_tools_enabled']);
    }
}
