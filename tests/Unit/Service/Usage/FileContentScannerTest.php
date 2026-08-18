<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service\Usage;

use Netzhirsch\ContaoMcpBundle\Service\Usage\FileContentScanner;
use Netzhirsch\ContaoMcpBundle\Service\Usage\UsageScanner;
use Netzhirsch\ContaoMcpBundle\Service\Usage\UsageTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * References that live inside files — the half the database cannot see.
 *
 * Needs nothing but a filesystem, so unlike the smoke test this runs in CI,
 * which matters: these are the rules that decide whether a deletion is
 * refused, and both kinds of mistake are expensive. A missed `@import` lets
 * the AI delete a partial that three stylesheets need; an over-eager match
 * blocks a cleanup that was perfectly fine.
 */
#[CoversClass(FileContentScanner::class)]
final class FileContentScannerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'mcp-usage-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/files/theme', 0o775, true);
        mkdir($this->dir.'/templates', 0o775, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->dir);
    }

    // ───────────────────────────── files ─────────────────────────────

    /**
     * The case that motivated the whole file scan: an SCSS partial is
     * imported by a name that shares no substring with its file name —
     * `_colors.scss` becomes `@import 'colors'`.
     */
    public function testResolvesScssPartialImportedByName(): void
    {
        $this->write('files/theme/_colors.scss', '$brand: #c00;');
        $this->write('files/theme/app.scss', "@import 'colors';");

        $references = $this->scanFile('files/theme/_colors.scss');

        self::assertCount(1, $references);
        self::assertSame('files/theme/app.scss', $references[0]['file']);
        self::assertTrue(UsageScanner::blocks($references[0]));
    }

    public function testResolvesScssUseAndForward(): void
    {
        $this->write('files/theme/_colors.scss', '$brand: #c00;');
        $this->write('files/theme/a.scss', "@use 'colors';");
        $this->write('files/theme/b.scss', "@forward 'colors';");

        self::assertCount(2, $this->scanFile('files/theme/_colors.scss'));
    }

    public function testResolvesMultiImportStatement(): void
    {
        $this->write('files/theme/_colors.scss', '$brand: #c00;');
        $this->write('files/theme/app.scss', "@import 'reset', 'colors', 'type';");

        self::assertCount(1, $this->scanFile('files/theme/_colors.scss'));
    }

    public function testFindsLiteralPathInATemplate(): void
    {
        $this->write('files/theme/logo.svg', '<svg/>');
        $this->write('templates/fe_page.html5', '<img src="<?= $base ?>files/theme/logo.svg">');

        $references = $this->scanFile('files/theme/logo.svg');

        self::assertCount(1, $references);
        self::assertTrue(UsageScanner::blocks($references[0]));
        self::assertStringContainsString('logo.svg', $references[0]['snippet']);
    }

    public function testFindsTheUuidSpelling(): void
    {
        $this->write('files/theme/logo.svg', '<svg/>');
        $this->write('templates/fe_page.html5', '{{file::a1b2c3d4-0000-1111-2222-333344445555}}');

        $references = $this->scanFile('files/theme/logo.svg', 'a1b2c3d4-0000-1111-2222-333344445555');

        self::assertCount(1, $references);
    }

    /**
     * A word that happens to match is not a dependency. It is still reported,
     * because a human may want to look — but it must never refuse a deletion.
     */
    public function testBareNameMentionIsReportedButDoesNotBlock(): void
    {
        $this->write('files/theme/logo.svg', '<svg/>');
        $this->write('templates/notes.md', 'We should redesign logo.svg at some point.');

        $references = $this->scanFile('files/theme/logo.svg');

        self::assertCount(1, $references);
        self::assertSame(UsageScanner::CONFIDENCE_POSSIBLE, $references[0]['confidence']);
        self::assertFalse(UsageScanner::blocks($references[0]));
    }

    public function testUnrelatedContentIsNotAReference(): void
    {
        $this->write('files/theme/_colors.scss', '$brand: #c00;');
        $this->write('files/theme/unrelated.scss', '.colors { color: red; }');

        self::assertSame([], $this->scanFile('files/theme/_colors.scss'));
    }

    public function testAFileIsNotItsOwnReferrer(): void
    {
        $this->write('files/theme/app.scss', "// files/theme/app.scss\n@import 'app';");

        self::assertSame([], $this->scanFile('files/theme/app.scss'));
    }

    /**
     * Deleting a folder deletes what is inside it, so a stylesheet in that
     * folder importing a sibling is not a reason to keep the folder.
     */
    public function testFilesInsideTheTargetFolderAreNotReferrers(): void
    {
        $this->write('files/theme/_colors.scss', '$brand: #c00;');
        $this->write('files/theme/app.scss', "@import 'colors';");

        $target = new UsageTarget(
            type: 'folder',
            table: 'tl_files',
            id: 1,
            label: 'theme',
            path: 'files/theme',
            isFolder: true,
        );

        self::assertSame([], (new FileContentScanner($this->dir))->scan($target, 50)['references']);
    }

    public function testBinaryFilesAreNotRead(): void
    {
        $this->write('files/theme/logo.svg', '<svg/>');
        // A .jpg is not in the text allowlist, so its bytes are never scanned.
        $this->write('files/theme/photo.jpg', 'files/theme/logo.svg');

        self::assertSame([], $this->scanFile('files/theme/logo.svg'));
    }

    public function testRespectsTheLimit(): void
    {
        $this->write('files/theme/_colors.scss', '$brand: #c00;');

        foreach (range(1, 5) as $i) {
            $this->write("files/theme/app{$i}.scss", "@import 'colors';");
        }

        self::assertCount(2, (new FileContentScanner($this->dir))->scan($this->fileTarget('files/theme/_colors.scss'), 2)['references']);
        self::assertSame([], (new FileContentScanner($this->dir))->scan($this->fileTarget('files/theme/_colors.scss'), 0)['references']);
    }

    // ─────────────────────────── templates ───────────────────────────

    public function testFindsLegacyExtendAndInsert(): void
    {
        $this->write('templates/parent.html5', '<?php $this->block("x"); ?>');
        $this->write('templates/child.html5', '<?php $this->extend(\'parent\'); ?>');
        $this->write('templates/other.html5', '<?php $this->insert("parent"); ?>');

        $references = $this->scanTemplate('templates/parent.html5', 'parent');

        self::assertCount(2, $references);
        self::assertTrue(UsageScanner::blocks($references[0]));
    }

    public function testFindsTwigExtendsIncludeEmbedAndUse(): void
    {
        $this->write('templates/base.html.twig', '{% block x %}{% endblock %}');
        $this->write('templates/a.html.twig', '{% extends "@Contao/base.html.twig" %}');
        $this->write('templates/b.html.twig', "{% include 'base' %}");
        $this->write('templates/c.html.twig', '{% embed "@Contao/base.html.twig" %}{% endembed %}');
        $this->write('templates/d.html.twig', '{{ include("@Contao/base.html.twig") }}');

        self::assertCount(4, $this->scanTemplate('templates/base.html.twig', 'base'));
    }

    /**
     * `{% extends "base_extra" %}` must not read as a use of `base`. Without
     * the delimiter anchor every template would look used.
     */
    public function testAPrefixOfAnotherTemplateNameIsNotAReference(): void
    {
        $this->write('templates/base.html.twig', 'x');
        $this->write('templates/a.html.twig', '{% extends "@Contao/base_extra.html.twig" %}');

        self::assertSame([], $this->scanTemplate('templates/base.html.twig', 'base'));
    }

    public function testATemplateMentionedInProseIsNotADependency(): void
    {
        $this->write('templates/parent.html5', 'x');
        $this->write('templates/notes.md', 'The parent template does the heavy lifting.');

        self::assertSame([], $this->scanTemplate('templates/parent.html5', 'parent'));
    }

    public function testATemplateIsNotItsOwnReferrer(): void
    {
        $this->write('templates/parent.html5', '<?php $this->extend(\'parent\'); ?>');

        self::assertSame([], $this->scanTemplate('templates/parent.html5', 'parent'));
    }

    public function testTwigTemplateIsMatchedByItsFullPathName(): void
    {
        mkdir($this->dir.'/templates/content_element/text', 0o775, true);
        $this->write('templates/content_element/text/my.html.twig', 'x');
        $this->write('templates/user.html.twig', '{% extends "@Contao/content_element/text/my.html.twig" %}');

        self::assertCount(1, $this->scanTemplate('templates/content_element/text/my.html.twig', 'content_element/text/my'));
    }

    // ──────────────────────────── helpers ────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    private function scanFile(string $path, ?string $uuid = null): array
    {
        return (new FileContentScanner($this->dir))->scan($this->fileTarget($path, $uuid), 50)['references'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scanTemplate(string $path, string $name): array
    {
        $target = new UsageTarget(
            type: 'template',
            table: UsageTarget::TABLE_TEMPLATES,
            id: 0,
            label: $name,
            aliases: [$name],
            path: $path,
        );

        return (new FileContentScanner($this->dir))->scanTemplate($target, 50)['references'];
    }

    private function fileTarget(string $path, ?string $uuid = null): UsageTarget
    {
        return new UsageTarget(
            type: 'file',
            table: 'tl_files',
            id: 1,
            label: basename($path),
            uuid: $uuid,
            path: $path,
        );
    }

    private function write(string $relative, string $content): void
    {
        $absolute = $this->dir.\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $relative);

        if (!is_dir(\dirname($absolute))) {
            mkdir(\dirname($absolute), 0o775, true);
        }

        file_put_contents($absolute, $content);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $dir.\DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
