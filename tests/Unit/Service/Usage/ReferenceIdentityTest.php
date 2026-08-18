<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service\Usage;

use Netzhirsch\ContaoMcpBundle\Service\Usage\InsertTagMap;
use Netzhirsch\ContaoMcpBundle\Service\Usage\ReferenceFieldMap;
use Netzhirsch\ContaoMcpBundle\Service\Usage\TargetResolver;
use Netzhirsch\ContaoMcpBundle\Service\Usage\UsageScanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a reference is anchored on, and therefore which operations break it.
 *
 * Renaming a file rewrites `tl_files.path` and keeps the row, the id and the
 * UUID, so `singleSRC = <uuid>` survives while `{{file::files/x.svg}}` does
 * not. Get this wrong in the permissive direction and a rename silently breaks
 * links; get it wrong in the strict direction and every rename is refused,
 * which is how a guard rail ends up switched off.
 */
#[CoversClass(ReferenceFieldMap::class)]
#[CoversClass(InsertTagMap::class)]
#[CoversClass(TargetResolver::class)]
final class ReferenceIdentityTest extends TestCase
{
    #[DataProvider('encodings')]
    public function testColumnEncodingMapsToAnIdentity(string $encoding, string $expected): void
    {
        self::assertSame($expected, ReferenceFieldMap::identityFor($encoding));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function encodings(): iterable
    {
        // Survives a rename — Contao keeps the UUID.
        yield 'singleSRC' => [ReferenceFieldMap::ENC_UUID, UsageScanner::IDENTITY_UUID];
        yield 'multiSRC' => [ReferenceFieldMap::ENC_UUID_LIST, UsageScanner::IDENTITY_UUID];
        // Breaks when the template name changes.
        yield 'customTpl' => [ReferenceFieldMap::ENC_TEMPLATE_NAME, UsageScanner::IDENTITY_NAME];
        // Record ids, unaffected by file operations.
        yield 'jumpTo' => [ReferenceFieldMap::ENC_INT, UsageScanner::IDENTITY_ID];
        yield 'page list' => [ReferenceFieldMap::ENC_INT_LIST, UsageScanner::IDENTITY_ID];
        yield 'image size' => [ReferenceFieldMap::ENC_IMAGE_SIZE, UsageScanner::IDENTITY_ID];
        yield 'layout modules' => [ReferenceFieldMap::ENC_MODULE_WIZARD, UsageScanner::IDENTITY_ID];
    }

    #[DataProvider('templatePaths')]
    public function testTemplateNameDerivation(string $path, ?string $expected): void
    {
        self::assertSame($expected, TargetResolver::templateNameFor($path));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function templatePaths(): iterable
    {
        yield 'legacy at root' => ['ce_text_my.html5', 'ce_text_my'];
        // The reason a folder move of a .html5 breaks nothing: Contao finds
        // legacy templates by basename, wherever they sit.
        yield 'legacy in a folder' => ['some/folder/ce_text_my.html5', 'ce_text_my'];
        yield 'twig keeps its path' => ['content_element/text/my.html.twig', 'content_element/text/my'];
        yield 'templates prefix accepted' => ['templates/ce_text_my.html5', 'ce_text_my'];
        yield 'backslashes normalised' => ['content_element\\text\\my.html.twig', 'content_element/text/my'];
        yield 'not a template' => ['notes.md', null];
    }

    /**
     * Moving `a/x.html5` to `b/x.html5` leaves the name `x` — nothing that
     * references it notices, so the guard must not refuse the move.
     */
    public function testLegacyFolderMoveKeepsTheName(): void
    {
        self::assertSame(
            TargetResolver::templateNameFor('a/x.html5'),
            TargetResolver::templateNameFor('b/x.html5'),
        );
    }

    public function testTwigFolderMoveChangesTheName(): void
    {
        self::assertNotSame(
            TargetResolver::templateNameFor('a/x.html.twig'),
            TargetResolver::templateNameFor('b/x.html.twig'),
        );
    }

    /**
     * A folder is spelled as the prefix of the paths inside it, so
     * `{{file::files/theme/logo.svg}}` is a reference to `files/theme`.
     */
    public function testFolderTargetMatchesPathsBelowIt(): void
    {
        $tags = InsertTagMap::tagsFor('tl_files');
        $text = '<img src="{{file::files/theme/logo.svg}}">';

        self::assertSame(1, preg_match(InsertTagMap::pattern($tags, 'files/theme', true), $text));
        // Off by default: for a FILE target a trailing slash would mean a
        // different resource entirely.
        self::assertSame(0, preg_match(InsertTagMap::pattern($tags, 'files/theme', false), $text));
    }

    public function testFolderSuffixDoesNotMatchASiblingFolder(): void
    {
        $tags = InsertTagMap::tagsFor('tl_files');

        self::assertSame(
            0,
            preg_match(InsertTagMap::pattern($tags, 'files/theme', true), '{{file::files/theme2/logo.svg}}'),
        );
    }
}
