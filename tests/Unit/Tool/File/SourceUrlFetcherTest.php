<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Tool\File;

use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\HostResolverInterface;
use Netzhirsch\ContaoMcpBundle\Security\OutboundUrlGuard;
use Netzhirsch\ContaoMcpBundle\Tool\File\SourceUrlFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * source_url used to refuse every redirect, so share links, download buttons
 * and CDNs — which nearly all answer 30x first — could not be pulled at all.
 * Redirects are followed now; what has to hold is that every hop is checked
 * as strictly as the first URL, because that is what the refusal was there
 * for.
 */
#[CoversClass(SourceUrlFetcher::class)]
final class SourceUrlFetcherTest extends TestCase
{
    private string $target;

    protected function setUp(): void
    {
        $this->target = tempnam(sys_get_temp_dir(), 'mcp-pull-') ?: '';
    }

    protected function tearDown(): void
    {
        @unlink($this->target);
    }

    public function testARedirectIsFollowedToTheFile(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://cdn.example.com/file.png']]),
            new MockResponse('PNGDATA'),
        ]);

        self::assertNull($this->fetcher($client)->fetchToFile('https://example.com/download', $this->target, 0));
        self::assertSame('PNGDATA', file_get_contents($this->target));
        self::assertSame(2, $client->getRequestsCount());
    }

    /**
     * The point of the old refusal: a public URL that redirects to an
     * internal address. Every hop goes through the same address check.
     */
    public function testARedirectToAPrivateAddressIsRefused(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'http://intranet.example/secret']]),
            new MockResponse('SECRET'),
        ]);
        $fetcher = new SourceUrlFetcher($client, self::guard(['example.com' => '93.184.216.34', 'intranet.example' => '10.0.0.5']));

        $result = $fetcher->fetchToFile('https://example.com/download', $this->target, 0);

        self::assertSame('source_url_blocked', $result['error'] ?? null);
        self::assertStringContainsString('redirect', (string) ($result['message'] ?? ''));
        self::assertSame(1, $client->getRequestsCount(), 'the internal address is never contacted');
        self::assertSame('', file_get_contents($this->target));
    }

    public function testRedirectsAreNotFollowedForever(): void
    {
        $responses = array_fill(0, SourceUrlFetcher::MAX_REDIRECTS + 2, new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location' => '/again']]));
        $client = new MockHttpClient($responses);

        $result = $this->fetcher($client)->fetchToFile('https://example.com/start', $this->target, 0);

        self::assertSame('source_url_http_error', $result['error'] ?? null);
        self::assertSame(SourceUrlFetcher::MAX_REDIRECTS + 1, $client->getRequestsCount());
    }

    /**
     * A file that cannot be stored is refused before it is downloaded, when
     * the server says how large it is — with the site's own limit, which is
     * what the caller can act on.
     */
    public function testAFileAboveMaxFileSizeIsRefusedBeforeTheDownload(): void
    {
        $client = new MockHttpClient([new MockResponse(str_repeat('x', 5000), ['response_headers' => ['Content-Length' => '5000']])]);

        $result = $this->fetcher($client)->fetchToFile('https://example.com/big.pdf', $this->target, 1000);

        self::assertSame('file_too_large', $result['error'] ?? null);
        self::assertSame(1000, $result['max_file_size'] ?? null);
        self::assertSame('', file_get_contents($this->target));
    }

    /**
     * Content-Length is the remote server's claim. The limit holds on what
     * actually arrives.
     */
    public function testTheLimitHoldsWithoutAContentLength(): void
    {
        $client = new MockHttpClient([new MockResponse([str_repeat('x', 600), str_repeat('y', 600)])]);

        $result = $this->fetcher($client)->fetchToFile('https://example.com/big.pdf', $this->target, 1000);

        self::assertSame('file_too_large', $result['error'] ?? null);
    }

    public function testALoginWallIsExplained(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 403])]);

        $result = $this->fetcher($client)->fetchToFile('https://drive.example.com/private', $this->target, 0);

        self::assertSame('source_url_http_error', $result['error'] ?? null);
        self::assertStringContainsString('anonymous visitor', (string) ($result['message'] ?? ''));
    }

    public function testAnEmptyBodyIsAnError(): void
    {
        $client = new MockHttpClient([new MockResponse('')]);

        self::assertSame('source_url_empty', $this->fetcher($client)->fetchToFile('https://example.com/x', $this->target, 0)['error'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function locations(): iterable
    {
        yield 'absolute' => ['https://a.example/x/y', 'https://b.example/z', 'https://b.example/z'];
        yield 'scheme-relative' => ['https://a.example/x/y', '//b.example/z', 'https://b.example/z'];
        yield 'root-relative' => ['https://a.example/x/y?q=1', '/z', 'https://a.example/z'];
        yield 'relative' => ['https://a.example/x/y', 'z', 'https://a.example/x/z'];
        yield 'with port' => ['http://a.example:8080/x/y', '/z', 'http://a.example:8080/z'];
    }

    #[DataProvider('locations')]
    public function testLocationsAreResolvedAgainstTheUrlThatRedirected(string $from, string $location, string $expected): void
    {
        self::assertSame($expected, SourceUrlFetcher::resolveRedirect($from, $location));
    }

    private function fetcher(MockHttpClient $client): SourceUrlFetcher
    {
        return new SourceUrlFetcher($client, self::guard([
            'example.com' => '93.184.216.34',
            'cdn.example.com' => '93.184.216.35',
            'drive.example.com' => '93.184.216.36',
        ]));
    }

    /**
     * @param array<string, string> $ips host => the one address it resolves to
     */
    private static function guard(array $ips): OutboundUrlGuard
    {
        return new OutboundUrlGuard(new class($ips) implements HostResolverInterface {
            /**
             * @param array<string, string> $ips
             */
            public function __construct(private readonly array $ips)
            {
            }

            public function resolve(string $host): array
            {
                return isset($this->ips[$host]) ? [$this->ips[$host]] : [];
            }
        });
    }
}
