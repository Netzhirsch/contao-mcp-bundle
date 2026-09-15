<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\License;

use Netzhirsch\ContaoMcpBundle\License\VersionString;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One rule for version strings in both directions: the three fields the
 * renewal sends, and the `latest_version` the server may announce back.
 */
#[CoversClass(VersionString::class)]
final class VersionStringTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function values(): iterable
    {
        // What Composer actually hands back.
        yield 'release' => ['1.0.10', '1.0.10'];
        yield 'prerelease with build' => ['v1.2.0-beta.1+build.7', 'v1.2.0-beta.1+build.7'];
        yield 'branch alias' => ['5.7.x-dev', '5.7.x-dev'];
        yield 'branch' => ['dev-master', 'dev-master'];
        yield 'fallback' => ['dev', 'dev'];

        // Trimmed rather than rejected — leading whitespace is not a reason to
        // lose a perfectly good version.
        yield 'padded' => ['  1.0.10  ', '1.0.10'];

        // A space inside the string is the server's own counter-example.
        yield 'space inside' => ['1.0.10 beta', ''];
        yield 'empty' => ['', ''];
        yield 'slash' => ['1.0/10', ''];
        yield 'too long' => [str_repeat('9', 33), ''];
        yield 'exactly at the limit' => [str_repeat('9', 32), str_repeat('9', 32)];

        // An announced version goes into the backend markup, so the rule also
        // has to keep markup out of it.
        yield 'markup' => ['<b>1.0</b>', ''];
    }

    #[DataProvider('values')]
    public function testSanitise(string $input, string $expected): void
    {
        self::assertSame($expected, VersionString::sanitise($input));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function devValues(): iterable
    {
        yield 'branch' => ['dev-master', true];
        yield 'feature branch' => ['dev-feature/thing', true];
        yield 'branch alias' => ['5.7.x-dev', true];
        yield 'bare dev' => ['dev', true];
        yield 'uppercase' => ['DEV-MASTER', true];
        yield 'checkout without a tag' => ['1.0.0+no-version-set', true];
        yield 'release' => ['1.26.0', false];
        yield 'tagged prerelease' => ['1.26.0-beta.1', false];
        yield 'v-prefixed' => ['v1.26.0', false];
    }

    #[DataProvider('devValues')]
    public function testIsDev(string $input, bool $expected): void
    {
        self::assertSame($expected, VersionString::isDev($input));
    }

    /**
     * The reason this goes through version_compare() and not a string compare.
     */
    public function testATenthPatchIsNewerThanANinth(): void
    {
        self::assertTrue(VersionString::isNewer('1.0.10', '1.0.9'));
        self::assertFalse(VersionString::isNewer('1.0.9', '1.0.10'));
        self::assertGreaterThan(0, strcmp('1.0.9', '1.0.10'), 'the string compare this avoids');
    }

    /**
     * Composer reports a tag as `1.0.10` or `v1.0.10` depending on how it was
     * tagged, and the announced value comes from a different place than the
     * installed one — so the two sides can disagree about the prefix.
     */
    public function testTheVPrefixDoesNotDecideTheComparison(): void
    {
        self::assertFalse(VersionString::isNewer('v1.0.0', '1.0.0'));
        self::assertFalse(VersionString::isNewer('1.0.0', 'v1.0.0'));
        self::assertTrue(VersionString::isNewer('v1.1.0', '1.0.0'));
        self::assertTrue(VersionString::isNewer('1.1.0', 'v1.0.0'));
    }

    public function testEqualIsNotNewer(): void
    {
        self::assertFalse(VersionString::isNewer('1.26.0', '1.26.0'));
    }
}
