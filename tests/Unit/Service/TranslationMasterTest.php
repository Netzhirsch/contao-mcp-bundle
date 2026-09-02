<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service;

use Netzhirsch\ContaoMcpBundle\Service\TranslationMaster;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The half of a changelanguage link that lives on the collection. Missing it
 * leaves a database that looks right and a site whose language switcher goes
 * to the home page — so the wording of the warning is the deliverable here,
 * not a nicety: it has to name the call that finishes the job.
 */
#[CoversClass(TranslationMaster::class)]
final class TranslationMasterTest extends TestCase
{
    public function testMapsEachRecordTableToItsCollection(): void
    {
        self::assertSame('tl_news_archive', TranslationMaster::collectionFor('tl_news'));
        self::assertSame('tl_calendar', TranslationMaster::collectionFor('tl_calendar_events'));
        self::assertSame('tl_faq_category', TranslationMaster::collectionFor('tl_faq'));
    }

    /**
     * Pages carry their own languageMain/languageRoot pair and articles inherit
     * the page's, so neither has a collection half to keep in step. Listing them
     * here would produce warnings about a column that does not exist.
     */
    public function testPagesAndArticlesHaveNoCollectionHalf(): void
    {
        self::assertNull(TranslationMaster::collectionFor('tl_page'));
        self::assertNull(TranslationMaster::collectionFor('tl_article'));
    }

    public function testKnowsItsCollectionTables(): void
    {
        self::assertTrue(TranslationMaster::isCollectionTable('tl_calendar'));
        self::assertFalse(TranslationMaster::isCollectionTable('tl_calendar_events'));
        self::assertSame(
            ['tl_news_archive', 'tl_calendar', 'tl_faq_category'],
            TranslationMaster::collectionTables(),
        );
    }

    public function testTheUnlinkedWarningNamesTheCallThatFinishesTheJob(): void
    {
        $warning = TranslationMaster::unlinkedWarning('tl_calendar_events', 8, [
            'collection_table' => 'tl_calendar',
            'translated' => 4,
            'master' => 2,
            'current' => 0,
            'blocker' => null,
        ]);

        self::assertStringContainsString('tl_calendar.4 has master=0', $warning);
        self::assertStringContainsString('hreflang', $warning);
        self::assertStringContainsString('entity_language_link(table: "tl_calendar", default_id: 2', $warning);
        self::assertStringContainsString('{"<lang>": 4}', $warning);
    }

    /**
     * A collection that already translates something ELSE is a different
     * problem from one that translates nothing, and pointing at the same fix
     * would be wrong — that write is the one changelanguage refuses.
     */
    public function testAWrongExistingLinkIsDescribedAsSuch(): void
    {
        $warning = TranslationMaster::unlinkedWarning('tl_news', 16, [
            'collection_table' => 'tl_news_archive',
            'translated' => 3,
            'master' => 1,
            'current' => 5,
            'blocker' => null,
        ]);

        self::assertStringContainsString('already translates tl_news_archive.5', $warning);
        self::assertStringContainsString('tl_news_archive.1', $warning);
        self::assertStringNotContainsString('entity_language_link', $warning);
    }
}
