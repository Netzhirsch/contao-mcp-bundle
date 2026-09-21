<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service;

use Netzhirsch\ContaoMcpBundle\Service\FilePermissions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;

/**
 * chmod() asks; it does not report. On Windows it is a no-op, on some mounts
 * it is ignored, and it fails when the web-server user does not own the file —
 * and each of those leaves a key readable while the writing code believes the
 * opposite.
 */
#[CoversClass(FilePermissions::class)]
final class FilePermissionsTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    private function fileWithMode(int $mode): string
    {
        $path = sys_get_temp_dir().'/mcp-perm-'.bin2hex(random_bytes(5));
        file_put_contents($path, 'secret');
        chmod($path, $mode);
        $this->paths[] = $path;

        return $path;
    }

    public function testAMissingFileIsNotAProblemToReport(): void
    {
        self::assertNull(FilePermissions::tooOpen(sys_get_temp_dir().'/mcp-does-not-exist-'.bin2hex(random_bytes(4))));
    }

    /**
     * NTFS permissions are real, but they are not what fileperms() reports.
     * Warning there would fire on every file of every install and teach the
     * operator to ignore the warning.
     */
    #[RequiresOperatingSystemFamily('Windows')]
    public function testWindowsStaysSilent(): void
    {
        self::assertNull(FilePermissions::tooOpen($this->fileWithMode(0o666)));
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testAPrivateFilePasses(): void
    {
        self::assertNull(FilePermissions::tooOpen($this->fileWithMode(0o600)));
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testAWorldReadableFileIsNamedAsSuch(): void
    {
        $problem = FilePermissions::tooOpen($this->fileWithMode(0o644));

        self::assertNotNull($problem);
        self::assertStringContainsString('0644', $problem);
        self::assertStringContainsString('every account on this host', $problem);
        self::assertStringContainsString('chmod 0600', $problem);
    }

    /**
     * Group-readable is a finding too — on shared hosting the group is often
     * where the neighbour lives — but it is not the same sentence.
     */
    #[RequiresOperatingSystemFamily('Linux')]
    public function testGroupReadableIsReportedWithoutTheWorldWording(): void
    {
        $problem = FilePermissions::tooOpen($this->fileWithMode(0o640));

        self::assertNotNull($problem);
        self::assertStringNotContainsString('every account on this host', $problem);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testTheAcceptableModeIsConfigurable(): void
    {
        $path = $this->fileWithMode(0o644);

        self::assertNull(FilePermissions::tooOpen($path, 0o644), 'a public key may be 0644');
        self::assertNotNull(FilePermissions::tooOpen($path, 0o600));
    }
}
