<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Cimd;

/**
 * Decides whether the `redirect_uri` of an authorization request is covered by
 * the `redirect_uris` in the client's metadata document.
 *
 * The rule is exact match — RFC 9700 and the MCP spec both insist on it, and
 * every relaxation of it is somebody's open-redirect advisory. There is exactly
 * one documented exception, and this class implements that one and nothing
 * else: RFC 8252 §7.3 requires a native client's loopback redirect to match
 * with the PORT IGNORED, because the client binds an ephemeral port it cannot
 * know in advance. Claude Code declares `http://localhost/callback` and
 * `http://127.0.0.1/callback` and then arrives on `http://localhost:3118/callback`.
 *
 * What the exception does NOT do is as important as what it does. Scheme, host,
 * path and query must still match exactly, and the host must be a literal
 * loopback name on BOTH sides. Without that last condition,
 * `http://localhost.attacker.example/callback` reads as a loopback host to a
 * careless prefix check and is in fact a domain the attacker owns — the same
 * mistake this bundle already fixed once in its dynamic-registration path.
 */
final class RedirectUriMatcher
{
    /**
     * Hosts for which RFC 8252 §7.3 port-agnostic matching applies. `localhost`
     * is in the list because Claude Code needs it; §8.3 of the same RFC would
     * rather nobody used it, which is why it is a fixed list and not a pattern.
     *
     * @var list<string>
     */
    private const LOOPBACK_HOSTS = ['127.0.0.1', '::1', 'localhost'];

    /**
     * @param list<string> $declared redirect_uris from the metadata document
     *
     * @return bool true when $requested may be used for this authorization
     */
    public static function matches(array $declared, string $requested): bool
    {
        if ($requested === '') {
            return false;
        }

        // An exact match needs no interpretation and is the normal case
        // (https://claude.ai/api/mcp/auth_callback for the hosted surfaces).
        if (\in_array($requested, $declared, true)) {
            return true;
        }

        $candidate = self::loopbackParts($requested);
        if ($candidate === null) {
            return false;
        }

        foreach ($declared as $uri) {
            $registered = self::loopbackParts($uri);

            if ($registered === null) {
                continue;
            }

            // Everything but the port, which RFC 8252 §7.3 says to ignore.
            if ($registered === $candidate) {
                return true;
            }
        }

        return false;
    }

    /**
     * Breaks a loopback redirect URI into the parts that must match exactly,
     * or returns null when the URI is not a loopback redirect at all.
     *
     * @return array{scheme: string, host: string, path: string, query: string}|null
     */
    private static function loopbackParts(string $uri): ?array
    {
        $parts = parse_url($uri);

        if (!\is_array($parts)) {
            return null;
        }

        // Credentials in a redirect URI have no legitimate use and are a
        // reliable way to make a URL read as one host and resolve as another.
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return null;
        }

        if (($parts['scheme'] ?? '') !== 'http') {
            return null;
        }

        // parse_url keeps the brackets on an IPv6 host, so `http://[::1]/cb`
        // arrives here as `[::1]`. Comparing that against the bare `::1` in the
        // list silently disabled port-agnostic matching for IPv6 loopback
        // redirects — found by the test, not by a user.
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (!\in_array($host, self::LOOPBACK_HOSTS, true)) {
            return null;
        }

        return [
            'scheme' => 'http',
            'host' => $host,
            'path' => (string) ($parts['path'] ?? ''),
            'query' => (string) ($parts['query'] ?? ''),
        ];
    }

    /**
     * True when every declared redirect URI is a loopback address. The MCP
     * specification asks authorization servers to warn about exactly this case:
     * a metadata document cannot stop a local process from binding a port and
     * claiming to be the client whose name the user is about to see.
     *
     * @param list<string> $declared
     */
    public static function isLoopbackOnly(array $declared): bool
    {
        if ($declared === []) {
            return false;
        }

        foreach ($declared as $uri) {
            if (self::loopbackParts($uri) === null) {
                return false;
            }
        }

        return true;
    }
}
