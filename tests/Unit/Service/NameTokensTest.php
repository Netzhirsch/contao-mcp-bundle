<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service;

use Netzhirsch\ContaoMcpBundle\Service\NameTokens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The case this exists for: a caller refused a write on
 * `tl_page.netzhirschPageState` searched for that exact name, found nothing,
 * concluded the capability was missing and wrote the field the wrong way round.
 * `pagestate_assign` was there the whole time.
 */
#[CoversClass(NameTokens::class)]
final class NameTokensTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function names(): iterable
    {
        yield 'camelCase splits at the humps' => ['netzhirschPageState', ['netzhirsch', 'page', 'state']];
        yield 'the tl_ prefix is not a word' => ['tl_netzhirsch_page_state', ['netzhirsch', 'page', 'state']];
        yield 'underscores separate' => ['tl_news_archive', ['news', 'archive']];
        yield 'a phrase stays a phrase' => ['version undo history revert', ['version', 'undo', 'history', 'revert']];
        yield 'an acronym keeps its shape' => ['HTMLParserConfig', ['html', 'parser', 'config']];
        yield 'a single word is one token' => ['master', ['master']];
        yield 'one-letter fragments are dropped' => ['aX', []];
        yield 'nothing in, nothing out' => ['', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('names')]
    public function testSplit(string $name, array $expected): void
    {
        self::assertSame($expected, NameTokens::split($name));
    }

    public function testDuplicateWordsAppearOnce(): void
    {
        self::assertSame(['page', 'state'], NameTokens::split('page_state_page'));
    }

    public function testPhraseJoinsTheWordsForASearchBox(): void
    {
        self::assertSame('netzhirsch page state', NameTokens::phrase('tl_netzhirsch_page_state'));
    }
}
