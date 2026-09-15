<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\License;

use Netzhirsch\ContaoMcpBundle\License\UpdateNotice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The announcement is optional, hand-maintained at the server and may be
 * absent, retracted or malformed. The rule those cases share: say nothing.
 */
#[CoversClass(UpdateNotice::class)]
final class UpdateNoticeTest extends TestCase
{
    private const URL = 'https://github.com/Netzhirsch/contao-mcp-bundle/releases/tag/1.27.0';

    public function testANewerAnnouncedVersionProducesANotice(): void
    {
        $notice = UpdateNotice::evaluate('1.27.0', '1.26.0', false, self::URL);

        self::assertNotNull($notice);
        self::assertSame('1.27.0', $notice->latestVersion);
        self::assertFalse($notice->securityRelease);
        self::assertSame(self::URL, $notice->releaseNotesUrl);
    }

    public function testTheSecurityFlagIsCarried(): void
    {
        $notice = UpdateNotice::evaluate('1.27.0', '1.26.0', true, self::URL);

        self::assertNotNull($notice);
        self::assertTrue($notice->securityRelease);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function silentCases(): iterable
    {
        // The normal state: nothing announced.
        yield 'nothing announced' => ['', '1.26.0'];

        yield 'same version' => ['1.26.0', '1.26.0'];
        yield 'older than installed' => ['1.25.0', '1.26.0'];

        // The bug a string comparison would produce: 1.0.9 sorts above 1.0.10.
        yield 'ninth patch against tenth' => ['1.0.9', '1.0.10'];

        // A development installation. version_compare() would answer, but the
        // answer would tell the developer they are behind the release they are
        // building.
        yield 'installed dev-master' => ['1.27.0', 'dev-master'];
        yield 'installed branch alias' => ['1.27.0', '1.27.x-dev'];
        yield 'installed without a tag' => ['1.27.0', '1.0.0+no-version-set'];
        yield 'installed unknown' => ['1.27.0', ''];

        // Nonsense from the server must not turn into a notice.
        yield 'announced branch' => ['dev-master', '1.26.0'];
        yield 'announced with a space' => ['1.27.0 final', '1.26.0'];
        yield 'announced markup' => ['<script>alert(1)</script>', '1.26.0'];
        yield 'announced overlong' => [str_repeat('9', 40), '1.26.0'];
    }

    #[DataProvider('silentCases')]
    public function testNoNoticeIsShown(string $latest, string $installed): void
    {
        self::assertNull(UpdateNotice::evaluate($latest, $installed, false, self::URL));
    }

    /**
     * A security release on a dev installation is still no notice: the
     * comparison is meaningless either way, and a wrong security warning is
     * worse than none.
     */
    public function testEvenASecurityReleaseStaysSilentOnADevInstall(): void
    {
        self::assertNull(UpdateNotice::evaluate('1.27.0', 'dev-master', true, self::URL));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function urls(): iterable
    {
        yield 'https' => [self::URL, self::URL];
        yield 'padded' => ['  '.self::URL.'  ', self::URL];
        yield 'http' => ['http://example.com/notes', ''];
        yield 'javascript' => ['javascript:alert(1)', ''];
        yield 'protocol relative' => ['//example.com/notes', ''];
        yield 'not a url' => ['https://', ''];
        yield 'empty' => ['', ''];
        yield 'overlong' => ['https://example.com/'.str_repeat('a', 400), ''];
    }

    /**
     * A rejected URL drops the link, it never drops the notice — the version
     * number is the part that matters.
     */
    #[DataProvider('urls')]
    public function testOnlyAnHttpsUrlIsLinked(string $input, string $expected): void
    {
        self::assertSame($expected, UpdateNotice::safeUrl($input));

        $notice = UpdateNotice::evaluate('1.27.0', '1.26.0', false, $input);
        self::assertNotNull($notice);
        self::assertSame($expected, $notice->releaseNotesUrl);
    }
}
