<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Tool\PagePreview;

use Netzhirsch\ContaoMcpBundle\Tool\PagePreview\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * page_preview fetches the page over its PUBLIC url, so a staging instance
 * behind HTTP basic auth answers 401 before Contao ever runs and the tool
 * reports an unusable preview.
 *
 * Two failure directions matter here. Not sending configured credentials
 * leaves the tool broken on exactly the instances that need it; sending
 * something when nothing is configured would attach an Authorization header
 * to every preview request on every ordinary site.
 */
#[CoversClass(Tool::class)]
final class RequestOptionsTest extends TestCase
{
    public function testNoCredentialsMeansNoAuthorization(): void
    {
        $options = Tool::requestOptions(null);

        self::assertArrayNotHasKey('auth_basic', $options);
        self::assertSame(10, $options['timeout']);
        self::assertSame('Contao-MCP-Bundle/page_preview', $options['headers']['User-Agent']);
    }

    /**
     * The client must not follow redirects on its own. The target URL comes out
     * of the page tree, and a `redirect` page's `url` is caller-controlled — an
     * editor pointing one at 169.254.169.254 and reading the body back was the
     * finding. fetch() follows hops by hand so each one is vetted first.
     */
    public function testTheClientNeverFollowsARedirectItself(): void
    {
        self::assertSame(0, Tool::requestOptions(null)['max_redirects']);
        self::assertSame(0, Tool::requestOptions('user:pass')['max_redirects']);
    }

    public function testResolvesARedirectTargetAgainstThePageItCameFrom(): void
    {
        self::assertSame(
            'https://example.com/en/news.html',
            Tool::absoluteLocation('https://example.com/de/nachrichten.html', '/en/news.html'),
        );
        self::assertSame(
            'https://example.com/de/sub/page.html',
            Tool::absoluteLocation('https://example.com/de/index.html', 'sub/page.html'),
        );
        self::assertSame(
            'https://other.example/x',
            Tool::absoluteLocation('https://example.com/', 'https://other.example/x'),
        );
        self::assertSame(
            'https://cdn.example/x',
            Tool::absoluteLocation('https://example.com/', '//cdn.example/x'),
        );
    }

    /**
     * A Location that is not http(s) is a dead end, not something to hand on to
     * the next hop.
     */
    public function testRefusesANonHttpRedirectTarget(): void
    {
        self::assertNull(Tool::absoluteLocation('https://example.com/', 'file:///etc/passwd'));
        self::assertNull(Tool::absoluteLocation('https://example.com/', 'gopher://x/'));
        self::assertNull(Tool::absoluteLocation('https://example.com/', '   '));
    }

    /**
     * An unset `MCP_PREVIEW_BASIC_AUTH` reaches the tool as an empty string,
     * not as null, when the env var exists but is blank.
     *
     * @return iterable<string, array{string}>
     */
    public static function blankValues(): iterable
    {
        yield 'empty string' => [''];
        yield 'whitespace' => ['   '];
    }

    #[DataProvider('blankValues')]
    public function testBlankCredentialsAreTreatedAsUnset(string $value): void
    {
        self::assertArrayNotHasKey('auth_basic', Tool::requestOptions($value));
    }

    public function testConfiguredCredentialsAreSent(): void
    {
        $options = Tool::requestOptions('staging:s3cret');

        self::assertSame('staging:s3cret', $options['auth_basic']);
    }

    /**
     * A value without a colon is far more likely a typo than an intentional
     * empty password — but dropping it would surface as a bare 401 with
     * nothing to debug, so it is passed through and fails visibly at the
     * webserver instead.
     */
    public function testAValueWithoutAColonIsStillSent(): void
    {
        self::assertSame('onlyuser', Tool::requestOptions('onlyuser')['auth_basic']);
    }

    public function testSurroundingWhitespaceIsTrimmed(): void
    {
        self::assertSame('user:pass', Tool::requestOptions("  user:pass\n")['auth_basic']);
    }

    /**
     * The credentials must live in the request options and nowhere else — the
     * tool response carries url/status/body, and a leak there would put them
     * into the LLM context and into whatever logs it.
     */
    public function testCredentialsAppearOnlyInTheAuthBasicOption(): void
    {
        $options = Tool::requestOptions('staging:s3cret');
        unset($options['auth_basic']);

        self::assertStringNotContainsString('s3cret', json_encode($options, JSON_THROW_ON_ERROR));
    }
}
