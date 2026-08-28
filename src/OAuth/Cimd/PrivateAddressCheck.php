<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Cimd;

/**
 * Decides whether an IP address is one we are willing to open a connection to.
 *
 * This is the load-bearing half of the SSRF defence. The draft only says
 * authorization servers "SHOULD avoid fetching any URLs using private or
 * loopback addresses" — that sentence, taken literally, misses most of the
 * ways this actually goes wrong:
 *
 *   - `::ffff:127.0.0.1` is loopback wearing an IPv6 hat. PHP's private/reserved
 *     filter flags do not catch it, because as an IPv6 address it is neither.
 *   - `100.64.0.0/10` is carrier-grade NAT. Not private by RFC 1918, routinely
 *     the customer's own network on shared hosting.
 *   - `169.254.169.254` is the cloud metadata endpoint — the single most
 *     valuable SSRF target there is, and the reason this class exists at all.
 *   - `64:ff9b::/96` is NAT64: a public-looking IPv6 address that a translator
 *     turns straight back into an IPv4 destination of the attacker's choosing.
 *
 * So the check is a deny-list of CIDR blocks on top of PHP's filter flags,
 * with IPv4-in-IPv6 unwrapped first. Anything unparsable is refused rather
 * than assumed public: an address we cannot classify is not one to connect to.
 */
final class PrivateAddressCheck
{
    /**
     * Blocks that must never be reached, beyond FILTER_FLAG_NO_PRIV_RANGE and
     * FILTER_FLAG_NO_RES_RANGE. Kept explicit so each entry can be argued with.
     *
     * @var list<string>
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',          // "this network"
        '10.0.0.0/8',         // RFC 1918
        '100.64.0.0/10',      // RFC 6598 carrier-grade NAT
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local — includes 169.254.169.254 (cloud metadata)
        '172.16.0.0/12',      // RFC 1918
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // TEST-NET-1
        '192.168.0.0/16',     // RFC 1918
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // TEST-NET-2
        '203.0.113.0/24',     // TEST-NET-3
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, includes 255.255.255.255
    ];

    /** @var list<string> */
    private const BLOCKED_V6 = [
        '::/128',             // unspecified
        '::1/128',            // loopback
        '64:ff9b::/96',       // NAT64 — translates to an arbitrary IPv4 destination
        '100::/64',           // discard-only
        '2001:db8::/32',      // documentation
        'fc00::/7',           // unique local
        'fe80::/10',          // link-local
        'ff00::/8',           // multicast
    ];

    /**
     * True when we may connect to this address.
     */
    public static function isPublic(string $ip): bool
    {
        $ip = self::unwrapMappedV4($ip);

        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false) {
            foreach (self::BLOCKED_V4 as $cidr) {
                if (self::inRange($ip, $cidr, 4)) {
                    return false;
                }
            }

            return true;
        }

        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) !== false) {
            foreach (self::BLOCKED_V6 as $cidr) {
                if (self::inRange($ip, $cidr, 6)) {
                    return false;
                }
            }

            return true;
        }

        // Not an address we can reason about — refuse rather than guess.
        return false;
    }

    /**
     * `::ffff:127.0.0.1` and `::127.0.0.1` carry an IPv4 address inside an IPv6
     * one. Classified as IPv6 they look unremarkable; unwrapped they are
     * whatever the attacker wanted to reach.
     */
    private static function unwrapMappedV4(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || \strlen($packed) !== 16) {
            return $ip;
        }

        // ::ffff:a.b.c.d (mapped) and ::a.b.c.d (deprecated compatible form).
        $prefix = substr($packed, 0, 12);
        if ($prefix === str_repeat("\0", 10)."\xFF\xFF" || $prefix === str_repeat("\0", 12)) {
            $v4 = @inet_ntop(substr($packed, 12));
            if (\is_string($v4)) {
                return $v4;
            }
        }

        return $ip;
    }

    private static function inRange(string $ip, string $cidr, int $version): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $ipPacked = @inet_pton($ip);
        $subnetPacked = @inet_pton($subnet);
        if ($ipPacked === false || $subnetPacked === false || \strlen($ipPacked) !== \strlen($subnetPacked)) {
            return false;
        }

        $expected = $version === 4 ? 4 : 16;
        if (\strlen($ipPacked) !== $expected) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($wholeBytes > 0 && strncmp($ipPacked, $subnetPacked, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (\ord($ipPacked[$wholeBytes]) & $mask) === (\ord($subnetPacked[$wholeBytes]) & $mask);
    }
}
