<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service;

use Netzhirsch\ContaoMcpBundle\Service\AtomicFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Guards the property that replaced `LOCK_EX`: a reader never sees a partial
 * file, and nothing in the write path can block on a filesystem whose lock
 * manager does not answer. The akquinet case left a zero-byte `license.json`
 * behind — that shape must not be reachable any more.
 */
#[CoversClass(AtomicFile::class)]
final class AtomicFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'nh_atomic_'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.\DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($this->dir.\DIRECTORY_SEPARATOR.'nested')) {
            foreach (glob($this->dir.\DIRECTORY_SEPARATOR.'nested'.\DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($this->dir.\DIRECTORY_SEPARATOR.'nested');
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testWritesTheContent(): void
    {
        $path = $this->dir.\DIRECTORY_SEPARATOR.'license.json';

        self::assertTrue(AtomicFile::write($path, '{"token":"abc"}'));
        self::assertSame('{"token":"abc"}', file_get_contents($path));
    }

    public function testOverwritesAnExistingFile(): void
    {
        $path = $this->dir.\DIRECTORY_SEPARATOR.'config.json';
        file_put_contents($path, 'stale');

        self::assertTrue(AtomicFile::write($path, 'fresh'));
        self::assertSame('fresh', file_get_contents($path));
    }

    public function testCreatesTheDirectory(): void
    {
        $path = $this->dir.\DIRECTORY_SEPARATOR.'nested'.\DIRECTORY_SEPARATOR.'config.json';

        self::assertTrue(AtomicFile::write($path, 'x'));
        self::assertFileExists($path);
    }

    /**
     * A crashed write must not litter the state directory, and — more to the
     * point — the temp file must never be mistaken for the real one.
     */
    public function testLeavesNoTempFileBehind(): void
    {
        $path = $this->dir.\DIRECTORY_SEPARATOR.'license.json';
        AtomicFile::write($path, 'a');
        AtomicFile::write($path, 'b');

        $leftovers = glob($this->dir.\DIRECTORY_SEPARATOR.'*.tmp.*') ?: [];
        self::assertSame([], $leftovers);
    }

    public function testAppliesTheRequestedMode(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permission bits are not modelled on Windows.');
        }

        $path = $this->dir.\DIRECTORY_SEPARATOR.'private.pem';
        self::assertTrue(AtomicFile::write($path, 'PEM', 0o600));

        clearstatcache(true, $path);
        self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
    }

    /**
     * The destination must carry the mode from the moment it appears — a
     * private key that is briefly world-readable is a private key that leaked.
     */
    public function testTheModeIsSetBeforeTheFileAppears(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permission bits are not modelled on Windows.');
        }

        $path = $this->dir.\DIRECTORY_SEPARATOR.'key.pem';
        file_put_contents($path, 'old');
        chmod($path, 0o644);

        self::assertTrue(AtomicFile::write($path, 'new', 0o600));
        clearstatcache(true, $path);
        self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
    }

    public function testReportsFailureInsteadOfThrowing(): void
    {
        // A regular file where a directory would have to be: mkdir cannot
        // succeed, so the write has to report false rather than blow up.
        $blocker = $this->dir.\DIRECTORY_SEPARATOR.'blocker';
        file_put_contents($blocker, 'i am a file');

        self::assertFalse(AtomicFile::write($blocker.\DIRECTORY_SEPARATOR.'child.json', 'x'));
    }
}
