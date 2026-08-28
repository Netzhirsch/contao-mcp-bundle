<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Cimd;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a client metadata document under the assumption that the URL was
 * chosen by an attacker — because it was. Any caller may put any URL in
 * `client_id` and make this server request it.
 *
 * The draft's security section asks for two things: avoid private addresses,
 * and cap the response at 5 KB. It explicitly leaves timeouts, DNS rebinding,
 * rate limiting and content-type checks unspecified. Those gaps are where the
 * bug reports come from, so they are closed here as well:
 *
 *   - **Resolve first, then pin.** Every A/AAAA answer is checked, and the
 *     connection is pinned to the address we checked (`resolve`). Without the
 *     pin the check is theatre: a name can answer 1.2.3.4 to our lookup and
 *     127.0.0.1 to the HTTP client's, a second later. That is DNS rebinding,
 *     and it defeats every implementation that validates a hostname and then
 *     hands the same hostname to its HTTP client.
 *   - **Every answer must be public, not just the one we use.** A name that
 *     resolves to one public and one private address is not half-safe.
 *   - **No redirects.** A 302 to `http://169.254.169.254/` is the shortest way
 *     around an address check, and no honest metadata document needs one.
 *   - **The size cap is enforced while streaming**, not afterwards: a cap
 *     applied to an already-buffered ten-gigabyte body is a memory-limit crash,
 *     not a cap.
 */
final class SafeUrlFetcher
{
    /** Section 6.6 of the draft: recommended maximum document size. */
    public const MAX_BYTES = 5120;

    /**
     * Claude gives the whole OAuth step 10 seconds, so our slice has to be well
     * under that — a slow fetch must fail early enough to leave room for the
     * rest of the authorization request.
     */
    private const TIMEOUT_SECONDS = 5;

    /** Bounds for the cache lifetime derived from the response headers. */
    private const MIN_TTL = 300;
    private const MAX_TTL = 86400;
    private const DEFAULT_TTL = 3600;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly HostResolverInterface $hostResolver,
    ) {
    }

    /**
     * @return array{body: string, ttl: int}
     *
     * @throws CimdException
     */
    public function fetch(string $url): array
    {
        $host = (string) (parse_url($url, \PHP_URL_HOST) ?: '');
        if ($host === '') {
            throw new CimdException('no_host');
        }

        $pinned = $this->resolvePublicAddress($host);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'resolve' => [$host => $pinned],
                'buffer' => false,
            ]);

            $status = $response->getStatusCode();
            if ($status !== 200) {
                throw new CimdException('http_status', (string) $status);
            }

            $headers = $response->getHeaders(false);

            $contentType = strtolower($headers['content-type'][0] ?? '');
            if (!str_contains($contentType, 'json')) {
                throw new CimdException('content_type', $contentType);
            }

            // Content-Length is a hint, not a promise — trusting it alone would
            // let a lying server stream past the cap. It only lets us give up
            // one round trip earlier when it happens to be honest.
            if ((int) ($headers['content-length'][0] ?? 0) > self::MAX_BYTES) {
                throw new CimdException('too_large');
            }

            $body = '';

            foreach ($this->httpClient->stream($response) as $chunk) {
                $body .= $chunk->getContent();

                if (\strlen($body) > self::MAX_BYTES) {
                    $response->cancel();

                    throw new CimdException('too_large');
                }
            }

            return ['body' => $body, 'ttl' => self::ttlFrom($headers)];
        } catch (CimdException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CimdException('unreachable', $e->getMessage());
        }
    }

    /**
     * Resolves the host and returns the address the connection is pinned to.
     * Refuses unless EVERY answer is publicly routable.
     *
     * @throws CimdException
     */
    private function resolvePublicAddress(string $host): string
    {
        $addresses = $this->hostResolver->resolve($host);

        if ($addresses === []) {
            throw new CimdException('dns_empty');
        }

        foreach ($addresses as $address) {
            if (!PrivateAddressCheck::isPublic($address)) {
                throw new CimdException('blocked_address');
            }
        }

        return $addresses[0];
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private static function ttlFrom(array $headers): int
    {
        $cacheControl = strtolower(implode(',', $headers['cache-control'] ?? []));

        if (str_contains($cacheControl, 'no-store') || str_contains($cacheControl, 'no-cache')) {
            return 0;
        }

        if (preg_match('/max-age\s*=\s*(\d+)/', $cacheControl, $m) === 1) {
            $maxAge = (int) $m[1];

            return $maxAge <= 0 ? 0 : max(self::MIN_TTL, min(self::MAX_TTL, $maxAge));
        }

        return self::DEFAULT_TTL;
    }
}
