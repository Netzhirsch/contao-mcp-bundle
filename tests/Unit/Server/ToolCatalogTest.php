<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Server;

use Netzhirsch\ContaoMcpBundle\Server\ToolCatalog;
use PhpMcp\Schema\Tool;
use PhpMcp\Server\Registry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Guards the per-tool enable/disable mechanics: registry pruning (incl. the
 * protected-tools floor), the Backend panel catalogue shape and the
 * checkbox→config merge semantics (core opt-out vs extension opt-in, plus the
 * keep-unrendered rule for temporarily missing bundles).
 */
#[CoversClass(ToolCatalog::class)]
final class ToolCatalogTest extends TestCase
{
    private ToolCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ToolCatalog();
    }

    private static function registryWith(string ...$names): Registry
    {
        $registry = new Registry(new NullLogger());
        foreach ($names as $name) {
            $registry->registerTool(
                Tool::make($name, ['type' => 'object', 'properties' => []], 'desc '.$name),
                'Some\\Handler',
                true,
            );
        }

        return $registry;
    }

    public function testPruneRemovesDisabledToolsFromRegistry(): void
    {
        $registry = self::registryWith('news_list', 'news_delete', 'page_get');

        $removed = $this->catalog->prune($registry, ['news_delete', 'not_registered_anyway']);

        self::assertSame(['news_delete'], $removed);
        self::assertNull($registry->getTool('news_delete'));
        self::assertNotNull($registry->getTool('news_list'));
        self::assertNotNull($registry->getTool('page_get'));
    }

    public function testPruneNeverRemovesProtectedTools(): void
    {
        $registry = self::registryWith('contao_call', 'ping', 'news_list');

        // Hand-edited config.json trying to disable the discovery proxy.
        $removed = $this->catalog->prune($registry, ['contao_call', 'ping', 'news_list']);

        self::assertSame(['news_list'], $removed);
        self::assertNotNull($registry->getTool('contao_call'));
        self::assertNotNull($registry->getTool('ping'));
    }

    public function testPruneEmptyListIsNoOp(): void
    {
        $registry = self::registryWith('news_list');

        self::assertSame([], $this->catalog->prune($registry, []));
        self::assertNotNull($registry->getTool('news_list'));
    }

    public function testCatalogueGroupsAndFlags(): void
    {
        $rows = $this->catalog->catalogue(
            registryTools: [
                'contao_call' => 'proxy',
                'news_list' => 'list news',
                'news_archive_get' => 'one archive',
                'news_archives_list' => 'list archives',
                'url_rewrite_get' => 'terminal42',
                'language_link_pages' => 'changelanguage helper',
                'acme_invoice_get' => 'enabled extension tool',
            ],
            extensionCandidates: [
                ['name' => 'acme_invoice_get', 'description' => 'enabled extension tool', 'class' => 'Acme\\T'],
                ['name' => 'acme_invoice_delete', 'description' => 'offered but not enabled', 'class' => 'Acme\\T'],
            ],
            disabledTools: ['news_list'],
            enabledExtensions: ['acme_invoice_get'],
        );

        $byGroup = array_column($rows, null, 'group');

        // Plural list group folds into the singular CRUD group.
        self::assertArrayHasKey('news_archive', $byGroup);
        self::assertArrayNotHasKey('news_archives', $byGroup);
        self::assertCount(2, $byGroup['news_archive']['tools']);

        // changelanguage helpers get their own multilingual box.
        self::assertArrayHasKey('multilingual', $byGroup);

        // Discovery ranks first.
        self::assertSame('discovery', $rows[0]['group']);
        self::assertTrue($rows[0]['tools'][0]['protected']);

        $news = array_column($byGroup['news']['tools'], null, 'name');
        self::assertFalse($news['news_list']['enabled'], 'disabled_tools entry must render unchecked');
        self::assertSame('core', $news['news_list']['source']);

        // Extension tools: enabled one from registry, offered one merged in.
        $allTools = array_merge(...array_column($rows, 'tools'));
        $byName = array_column($allTools, null, 'name');
        self::assertTrue($byName['acme_invoice_get']['enabled']);
        self::assertSame('extension', $byName['acme_invoice_get']['source']);
        self::assertFalse($byName['acme_invoice_delete']['enabled'], 'not-enabled candidate must appear unchecked');
        self::assertSame('extension', $byName['acme_invoice_delete']['source']);

        // Counts add up.
        self::assertSame(2, $byGroup['news_archive']['enabled_count']);
        self::assertSame(2, $byGroup['news_archive']['total']);
    }

    public function testMergeSelectionCoreOptOutExtensionOptIn(): void
    {
        $merged = ToolCatalog::mergeSelection(
            renderedCore: ['news_list', 'news_delete', 'page_get', 'contao_call'],
            renderedExtension: ['acme_invoice_get', 'acme_invoice_delete'],
            checked: ['news_list', 'page_get', 'acme_invoice_get'],
            previousDisabled: [],
            previousEnabledExtensions: [],
        );

        // news_delete unchecked → disabled; contao_call unchecked (it never
        // is in the real form, it renders disabled-checked) → protected, kept on.
        self::assertSame(['news_delete'], $merged['disabled_tools']);
        self::assertSame(['acme_invoice_get'], $merged['extension_tools_enabled']);
    }

    public function testMergeSelectionKeepsUnrenderedState(): void
    {
        // newsletter_* not rendered (bundle temporarily uninstalled), a stale
        // extension entry from a removed bundle also unrendered.
        $merged = ToolCatalog::mergeSelection(
            renderedCore: ['news_list'],
            renderedExtension: [],
            checked: ['news_list'],
            previousDisabled: ['newsletter_delete', 'news_list'],
            previousEnabledExtensions: ['vendor_gone_tool'],
        );

        // news_list re-enabled (was disabled, now checked); newsletter_delete
        // kept disabled although invisible; the orphaned extension entry stays.
        self::assertSame(['newsletter_delete'], $merged['disabled_tools']);
        self::assertSame(['vendor_gone_tool'], $merged['extension_tools_enabled']);
    }

    public function testMergeSelectionRoundTripsThroughCatalogue(): void
    {
        // Disabling via merge must render as unchecked in the next catalogue.
        $merged = ToolCatalog::mergeSelection(
            renderedCore: ['news_list', 'news_get'],
            renderedExtension: [],
            checked: ['news_get'],
            previousDisabled: [],
            previousEnabledExtensions: [],
        );

        $rows = $this->catalog->catalogue(
            ['news_list' => 'a', 'news_get' => 'b'],
            [],
            $merged['disabled_tools'],
            $merged['extension_tools_enabled'],
        );

        $tools = array_column($rows[0]['tools'], null, 'name');
        self::assertFalse($tools['news_list']['enabled']);
        self::assertTrue($tools['news_get']['enabled']);
    }
}
