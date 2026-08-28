<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Cimd;

/**
 * Decides whether a `client_id` is a Client ID Metadata Document URL we are
 * willing to dereference — before anything touches the network.
 *
 * Section 3 of draft-ietf-oauth-client-id-metadata-document-00 constrains the
 * shape: https, a path component, no fragment, no userinfo, no dot segments.
 * Every one of those rules exists to stop a URL from meaning two things at
 * once. `https://good.example/client.json#@evil.example/` and
 * `https://good.example/a/../../internal` are the same trick in two costumes —
 * something that reads as trustworthy to a human and resolves elsewhere.
 *
 * Two rules here go beyond the draft:
 *
 *   - **No IP literals as the host.** A metadata document identifies a client
 *     by DNS and TLS control; an IP literal has neither, and it is the shortest
 *     path to pointing us at infrastructure. There is no legitimate CIMD client
 *     that needs one.
 *   - **A length cap.** `client_id` is a unique-indexed column; a URL longer
 *     than the column is a write error waiting to happen, and refusing it up
 *     front is a better answer than a truncated identifier.
 */
final class ClientIdUrl
{
    /** Matches the tl_mcp_oauth_client.client_id column. */
    public const MAX_LENGTH = 255;

    /**
     * A cheap shape test used to decide whether the CIMD path applies at all.
     * Anything else is an ordinary registered client id and must be left alone.
     */
    public static function looksLikeUrl(string $clientId): bool
    {
        return str_starts_with($clientId, 'https://') || str_starts_with($clientId, 'http://');
    }

    /**
     * @return string|null null when acceptable, otherwise a short machine-readable reason
     */
    public static function reject(string $clientId): ?string
    {
        if ($clientId === '' || \strlen($clientId) > self::MAX_LENGTH) {
            return 'length';
        }

        // Control characters and whitespace never belong in a URL and are a
        // classic way to smuggle a second request line past a parser.
        if (preg_match('/[\x00-\x20\x7F]/', $clientId) === 1) {
            return 'illegal_characters';
        }

        $parts = parse_url($clientId);
        if (!\is_array($parts)) {
            return 'unparsable';
        }

        if (($parts['scheme'] ?? '') !== 'https') {
            return 'not_https';
        }

        if (isset($parts['fragment'])) {
            return 'fragment';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'userinfo';
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return 'no_host';
        }

        // An IP literal cannot demonstrate control over a name, and bracketed
        // IPv6 hosts are the usual way to smuggle one past a naive check.
        if (str_contains($host, ':') || filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return 'ip_literal';
        }

        // A hostname, not a label soup. Rejects trailing dots, empty labels and
        // anything non-ASCII (a punycode host is fine — it is already ASCII).
        if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i', $host) !== 1) {
            return 'invalid_host';
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || $path === '/') {
            return 'no_path';
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return 'dot_segment';
            }
        }

        return null;
    }

    /**
     * Host of an already-accepted URL. Shown on the consent screen, because
     * section 6.4 of the draft asks for exactly that: the user's only real
     * defence against a lookalike client is seeing where it came from.
     */
    public static function host(string $clientId): string
    {
        return (string) (parse_url($clientId, \PHP_URL_HOST) ?: '');
    }
}
