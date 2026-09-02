<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service;

use Netzhirsch\ContaoMcpBundle\Service\FieldOwner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `field_not_writable` was read twice as "there is no way to write this", and
 * both times a different tool owned the field. The refusal is where the caller
 * is listening, so it is where the alternative belongs.
 */
#[CoversClass(FieldOwner::class)]
final class FieldOwnerTest extends TestCase
{
    public function testNamesTheOwningCallForAKnownField(): void
    {
        $hint = FieldOwner::hintFor('tl_news_archive', ['master']);

        self::assertStringContainsString('entity_language_link', $hint);
        self::assertStringContainsString('tl_news_archive', $hint);
    }

    public function testCoversEveryCollectionTable(): void
    {
        foreach (['tl_news_archive', 'tl_calendar', 'tl_faq_category'] as $table) {
            self::assertStringContainsString('entity_language_link', FieldOwner::hintFor($table, ['master']));
        }
    }

    /**
     * The fallback has to hand over WORDS, not the identifier: the search is
     * word-based, and `netzhirschPageState` as one token matches nothing.
     */
    public function testOtherwiseSuggestsASearchableePhrase(): void
    {
        $hint = FieldOwner::hintFor('tl_page', ['netzhirschPageState']);

        self::assertStringContainsString('contao_search_tools("netzhirsch page state")', $hint);
    }

    public function testFallsBackToTheTableWhenNoFieldIsNamed(): void
    {
        self::assertStringContainsString('news archive', FieldOwner::hintFor('tl_news_archive', []));
    }

    public function testTheFirstKnownFieldWins(): void
    {
        $hint = FieldOwner::hintFor('tl_calendar', ['irgendwas', 'master']);

        self::assertStringContainsString('entity_language_link', $hint);
    }
}
