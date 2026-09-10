<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Backend;

use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The default for `auth_mode` is "none", and every error path in load() used to
 * return the defaults. So a config file that existed but could not be read —
 * a restore without var/mcp, lost permissions, a full disk mid-save — turned an
 * OAuth-protected server into an open one with ~186 tools, silently.
 *
 * Fail-closed means: a file that is there and unusable is an error, not an
 * absence.
 */
#[CoversClass(McpServerConfigStorage::class)]
final class McpServerConfigStorageFailClosedTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/mcp-cfg-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/var/mcp', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/var/mcp/config.json', '/var/mcp', '/var', ''] as $part) {
            $path = $this->projectDir.$part;
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
    }

    private function write(string $contents): void
    {
        file_put_contents($this->projectDir.'/var/mcp/config.json', $contents);
    }

    private function storage(): McpServerConfigStorage
    {
        return new McpServerConfigStorage($this->projectDir);
    }

    /**
     * Nothing writes config.json at install time — it appears the first time
     * somebody saves the backend form. So "no file" is the state a freshly
     * installed, licensed instance is in, and defaulting to `none` there meant
     * every tool was served at /mcp unauthenticated until an operator happened
     * to open the configuration.
     */
    public function testAFreshInstallDefaultsToOauthAndSaysItIsUnconfigured(): void
    {
        $config = $this->storage()->load();

        self::assertSame('oauth', $config['auth_mode']);
        self::assertSame('missing', $config['config_state']);
    }

    /**
     * The three broken-config states are told apart, because "you have not set
     * this up yet" and "your file is corrupt" need different answers.
     */
    public function testTheConfigStatesAreDistinguishable(): void
    {
        $this->write('{"auth_mode":"oauth","backend_ur');
        self::assertSame('invalid', $this->storage()->load()['config_state']);

        $this->write(json_encode(['auth_mode' => 'oauth']));
        self::assertArrayNotHasKey('config_state', $this->storage()->load());
    }

    public function testAValidConfigIsReadAsBefore(): void
    {
        $this->write(json_encode(['auth_mode' => 'oauth', 'backend_url' => 'https://example.com']));
        $config = $this->storage()->load();

        self::assertSame('oauth', $config['auth_mode']);
        self::assertSame('https://example.com', $config['backend_url']);
        self::assertArrayNotHasKey('config_error', $config);
    }

    public function testTruncatedJsonIsAnErrorRatherThanNoAuth(): void
    {
        // What a save() interrupted by a full disk leaves behind.
        $this->write('{"auth_mode":"oauth","backend_ur');
        $config = $this->storage()->load();

        self::assertArrayHasKey('config_error', $config);
        self::assertStringContainsString('not valid JSON', $config['config_error']);
    }

    public function testAnEmptyFileIsAnErrorToo(): void
    {
        $this->write('');

        self::assertArrayHasKey('config_error', $this->storage()->load());
    }

    /**
     * The subtler one: the file parses, but the value is not a mode we know.
     * Clamping it to the default would have disabled authentication because of
     * a trailing space.
     */
    public function testAnUnrecognisedAuthModeIsAnErrorRatherThanNone(): void
    {
        $this->write(json_encode(['auth_mode' => 'oauth ']));
        $config = $this->storage()->load();

        self::assertArrayHasKey('config_error', $config);
        self::assertStringContainsString('auth_mode', $config['config_error']);
    }

    /**
     * An explicit `none` is a decision an operator made, and stays one.
     */
    public function testAnExplicitNoneIsHonoured(): void
    {
        $this->write(json_encode(['auth_mode' => 'none']));
        $config = $this->storage()->load();

        self::assertSame('none', $config['auth_mode']);
        self::assertArrayNotHasKey('config_error', $config);
    }
}
