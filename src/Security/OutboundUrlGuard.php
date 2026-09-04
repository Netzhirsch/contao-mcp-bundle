<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Security;

use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\HostResolverInterface;
use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\PrivateAddressCheck;

/**
 * The address check every server-side fetch has to pass, in one place.
 *
 * The CIMD path had a careful implementation of this and the other two outbound
 * paths — `file_upload(source_url)` and `page_preview` — each had their own,
 * weaker version. A security audit found both: not because anyone had been
 * careless at those call sites, but because a second copy of a rule drifts from
 * the first by default.
 *
 * Two things the weaker copies got wrong, and this one does not:
 *
 *  - **Resolve-then-PIN, not resolve-then-check.** Checking the address and
 *    then handing the *hostname* to the HTTP client lets the client resolve it
 *    again, and the second answer can differ from the first — the attacker
 *    controls the DNS. The caller must pass the returned ip back as the
 *    client's `resolve` option so the connection goes where the check looked.
 *  - **The full blocklist.** `filter_var(NO_PRIV_RANGE|NO_RES_RANGE)` is the
 *    obvious tool and it lets CGNAT (100.64.0.0/10) and NAT64 (64:ff9b::/96)
 *    straight through — verified on PHP 8.3 and 8.4.
 *    {@see PrivateAddressCheck} blocks both, and unwraps IPv4-mapped IPv6
 *    besides.
 *
 * What this class deliberately does NOT do is perform the request. Body size
 * caps, content types and redirect policy differ per caller — a preview wants
 * HTML and may follow a redirect, an upload wants bytes and must not. Each hop
 * of a redirect is a new request and has to come back through {@see self::pin()}.
 */
final class OutboundUrlGuard
{
    public function __construct(private readonly HostResolverInterface $resolver)
    {
    }

    /**
     * Vet a URL for a server-side fetch.
     *
     * @return array{0: string, 1: string, 2: null}|array{0: null, 1: null, 2: array{error: string, message: string}}
     *                                                                                                              host and the ip to pin it to, or an error to hand back
     */
    public function pin(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        // parse_url keeps the brackets on an IPv6 literal.
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (!\in_array($scheme, ['http', 'https'], true) || $host === '') {
            return [null, null, [
                'error' => 'invalid_url',
                'message' => 'Expected an http(s) URL with a host.',
            ]];
        }

        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            return [null, null, [
                'error' => 'url_unresolvable',
                'message' => sprintf('Could not resolve host "%s".', $host),
            ]];
        }

        // EVERY answer has to be public, not just the one we would pick. A name
        // that resolves to a public and a private address is an attempt, not a
        // coincidence.
        foreach ($ips as $ip) {
            if (!PrivateAddressCheck::isPublic($ip)) {
                return [null, null, [
                    'error' => 'url_blocked',
                    'message' => sprintf(
                        'Refusing to fetch from a private, reserved or link-local address (%s → %s).',
                        $host,
                        $ip,
                    ),
                ]];
            }
        }

        return [$host, $ips[0], null];
    }
}
