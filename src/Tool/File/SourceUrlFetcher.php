<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\File;

use Netzhirsch\ContaoMcpBundle\Security\OutboundUrlGuard;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pulls a `source_url` for `file_upload` into a file on disk.
 *
 * SSRF guard on EVERY hop: http/https only, the resolved address must be
 * public (private, reserved, loopback, link-local — including the
 * 169.254.169.254 cloud-metadata address — are refused), and the client
 * connects to the address that was checked, not to a second lookup.
 *
 * Redirects used to be refused outright, because a redirect could point at a
 * blocked host. That made source_url fail for a large share of real links —
 * download buttons, CDNs, `?dl=1` share links and the like all answer 30x
 * first. They are followed now, by hand, up to {@see MAX_REDIRECTS} hops, and
 * each Location goes through the same check the first URL did — which is what
 * the refusal was protecting.
 *
 * The body goes to disk in blocks, never into memory as a whole, and the pull
 * stops as soon as it passes the site's maxFileSize (or the hard ceiling when
 * none is set). A Content-Length above the limit is refused before a single
 * byte is downloaded.
 */
final class SourceUrlFetcher
{
    /** Hard ceiling for a pull, whatever maxFileSize says. */
    public const MAX_BYTES = 64 * 1024 * 1024; // 64 MB

    /** Redirects followed, each one checked like the first URL. */
    public const MAX_REDIRECTS = 5;

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OutboundUrlGuard $outboundGuard,
    ) {
    }

    /**
     * @param int $maxFileSize tl_settings.maxFileSize, 0 when unlimited
     *
     * @return array<string, mixed>|null an error to hand back, or null when `$target` holds the body
     */
    public function fetchToFile(string $url, string $target, int $maxFileSize): ?array
    {
        $limit = $maxFileSize > 0 ? min($maxFileSize, self::MAX_BYTES) : self::MAX_BYTES;
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            // Address check + pin, shared with the other outbound paths.
            [$host, $pinnedIp, $urlError] = $this->outboundGuard->pin($current);

            if ($urlError !== null) {
                // Keep the error codes this tool has always returned — callers
                // and the smoke test match on them.
                return [
                    'error' => match ($urlError['error']) {
                        'invalid_url' => 'invalid_source_url',
                        'url_unresolvable' => 'source_url_unresolvable',
                        default => 'source_url_blocked',
                    },
                    'message' => $hop === 0 ? $urlError['message'] : sprintf('The redirect to %s was refused: %s', $current, $urlError['message']),
                ];
            }

            try {
                $response = $this->httpClient->request('GET', $current, [
                    'timeout' => 20,
                    'max_redirects' => 0,      // followed by hand below, each hop checked
                    'max_duration' => 120,
                    'headers' => ['Accept' => '*/*'],
                    // Connect to the address that was checked, not to whatever
                    // the name resolves to on the second lookup.
                    'resolve' => [$host => $pinnedIp],
                ]);
                $status = $response->getStatusCode();
                $headers = $response->getHeaders(false);
            } catch (\Throwable $e) {
                return ['error' => 'source_url_failed', 'message' => 'Could not fetch source_url: '.$e->getMessage()];
            }

            if (\in_array($status, self::REDIRECT_STATUSES, true)) {
                $location = (string) ($headers['location'][0] ?? '');
                $response->cancel();

                if ($location === '') {
                    return ['error' => 'source_url_http_error', 'message' => sprintf('source_url answered HTTP %d without saying where to.', $status)];
                }

                $current = self::resolveRedirect($current, $location);

                continue;
            }

            if ($status < 200 || $status >= 300) {
                $response->cancel();

                return [
                    'error' => 'source_url_http_error',
                    'message' => sprintf(
                        'source_url returned HTTP %d.%s',
                        $status,
                        \in_array($status, [401, 403], true) ? ' The file needs a login or a share link the server can open without one — the server pulls it as an anonymous visitor.' : '',
                    ),
                ];
            }

            $declared = (int) ($headers['content-length'][0] ?? -1);

            if ($declared > $limit) {
                $response->cancel();

                return self::tooLarge($declared, $maxFileSize);
            }

            return $this->stream($response, $target, $limit, $maxFileSize);
        }

        return ['error' => 'source_url_http_error', 'message' => sprintf('source_url redirected more than %d times.', self::MAX_REDIRECTS)];
    }

    /**
     * Turns a Location header into an absolute URL. Absolute and
     * scheme-relative locations are taken as they are; a path is resolved
     * against the URL that redirected.
     */
    public static function resolveRedirect(string $from, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($from);
        $scheme = (string) ($parts['scheme'] ?? 'https');

        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        $origin = $scheme.'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $directory = preg_replace('#/[^/]*$#', '/', (string) ($parts['path'] ?? '/')) ?? '/';

        return $origin.($directory === '' ? '/' : $directory).$location;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stream(\Symfony\Contracts\HttpClient\ResponseInterface $response, string $target, int $limit, int $maxFileSize): ?array
    {
        $out = @fopen($target, 'wb');

        if ($out === false) {
            $response->cancel();

            return ['error' => 'write_failed', 'message' => 'Could not buffer the download in var/mcp/uploads.'];
        }

        $written = 0;

        try {
            foreach ($this->httpClient->stream($response) as $chunk) {
                $block = $chunk->getContent();
                $written += \strlen($block);

                if ($written > $limit) {
                    $response->cancel();

                    return self::tooLarge($written, $maxFileSize);
                }

                fwrite($out, $block);
            }
        } catch (\Throwable $e) {
            return ['error' => 'source_url_failed', 'message' => 'The download broke off: '.$e->getMessage()];
        } finally {
            fclose($out);
        }

        if ($written === 0) {
            return ['error' => 'source_url_empty', 'message' => 'source_url returned an empty body.'];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function tooLarge(int $bytes, int $maxFileSize): array
    {
        return $maxFileSize > 0 && $maxFileSize <= self::MAX_BYTES
            ? [
                'error' => 'file_too_large',
                'message' => sprintf('The remote file is at least %d bytes; this site accepts at most %d (tl_settings.maxFileSize). Nothing was stored.', $bytes, $maxFileSize),
                'max_file_size' => $maxFileSize,
            ]
            : [
                'error' => 'source_url_too_large',
                'message' => sprintf('The remote file exceeds the %d MB pull ceiling.', self::MAX_BYTES >> 20),
            ];
    }
}
