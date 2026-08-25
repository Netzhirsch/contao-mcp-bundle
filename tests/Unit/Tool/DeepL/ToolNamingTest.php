<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Tool\DeepL;

use Netzhirsch\ContaoMcpBundle\Server\ToolGroups;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The four DeepL tools have to land in one bucket, and that bucket needs a
 * label. Without one the backend tool panel falls back to
 * `ucfirst(str_replace('_', ' ', $group))` and offers the operator a fieldset
 * called "Deepl".
 */
#[CoversClass(ToolGroups::class)]
final class ToolNamingTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../..';

    /**
     * @return iterable<string, array{string}>
     */
    public static function toolNames(): iterable
    {
        foreach (['deepl_status', 'deepl_translate', 'deepl_translate_records', 'deepl_translate_page_tree'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('toolNames')]
    public function testEveryDeepLToolSharesOneGroup(string $tool): void
    {
        self::assertSame('deepl', ToolGroups::groupOf($tool));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function catalogues(): iterable
    {
        yield 'de' => ['contao/languages/de/mcp_server.xlf'];
        yield 'en' => ['contao/languages/en/mcp_server.xlf'];
    }

    #[DataProvider('catalogues')]
    public function testTheGroupHasALabelInBothCatalogues(string $relativePath): void
    {
        self::assertStringContainsString(
            'mcp_server.tool_group_deepl',
            (string) file_get_contents(self::ROOT.'/'.$relativePath),
            "$relativePath has no label for the deepl tool group — the panel would render \"Deepl\".",
        );
    }
}
