<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\OAuth\Cimd;

use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\CimdException;
use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\HostResolverInterface;
use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\SafeUrlFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fetching a client metadata document means this server makes a request to a
 * URL the caller chose. These tests are the record of what that is allowed to
 * do, and the name resolution is stubbed precisely so the interesting answer
 * sets — one public and one private address, an IPv4-mapped loopback — can be
 * exercised at all. None of them are reachable through real DNS.
 */
#[CoversClass(SafeUrlFetcher::class)]
final class SafeUrlFetcherTest extends TestCase
{
    private const URL = 'https://claude.ai/oauth/claude-code-client-metadata';
    private const BODY = '{"client_id":"x"}';

    /**
     * @param list<string> $addresses
     */
    private static function resolver(array $addresses): HostResolverInterface
    {
        return new class($addresses) implements HostResolverInterface {
            /** @param list<string> $addresses */
            public function __construct(private readonly array $addresses)
            {
            }

            public function resolve(string $host): array
            {
                return $this->addresses;
            }
        };
    }

    /**
     * @param array<string, mixed> $info
     */
    private static function fetcher(array $addresses, string $body = self::BODY, array $info = []): SafeUrlFetcher
    {
        $info += ['response_headers' => ['content-type' => 'application/json']];

        return new SafeUrlFetcher(new MockHttpClient(new MockResponse($body, $info)), self::resolver($addresses));
    }

    public function testFetchesADocumentFromAPublicAddress(): void
    {
        $result = self::fetcher(['160.79.104.1'])->fetch(self::URL);

        self::assertSame(self::BODY, $result['body']);
    }

    public function testRefusesAPrivateAddress(): void
    {
        $this->expectExceptionMessage('blocked_address');

        self::fetcher(['127.0.0.1'])->fetch(self::URL);
    }

    /**
     * A name answering one public and one private address is not half-safe:
     * which one the connection ends up on is the resolver's choice, not ours.
     */
    public function testRefusesWhenAnyAnswerIsPrivate(): void
    {
        $this->expectExceptionMessage('blocked_address');

        self::fetcher(['160.79.104.1', '10.0.0.5'])->fetch(self::URL);
    }

    public function testRefusesAnIpv4MappedLoopbackAnswer(): void
    {
        $this->expectExceptionMessage('blocked_address');

        self::fetcher(['::ffff:127.0.0.1'])->fetch(self::URL);
    }

    public function testRefusesWhenTheNameDoesNotResolve(): void
    {
        $this->expectExceptionMessage('dns_empty');

        self::fetcher([])->fetch(self::URL);
    }

    /**
     * The check is only worth anything if the connection goes to the address
     * that was checked. Without the pin, a name can answer one address to our
     * lookup and another to the HTTP client a moment later — DNS rebinding,
     * which defeats every implementation that re-uses the hostname.
     */
    public function testPinsTheConnectionToTheCheckedAddressAndRefusesRedirects(): void
    {
        $mock = new MockResponse(self::BODY, ['response_headers' => ['content-type' => 'application/json']]);
        $fetcher = new SafeUrlFetcher(new MockHttpClient($mock), self::resolver(['160.79.104.1']));

        $fetcher->fetch(self::URL);

        $options = $mock->getRequestOptions();

        self::assertSame(['claude.ai' => '160.79.104.1'], $options['resolve'] ?? null);
        self::assertSame(0, $options['max_redirects'] ?? null);
    }

    public function testRefusesANonOkStatus(): void
    {
        $this->expectExceptionMessage('http_status');

        self::fetcher(['160.79.104.1'], self::BODY, [
            'http_code' => 404,
            'response_headers' => ['content-type' => 'application/json'],
        ])->fetch(self::URL);
    }

    public function testRefusesSomethingThatIsNotJson(): void
    {
        $this->expectExceptionMessage('content_type');

        self::fetcher(['160.79.104.1'], '<html>', [
            'response_headers' => ['content-type' => 'text/html; charset=utf-8'],
        ])->fetch(self::URL);
    }

    /**
     * The body itself is over the cap and gets cut off while it streams. This
     * is the case that matters, because a chunked response carries no length
     * to check up front — the server simply keeps sending.
     *
     * (The mirror case, a server understating Content-Length and then sending
     * more, cannot be modelled here: MockHttpClient aborts the transfer itself,
     * and a real client truncates at the declared length so the short body
     * fails to parse as a document. Either way nothing oversized is accepted.)
     */
    public function testCutsOffAnOversizedBodyWhileStreaming(): void
    {
        $this->expectExceptionMessage('too_large');

        self::fetcher(['160.79.104.1'], str_repeat('a', SafeUrlFetcher::MAX_BYTES + 1))->fetch(self::URL);
    }

    /**
     * The Content-Length check in front of the stream is a fast path, not a
     * second guarantee: an honest oversize header saves the download, a
     * dishonest one changes nothing because the streaming cap still applies.
     * It is deliberately not asserted separately — both paths end in the same
     * refusal, and a test that told them apart would be asserting the mock's
     * internals rather than the behaviour.
     */
    public function testATransportFailureIsReportedGenerically(): void
    {
        $this->expectException(CimdException::class);

        $client = new MockHttpClient(static function (): never {
            throw new \RuntimeException('connection refused to 10.0.0.5:80');
        });

        try {
            (new SafeUrlFetcher($client, self::resolver(['160.79.104.1'])))->fetch(self::URL);
        } catch (CimdException $e) {
            // The detail belongs in the log; the reason is what the caller may
            // learn, and it must not describe the server's network.
            self::assertSame('unreachable', $e->reason);

            throw $e;
        }
    }

    public function testDerivesTheCacheLifetimeFromTheResponse(): void
    {
        $result = self::fetcher(['160.79.104.1'], self::BODY, [
            'response_headers' => [
                'content-type' => 'application/json',
                'cache-control' => 'public, max-age=7200',
            ],
        ])->fetch(self::URL);

        self::assertSame(7200, $result['ttl']);
    }

    public function testDoesNotCacheWhenTheServerSaysNotTo(): void
    {
        $result = self::fetcher(['160.79.104.1'], self::BODY, [
            'response_headers' => [
                'content-type' => 'application/json',
                'cache-control' => 'no-store',
            ],
        ])->fetch(self::URL);

        self::assertSame(0, $result['ttl']);
    }

    public function testClampsAnAbsurdCacheLifetime(): void
    {
        $result = self::fetcher(['160.79.104.1'], self::BODY, [
            'response_headers' => [
                'content-type' => 'application/json',
                'cache-control' => 'max-age=99999999',
            ],
        ])->fetch(self::URL);

        self::assertSame(86400, $result['ttl']);
    }

    public function testATransportFailureIsReportedAsUnreachable(): void
    {
        $this->expectException(CimdException::class);
        $this->expectExceptionMessage('unreachable');

        $client = new MockHttpClient(static function (): never {
            throw new \RuntimeException('connection refused');
        });

        (new SafeUrlFetcher($client, self::resolver(['160.79.104.1'])))->fetch(self::URL);
    }
}
