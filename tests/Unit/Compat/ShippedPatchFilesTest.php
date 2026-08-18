<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Compat;

use PHPUnit\Framework\TestCase;

/**
 * The patch files are dead weight for this bundle — and MUST ship anyway
 * until 2.0.0.
 *
 * Every installation up to 1.4.0 was told to put an `extra.patches` block in
 * its root `composer.json` pointing at
 * `vendor/netzhirsch/contao-mcp-bundle/patches/*.patch`. That block lives in
 * the host project, so we cannot remove it for them.
 *
 * Deleting the files does not degrade gracefully: `cweagans/composer-patches`
 * treats a non-existent local path as a URL, hands `null` to
 * `RemoteFilesystem::copy()` and dies with a `TypeError`. A `TypeError` is an
 * `\Error`, so the `catch (\Exception)` that normally prints "Could not apply
 * patch! Skipping." never runs — `composer install` aborts with exit 1 and
 * leaves the project without `vendor/autoload.php`. Verified against
 * composer-patches 1.7.3 / Composer 2.
 *
 * So: keep them, keep them applying, drop them in 2.0.0.
 */
final class ShippedPatchFilesTest extends TestCase
{
    private const PATCH_DIR = __DIR__.'/../../../patches';

    /**
     * @return iterable<string, array{string}>
     */
    public static function patchFiles(): iterable
    {
        yield 'transport' => ['transport-auth-and-oauth-metadata.patch'];
        yield 'dispatcher' => ['dispatcher-tool-filter.patch'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('patchFiles')]
    public function testPatchFileStillShips(string $file): void
    {
        $path = self::PATCH_DIR.'/'.$file;

        self::assertFileExists(
            $path,
            "patches/$file is referenced by the root composer.json of every ≤1.4.0 install. "
            .'Removing it breaks their `composer install` with exit 1. Drop it in 2.0.0, not before.',
        );

        $contents = (string) file_get_contents($path);

        // An empty or truncated file is as fatal as a missing one: `patch`
        // rejects it ("Only garbage was found in the patch input").
        self::assertNotSame('', trim($contents), "patches/$file must not be empty.");
        self::assertStringContainsString('--- a/src/', $contents, "patches/$file lost its -p1 header.");
        self::assertStringContainsString('@@', $contents, "patches/$file has no hunks left.");
    }

    /**
     * `.gitattributes` decides what ends up in the dist archive Composer
     * downloads. An `export-ignore` on `patches/` would pass every test here
     * and still ship a package without the files.
     */
    public function testPatchesAreNotExcludedFromTheDistArchive(): void
    {
        $gitattributes = (string) file_get_contents(__DIR__.'/../../../.gitattributes');

        self::assertDoesNotMatchRegularExpression(
            '#^\s*/?patches/?\s+export-ignore#m',
            $gitattributes,
            'patches/ must stay in the dist archive — the paths in every ≤1.4.0 root composer.json point into it.',
        );
    }
}
