<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Docs;

use Netzhirsch\ContaoMcpBundle\DependencyInjection\ContaoMcpExtension;
use PhpMcp\Server\Attributes\McpTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Facts the documentation states and the code decides.
 *
 * Both were wrong at the same time. The READMEs and the package description
 * Packagist shows said 197 tools while 196 were registered, and a guide said
 * ~100. And both READMEs told operators to configure the bundle under
 * `netzhirsch_contao_mcp:` in config/packages/ — a key no extension answers
 * to, in a directory the Contao Managed Edition never reads. Put into
 * config/config.yaml, that block stopped cache:clear; left in
 * config/packages/, it was silently ignored.
 */
final class DocumentedFactsTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        yield 'readme (de)' => ['README.md'];
        yield 'readme (en)' => ['README.en.md'];
        yield 'documentation' => ['docs/dokumentation.md'];
        yield 'client guide' => ['docs/mcp-client-lokal-einrichten.md'];
        yield 'installation guide' => ['docs/installation.md'];
        yield 'extending' => ['EXTENDING.md'];
        yield 'package description' => ['composer.json'];
    }

    #[DataProvider('documents')]
    public function testEveryStatedToolCountIsTheRegisteredOne(string $relativePath): void
    {
        $registered = \count(self::registeredToolNames());
        preg_match_all('/(\d{3}) (?:MCP[ -])?[Tt]ools?\b/u', self::read($relativePath), $matches);

        self::assertSame(
            [],
            array_values(array_diff(array_unique($matches[1]), [(string) $registered])),
            "$relativePath states a tool count other than the $registered the bundle registers.",
        );
    }

    public function testTheReadmesAndThePackageDescriptionStateTheCount(): void
    {
        $registered = \count(self::registeredToolNames());

        self::assertStringContainsString("$registered Tools", self::read('README.md'));
        self::assertStringContainsString("$registered tools", self::read('README.en.md'));
        self::assertStringContainsString("$registered MCP tools", self::read('composer.json'));
    }

    #[DataProvider('documents')]
    public function testTheBundleConfigurationIsNotDocumentedUnderAKeyNothingReads(string $relativePath): void
    {
        $contents = self::read($relativePath);

        self::assertStringNotContainsString('netzhirsch_contao_mcp:', $contents,
            "$relativePath documents the root key netzhirsch_contao_mcp, which no extension loads — it is contao_mcp.");
        self::assertDoesNotMatchRegularExpression('#config/packages/[\w-]+\.ya?ml#', $contents,
            "$relativePath tells operators to put a file into config/packages/, which the Contao Managed Edition does not read.");
    }

    public function testTheDocumentedKeyIsTheExtensionAlias(): void
    {
        $alias = (new ContaoMcpExtension())->getAlias();

        foreach (['README.md', 'README.en.md'] as $readme) {
            self::assertStringContainsString("$alias:\n    write:", self::read($readme), "$readme does not document the $alias: block.");
            self::assertStringContainsString('config/config.yaml', self::read($readme));
        }
    }

    /**
     * What php-mcp's discoverer registers from src/Tool: every public,
     * non-static method carrying #[McpTool] on a concrete class.
     *
     * @return list<string>
     */
    private static function registeredToolNames(): array
    {
        $names = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/src/Tool', \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), '.php') || !str_contains((string) file_get_contents($file->getPathname()), '#[McpTool(')) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen(self::ROOT.'/src/'), -4);
            $class = new \ReflectionClass('Netzhirsch\\ContaoMcpBundle\\'.str_replace('/', '\\', $relative));
            if ($class->isAbstract() || $class->isInterface()) {
                continue;
            }

            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic()) {
                    continue;
                }
                foreach ($method->getAttributes(McpTool::class) as $attribute) {
                    $names[] = $attribute->newInstance()->name ?? $method->getName();
                }
            }
        }

        return array_values(array_unique($names));
    }

    private static function read(string $relativePath): string
    {
        return (string) file_get_contents(self::ROOT.'/'.$relativePath);
    }
}
