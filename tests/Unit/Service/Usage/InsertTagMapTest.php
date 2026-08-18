<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service\Usage;

use Netzhirsch\ContaoMcpBundle\Service\Usage\InsertTagMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pattern that decides whether a piece of text really references a record.
 *
 * Both directions cost something real: a missed match lets the delete guard
 * wave through a deletion that breaks a link, and a false match blocks a
 * legitimate cleanup with a reference that is not there.
 */
#[CoversClass(InsertTagMap::class)]
final class InsertTagMapTest extends TestCase
{
    /**
     * Group 1 must stay a CAPTURING group — the scanner reads the tag name
     * out of it to report WHICH tag matched. A non-capturing `(?:` made the
     * whole insert-tag scan throw instead of matching.
     */
    public function testCapturesTheTagName(): void
    {
        $pattern = InsertTagMap::pattern(['link', 'link_url'], '42');

        self::assertSame(1, preg_match($pattern, 'see {{link_url::42}} there', $matches));
        self::assertSame('link_url', $matches[1] ?? null);
    }

    #[DataProvider('matchingText')]
    public function testMatches(string $text, string $needle): void
    {
        self::assertSame(1, preg_match(InsertTagMap::pattern(InsertTagMap::tagsFor('tl_page'), $needle), $text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function matchingText(): iterable
    {
        yield 'plain' => ['{{link::42}}', '42'];
        yield 'with parameters' => ['{{link::42|blank}}', '42'];
        yield 'with query string' => ['{{link_url::42?foo=bar}}', '42'];
        yield 'by alias' => ['{{link::contact-us}}', 'contact-us'];
        yield 'inside markup' => ['<p>a {{link_title::42}} b</p>', '42'];
        yield 'uppercase tag' => ['{{LINK::42}}', '42'];
    }

    #[DataProvider('nonMatchingText')]
    public function testDoesNotMatch(string $text, string $needle, string $why): void
    {
        self::assertSame(0, preg_match(InsertTagMap::pattern(InsertTagMap::tagsFor('tl_page'), $needle), $text), $why);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function nonMatchingText(): iterable
    {
        yield 'longer id' => ['{{link::421}}', '42', 'id 42 must not match id 421'];
        yield 'longer alias' => ['{{link::contact-us-now}}', 'contact-us', 'alias prefixes are not references'];
        yield 'foreign tag' => ['{{news::42}}', '42', 'a news tag is not a page reference'];
        yield 'bare number' => ['the answer is 42', '42', 'plain text is not a reference'];
        yield 'similar text' => ['link::42', '42', 'without the braces it is not an insert tag'];
    }

    public function testTagsAreScopedToTheirTable(): void
    {
        self::assertContains('insert_module', InsertTagMap::tagsFor('tl_module'));
        self::assertNotContains('link', InsertTagMap::tagsFor('tl_module'));
        self::assertSame([], InsertTagMap::tagsFor('tl_style_sheet'));
    }

    /**
     * A needle is user data (a page alias), so it must never be able to change
     * the meaning of the pattern.
     */
    public function testNeedleIsQuoted(): void
    {
        $pattern = InsertTagMap::pattern(['link'], 'a.b*c');

        self::assertSame(1, preg_match($pattern, '{{link::a.b*c}}'));
        self::assertSame(0, preg_match($pattern, '{{link::axbxc}}'));
    }
}
