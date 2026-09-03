<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Server;

use Netzhirsch\ContaoMcpBundle\Server\ToolGroups;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolGroups::class)]
final class ToolGroupsTest extends TestCase
{
    public function testMultiWordPrefixesStillWin(): void
    {
        self::assertSame('news_archive', ToolGroups::groupOf('news_archive_get'));
        self::assertSame('calendar_event', ToolGroups::groupOf('calendar_event_create'));
        self::assertSame('html_filter', ToolGroups::groupOf('html_filter_info'));
    }

    public function testPluralsFoldIntoTheirSingular(): void
    {
        self::assertSame('page', ToolGroups::groupOf('pages_list'));
        self::assertSame('lead', ToolGroups::groupOf('leads_list'));
    }

    /**
     * The house convention wants the vendor prefix on the tool name, but the
     * panel heading is derived from the group — and a box called
     * "netzhirsch_pagestate" is a worse answer than the convention is a good
     * one. So the vendor segment is dropped for the group, and a bundle can
     * follow the convention without paying for it in the UI.
     */
    public function testAVendorPrefixDoesNotLeakIntoThePanelHeading(): void
    {
        self::assertSame('pagestate', ToolGroups::groupOf('netzhirsch_pagestate_assign'));
        self::assertSame('pagestate', ToolGroups::groupOf('netzhirsch_pagestate_of_page'));
    }

    public function testTheUnprefixedNamesKeepTheSameGroup(): void
    {
        // Both spellings have to land in one box, or a rename would split the
        // group in the panel while both versions are in the field.
        self::assertSame(
            ToolGroups::groupOf('pagestate_assign'),
            ToolGroups::groupOf('netzhirsch_pagestate_assign'),
        );
    }

    public function testDiscoveryAndSystemStayWhereTheyWere(): void
    {
        self::assertSame('discovery', ToolGroups::groupOf('contao_search_tools'));
        self::assertSame('system', ToolGroups::groupOf('ping'));
    }
}
