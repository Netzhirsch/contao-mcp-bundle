<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Security;

use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\HostResolverInterface;
use Netzhirsch\ContaoMcpBundle\Security\OutboundUrlGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A security audit found two outbound paths — `file_upload(source_url)` and
 * `page_preview` — each carrying its own weaker copy of the address check the
 * CIMD path already did properly. This is the one copy, and these are the cases
 * the weaker ones let through.
 */
#[CoversClass(OutboundUrlGuard::class)]
final class OutboundUrlGuardTest extends TestCase
{
    /**
     * @param list<string> $ips
     */
    private static function guard(array $ips): OutboundUrlGuard
    {
        return new OutboundUrlGuard(new class($ips) implements HostResolverInterface {
            /** @param list<string> $ips */
            public function __construct(private readonly array $ips)
            {
            }

            public function resolve(string $host): array
            {
                return $this->ips;
            }
        });
    }

    /**
     * The two ranges `filter_var(NO_PRIV_RANGE|NO_RES_RANGE)` does NOT block —
     * verified against PHP 8.3 and 8.4 — plus the classics.
     *
     * @return iterable<string, array{string}>
     */
    public static function blockedAddresses(): iterable
    {
        yield 'cloud metadata' => ['169.254.169.254'];
        yield 'cloud metadata as mapped IPv6' => ['::ffff:169.254.169.254'];
        yield 'CGNAT — passes filter_var' => ['100.64.1.1'];
        yield 'NAT64 — passes filter_var' => ['64:ff9b::a9fe:a9fe'];
        yield 'private v4' => ['10.0.0.1'];
        yield 'loopback v6' => ['::1'];
    }

    #[DataProvider('blockedAddresses')]
    public function testRefusesNonPublicTargets(string $ip): void
    {
        [$host, $pinned, $error] = self::guard([$ip])->pin('http://target.example/x');

        self::assertNull($host);
        self::assertNull($pinned);
        self::assertSame('url_blocked', $error['error']);
    }

    public function testReturnsTheAddressToPinForAPublicTarget(): void
    {
        [$host, $pinned, $error] = self::guard(['93.184.216.34'])->pin('https://example.com/a/b');

        self::assertNull($error);
        self::assertSame('example.com', $host);
        self::assertSame('93.184.216.34', $pinned);
    }

    /**
     * A name answering with one public and one private address is an attempt,
     * not a coincidence — checking only the address that would be used leaves
     * the other one reachable on a retry.
     */
    public function testOneBadAnswerPoisonsTheWholeName(): void
    {
        [, , $error] = self::guard(['93.184.216.34', '127.0.0.1'])->pin('https://example.com/');

        self::assertSame('url_blocked', $error['error']);
    }

    public function testRefusesNonHttpSchemes(): void
    {
        foreach (['file:///etc/passwd', 'gopher://x/', 'ftp://example.com/'] as $url) {
            [, , $error] = self::guard(['93.184.216.34'])->pin($url);
            self::assertSame('invalid_url', $error['error'], $url);
        }
    }

    public function testReportsAnUnresolvableHost(): void
    {
        [, , $error] = self::guard([])->pin('https://nx.example/');

        self::assertSame('url_unresolvable', $error['error']);
    }

    /**
     * parse_url keeps the brackets on an IPv6 literal, and a host that still
     * has them never matches the client's `resolve` key — the pin would be
     * silently ineffective.
     */
    public function testStripsBracketsFromAnIpv6Literal(): void
    {
        [$host, , $error] = self::guard(['2606:2800:220:1:248:1893:25c8:1946'])
            ->pin('https://[2606:2800:220:1:248:1893:25c8:1946]/x');

        self::assertNull($error);
        self::assertSame('2606:2800:220:1:248:1893:25c8:1946', $host);
    }
}
