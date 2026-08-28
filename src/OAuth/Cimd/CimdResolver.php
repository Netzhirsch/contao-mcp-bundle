<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Cimd;

use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Turns a URL-shaped `client_id` into a client metadata document we are willing
 * to act on — or refuses, loudly enough for the log and vaguely enough for the
 * caller.
 *
 * Order matters here, and it is cheapest-and-strictest first: shape, then trust
 * policy, then cache, then rate limit, and only then the network. Every check
 * that runs before the fetch is one an attacker cannot pay for with our
 * bandwidth.
 *
 * The trust policy is the part the specification leaves to the operator
 * ("Authorization servers MAY implement domain-based trust policies"). This
 * bundle ships `trusted` by default, allowing the Anthropic hosts, because that
 * is what a Contao site is actually connecting to and because "accept any HTTPS
 * URL" on a customer's production CMS is a bigger promise than the feature is
 * worth. `open` implements the specification's open-server posture for anyone
 * who wants it; `off` disables CIMD entirely and leaves Dynamic Client
 * Registration as the only path.
 */
final class CimdResolver
{
    /** Cache namespace. Bumping it invalidates every stored document. */
    private const CACHE_PREFIX = 'nh_mcp_cimd.v1.';

    /** Guards against a metadata document that is technically valid but absurd. */
    private const MAX_REDIRECT_URIS = 20;
    private const MAX_REDIRECT_URI_LENGTH = 500;
    private const MAX_CLIENT_NAME_LENGTH = 200;

    public function __construct(
        private readonly McpServerConfigStorage $configStorage,
        private readonly SafeUrlFetcher $fetcher,
        private readonly CacheItemPoolInterface $cache,
        private readonly RateLimiterFactory $cimdFetchLimiter,
    ) {
    }

    /**
     * True when the server should advertise `client_id_metadata_document_supported`
     * and act on URL-shaped client ids at all.
     */
    public function isEnabled(): bool
    {
        return $this->mode() !== 'off';
    }

    /**
     * @return array{client_id: string, client_name: string, redirect_uris: list<string>}
     *
     * @throws CimdException
     */
    public function resolve(string $clientId): array
    {
        if (!$this->isEnabled()) {
            throw new CimdException('disabled');
        }

        if ($reason = ClientIdUrl::reject($clientId)) {
            throw new CimdException($reason);
        }

        if (!$this->isTrusted($clientId)) {
            throw new CimdException('host_not_trusted', ClientIdUrl::host($clientId));
        }

        $key = self::CACHE_PREFIX.hash('sha256', $clientId);
        $item = $this->cache->getItem($key);

        if ($item->isHit()) {
            $cached = $item->get();

            if (\is_array($cached) && isset($cached['client_id'], $cached['client_name'], $cached['redirect_uris'])) {
                /** @var array{client_id: string, client_name: string, redirect_uris: list<string>} $cached */
                return $cached;
            }
        }

        // Only a cache MISS costs a request, so the limiter sits here rather
        // than at the entrance: a legitimate client reconnecting all day never
        // touches it, while someone feeding us a fresh URL each time does.
        if (!$this->cimdFetchLimiter->create(ClientIdUrl::host($clientId))->consume()->isAccepted()) {
            throw new CimdException('rate_limited');
        }

        $fetched = $this->fetcher->fetch($clientId);
        $document = $this->validate($clientId, $fetched['body']);

        if ($fetched['ttl'] > 0) {
            $item->set($document);
            $item->expiresAfter($fetched['ttl']);
            $this->cache->save($item);
        }

        return $document;
    }

    /**
     * @return array{client_id: string, client_name: string, redirect_uris: list<string>}
     *
     * @throws CimdException
     */
    private function validate(string $clientId, string $body): array
    {
        $decoded = json_decode($body, true);

        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw new CimdException('not_a_json_object');
        }

        // The single most important line in this class. Without it the document
        // at evil.example could claim `client_id: https://claude.ai/...` and the
        // consent screen would show Claude's name for someone else's client.
        if (($decoded['client_id'] ?? null) !== $clientId) {
            throw new CimdException('client_id_mismatch');
        }

        $name = $decoded['client_name'] ?? null;
        if (!\is_string($name) || trim($name) === '' || mb_strlen($name) > self::MAX_CLIENT_NAME_LENGTH) {
            throw new CimdException('client_name');
        }

        $uris = $decoded['redirect_uris'] ?? null;
        if (!\is_array($uris) || $uris === [] || \count($uris) > self::MAX_REDIRECT_URIS) {
            throw new CimdException('redirect_uris');
        }

        $redirectUris = [];
        foreach ($uris as $uri) {
            if (!\is_string($uri) || $uri === '' || \strlen($uri) > self::MAX_REDIRECT_URI_LENGTH) {
                throw new CimdException('redirect_uri_invalid');
            }

            if (!self::isAcceptableRedirectUri($uri)) {
                throw new CimdException('redirect_uri_scheme', $uri);
            }

            $redirectUris[] = $uri;
        }

        // We implement the public-client profile: PKCE, `none` at the token
        // endpoint. A document asking for `private_key_jwt` expects its
        // signature to be verified, and quietly treating such a client as
        // public would weaken exactly the protection it asked for. Refusing is
        // the honest answer until we implement it.
        $authMethod = $decoded['token_endpoint_auth_method'] ?? 'none';
        if ($authMethod !== 'none') {
            throw new CimdException('unsupported_auth_method', (string) (\is_scalar($authMethod) ? $authMethod : '?'));
        }

        $grantTypes = $decoded['grant_types'] ?? ['authorization_code'];
        if (!\is_array($grantTypes) || !\in_array('authorization_code', $grantTypes, true)) {
            throw new CimdException('grant_types');
        }

        return [
            'client_id' => $clientId,
            'client_name' => trim($name),
            'redirect_uris' => array_values(array_unique($redirectUris)),
        ];
    }

    /**
     * "All redirect URIs MUST be either localhost or use HTTPS" — MCP
     * authorization spec, Communication Security. A plaintext redirect to a
     * routable host would hand the authorization code to the network.
     */
    private static function isAcceptableRedirectUri(string $uri): bool
    {
        $parts = parse_url($uri);

        if (!\is_array($parts) || isset($parts['user'], $parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        $scheme = $parts['scheme'] ?? '';

        if ($scheme === 'https') {
            return ($parts['host'] ?? '') !== '';
        }

        if ($scheme === 'http') {
            // Brackets stay on an IPv6 host after parse_url — see the same
            // normalisation in RedirectUriMatcher.
            return \in_array(strtolower(trim((string) ($parts['host'] ?? ''), '[]')), ['127.0.0.1', '::1', 'localhost'], true);
        }

        return false;
    }

    private function isTrusted(string $clientId): bool
    {
        if ($this->mode() === 'open') {
            return true;
        }

        $host = strtolower(ClientIdUrl::host($clientId));

        foreach ($this->trustedHosts() as $trusted) {
            $trusted = strtolower(trim($trusted));

            if ($trusted === '') {
                continue;
            }

            // Exact host, or a subdomain of it. Compared on label boundaries:
            // a plain str_ends_with would let `notclaude.ai` pass for `claude.ai`.
            if ($host === $trusted || str_ends_with($host, '.'.$trusted)) {
                return true;
            }
        }

        return false;
    }

    private function mode(): string
    {
        $config = $this->configStorage->load();
        $mode = $config['cimd_mode'] ?? 'trusted';

        return \in_array($mode, ['off', 'trusted', 'open'], true) ? (string) $mode : 'trusted';
    }

    /**
     * @return list<string>
     */
    private function trustedHosts(): array
    {
        $config = $this->configStorage->load();
        $hosts = $config['cimd_trusted_hosts'] ?? [];

        if (!\is_array($hosts)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($h) => \is_string($h) ? $h : '',
            $hosts,
        ), static fn (string $h) => $h !== ''));
    }
}
