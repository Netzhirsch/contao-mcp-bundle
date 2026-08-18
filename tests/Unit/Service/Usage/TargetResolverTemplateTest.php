<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service\Usage;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Service\Usage\SchemaIndex;
use Netzhirsch\ContaoMcpBundle\Service\Usage\TargetResolver;
use Netzhirsch\ContaoMcpBundle\Service\Usage\UsageTarget;
use Netzhirsch\ContaoMcpBundle\Tool\File\PathResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * How a template override on disk becomes the NAME that database columns
 * store it under.
 *
 * Contao uses two spellings and picks between them by file extension. Getting
 * this wrong does not throw — it silently finds nothing, which reads exactly
 * like "safe to delete". Verified against real `customTpl` / `tl_layout.template`
 * values: `nav_default`, `fe_procleaning`, `content_element/text/slider`.
 */
#[CoversClass(TargetResolver::class)]
final class TargetResolverTemplateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'mcp-tpl-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/templates/content_element/text', 0o775, true);
        file_put_contents($this->dir.'/templates/ce_text_my.html5', 'x');
        file_put_contents($this->dir.'/templates/content_element/text/my.html.twig', 'x');
    }

    protected function tearDown(): void
    {
        foreach (['/templates/content_element/text/my.html.twig', '/templates/ce_text_my.html5'] as $file) {
            @unlink($this->dir.$file);
        }

        foreach (['/templates/content_element/text', '/templates/content_element', '/templates', ''] as $sub) {
            @rmdir($this->dir.$sub);
        }
    }

    public function testLegacyTemplateIsNamedByItsBasename(): void
    {
        $target = $this->resolve('ce_text_my.html5');

        self::assertInstanceOf(UsageTarget::class, $target);
        self::assertSame(['ce_text_my'], $target->aliases);
        self::assertSame('templates/ce_text_my.html5', $target->path);
        self::assertSame(UsageTarget::TABLE_TEMPLATES, $target->table);
    }

    public function testTwigTemplateIsNamedByItsFullPath(): void
    {
        $target = $this->resolve('content_element/text/my.html.twig');

        self::assertInstanceOf(UsageTarget::class, $target);
        // NOT "my" — Contao stores the whole managed-namespace path.
        self::assertSame(['content_element/text/my'], $target->aliases);
    }

    public function testAcceptsTheTemplatesPrefix(): void
    {
        $target = $this->resolve('templates/ce_text_my.html5');

        self::assertInstanceOf(UsageTarget::class, $target);
        self::assertSame(['ce_text_my'], $target->aliases);
    }

    public function testMissingOverrideIsNotFound(): void
    {
        self::assertSame('not_found', $this->resolve('nope.html5')['error'] ?? null);
    }

    public function testRejectsPathTraversal(): void
    {
        self::assertSame('invalid_input', $this->resolve('../config/parameters.yml')['error'] ?? null);
    }

    public function testRejectsAnUnknownExtension(): void
    {
        file_put_contents($this->dir.'/templates/notes.md', 'x');

        try {
            self::assertSame('invalid_input', $this->resolve('notes.md')['error'] ?? null);
        } finally {
            @unlink($this->dir.'/templates/notes.md');
        }
    }

    /**
     * @return UsageTarget|array{error: string, message: string}
     */
    private function resolve(string $identifier): UsageTarget|array
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $resolver = new TargetResolver(
            $this->createMock(ContaoFramework::class),
            $connection,
            new SchemaIndex($connection),
            new PathResolver($this->dir, 'files'),
            $this->dir,
        );

        return $resolver->resolve('template', $identifier);
    }
}
