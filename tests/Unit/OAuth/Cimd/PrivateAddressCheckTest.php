<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\OAuth\Cimd;

use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\PrivateAddressCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The address filter is the half of the SSRF defence that decides where a
 * connection may go, so its edges are worth nailing down explicitly: the last
 * address inside a blocked range and the first one outside it, both directions.
 *
 * The cases that are easy to get wrong, and that a "private or loopback" check
 * written from the draft's wording alone would let through, are marked below.
 */
#[CoversClass(PrivateAddressCheck::class)]
final class PrivateAddressCheckTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function addresses(): iterable
    {
        yield 'ordinary public v4' => ['1.1.1.1', true];
        yield 'anthropic egress range' => ['160.79.104.1', true];

        yield 'loopback' => ['127.0.0.1', false];
        yield 'loopback, last address' => ['127.255.255.254', false];
        yield 'first address after loopback' => ['128.0.0.1', true];

        yield 'rfc1918 10/8' => ['10.0.0.1', false];
        yield 'just below 10/8' => ['9.255.255.255', true];
        yield 'just above 10/8' => ['11.0.0.1', true];
        yield 'rfc1918 172.16/12' => ['172.16.0.1', false];
        yield 'just below 172.16/12' => ['172.15.255.255', true];
        yield 'just above 172.16/12' => ['172.32.0.1', true];
        yield 'rfc1918 192.168/16' => ['192.168.1.1', false];
        yield 'just below 192.168/16' => ['192.167.255.255', true];

        // The single most valuable SSRF target on a cloud host.
        yield 'cloud metadata endpoint' => ['169.254.169.254', false];

        // Not private by RFC 1918 — routinely the customer's own network on
        // shared hosting, and missed by a naive check.
        yield 'carrier-grade nat' => ['100.64.0.1', false];
        yield 'just below cgnat' => ['100.63.255.255', true];
        yield 'just above cgnat' => ['100.128.0.1', true];

        yield 'this network' => ['0.0.0.0', false];
        yield 'broadcast' => ['255.255.255.255', false];
        yield 'multicast' => ['224.0.0.1', false];
        yield 'benchmarking range' => ['198.18.0.1', false];
        yield 'just above benchmarking' => ['198.20.0.1', true];

        yield 'ipv6 public' => ['2606:4700:4700::1111', true];
        yield 'ipv6 loopback' => ['::1', false];
        yield 'ipv6 unspecified' => ['::', false];
        yield 'ipv6 unique local' => ['fc00::1', false];
        yield 'ipv6 unique local, fd' => ['fd00::1', false];
        yield 'ipv6 link local' => ['fe80::1', false];
        yield 'ipv6 multicast' => ['ff02::1', false];
        yield 'ipv6 documentation' => ['2001:db8::1', false];

        // A public-looking IPv6 address that a translator turns back into an
        // arbitrary IPv4 destination.
        yield 'nat64' => ['64:ff9b::7f00:1', false];

        // Loopback wearing an IPv6 hat. PHP's private/reserved filter flags do
        // not catch these, because as IPv6 addresses they are neither.
        yield 'ipv4-mapped loopback' => ['::ffff:127.0.0.1', false];
        yield 'ipv4-mapped rfc1918' => ['::ffff:10.0.0.1', false];
        yield 'ipv4-mapped public' => ['::ffff:8.8.8.8', true];
        yield 'ipv4-compatible loopback' => ['::127.0.0.1', false];

        // Anything we cannot classify is refused rather than assumed public.
        yield 'not an address' => ['nonsense', false];
        yield 'empty' => ['', false];
        yield 'out of range octet' => ['999.1.1.1', false];
        yield 'hostname' => ['claude.ai', false];
    }

    #[DataProvider('addresses')]
    public function testClassifies(string $ip, bool $expectedPublic): void
    {
        self::assertSame($expectedPublic, PrivateAddressCheck::isPublic($ip));
    }
}
