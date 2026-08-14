<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\License;

use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to the vendor license server (baked-in default URL, overridable via the
 * `license_server_url` config for dev/testing).
 * Implements the HTTP contract in docs/licensing/http-contract.md:
 *
 *   POST {server}/trial   {product, domain, account_email}  → {token, expires_at}
 *   POST {server}/renew   {product, domain, token}          → {token, expires_at}
 *
 * The server enforces "one trial per domain/account" and only renews while the
 * subscription is paid. This client never decides anything about the license —
 * it just fetches the freshly signed token and hands it to {@see LicenseStore}.
 * Verification stays offline in {@see LicenseToken}.
 */
final class RenewalClient
{
    /** Don't hammer the server: at most one auto-renew attempt per interval. */
    private const RENEW_THROTTLE_SECONDS = 6 * 3600;

    /**
     * Vendor's production license server, baked in so customers never have to
     * configure it. Overridable via the `license_server_url` config for
     * dev/testing (empty config = this default). NOT a security surface:
     * pointing it elsewhere cannot mint a valid token (offline verification
     * needs the vendor secret) — it only breaks that install's own licensing.
     */
    private const DEFAULT_LICENSE_SERVER_URL = 'https://license.netzhirsch.de';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LicenseStore $store,
        private readonly McpServerConfigStorage $config,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Request a fresh trial token. The server rejects a second trial for the
     * same domain/account (HTTP 409) — that is where "no restart" lives.
     *
     * @return array{ok: bool, error?: string, message?: string, expires_at?: int}
     */
    public function startTrial(string $accountEmail): array
    {
        return $this->post('/trial', [
            'product' => LicenseToken::PRODUCT,
            'domain' => $this->domain(),
            'account_email' => trim($accountEmail),
        ]);
    }

    /**
     * Renew the current subscription token. No-op-friendly: a non-fatal failure
     * (throttled / unreachable / unpaid) returns ok=false and leaves the stored
     * token untouched, so it stays valid until it actually expires + grace.
     *
     * The ONE exception is an explicit server-side `revoked`: that clears the
     * token immediately (no grace), so a revoked license — including a
     * long-lived internal one — stops working at the next tool call. A mere
     * connectivity failure ('unreachable') never lands here, so a server outage
     * cannot brick a paying install.
     *
     * @return array{ok: bool, error?: string, message?: string, expires_at?: int}
     */
    public function renew(bool $force = false): array
    {
        if (!$force) {
            $since = time() - $this->store->getLastRenewAt();
            if ($since < self::RENEW_THROTTLE_SECONDS) {
                return ['ok' => false, 'error' => 'throttled', 'message' => 'Renewed recently; skipping.'];
            }
        }

        $result = $this->post('/renew', [
            'product' => LicenseToken::PRODUCT,
            'domain' => $this->domain(),
            'token' => $this->store->getToken(),
        ]);

        // Record the ATTEMPT, not just the success: otherwise a failing install
        // (unreachable server, unpaid, domain mismatch) would never advance the
        // throttle and the hourly cron would hit the server every hour instead
        // of once per window.
        $this->store->setLastRenewAt(time());

        if (!$result['ok'] && 'revoked' === ($result['error'] ?? '')) {
            // Authoritative kill switch: the server actively revoked this
            // license. Drop the token so the gate closes now, not after grace.
            $this->store->setToken('');
            $this->logger->warning('MCP license revoked by server — token cleared.', ['domain' => $this->domain()]);
        }

        return $result;
    }

    /**
     * Create a Stripe Checkout session (subscribe). Returns a Stripe-hosted
     * https URL the backend opens — no card data ever touches Contao/us.
     *
     * @return array{ok: bool, url?: string, error?: string, message?: string}
     */
    public function checkoutSession(string $accountEmail, ?string $plan = null): array
    {
        $body = [
            'product' => LicenseToken::PRODUCT,
            'domain' => $this->domain(),
            'account_email' => trim($accountEmail),
        ];
        if ($plan !== null && '' !== trim($plan)) {
            $body['plan'] = trim($plan);
        }

        return $this->fetchUrl('/checkout-session', $body);
    }

    /**
     * Create a Stripe Customer Portal session (manage/cancel/invoices).
     *
     * @return array{ok: bool, url?: string, error?: string, message?: string}
     */
    public function portalSession(): array
    {
        return $this->fetchUrl('/portal-session', [
            'product' => LicenseToken::PRODUCT,
            'domain' => $this->domain(),
            'token' => $this->store->getToken(),
        ]);
    }

    /**
     * POST that expects a Stripe-hosted `{url}` back. The URL is validated to be
     * https before the caller redirects to it.
     *
     * @param array<string, mixed> $body
     *
     * @return array{ok: bool, url?: string, error?: string, message?: string}
     */
    private function fetchUrl(string $endpoint, array $body): array
    {
        $server = $this->serverUrl();

        try {
            $response = $this->httpClient->request('POST', $server.$endpoint, ['json' => $body, 'timeout' => 10]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('MCP license request failed.', ['endpoint' => $endpoint, 'exception' => $e]);

            return ['ok' => false, 'error' => 'unreachable', 'message' => $e->getMessage()];
        }

        if ($status >= 400) {
            return [
                'ok' => false,
                'error' => (string) ($data['error'] ?? 'http_'.$status),
                'message' => (string) ($data['message'] ?? 'License server returned HTTP '.$status.'.'),
            ];
        }

        $url = (string) ($data['url'] ?? '');
        if ($url === '' || !str_starts_with($url, 'https://')) {
            return ['ok' => false, 'error' => 'bad_response', 'message' => 'License server did not return a valid https URL.'];
        }

        return ['ok' => true, 'url' => $url];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{ok: bool, error?: string, message?: string, expires_at?: int}
     */
    private function post(string $endpoint, array $body): array
    {
        $server = $this->serverUrl();

        try {
            $response = $this->httpClient->request('POST', $server.$endpoint, [
                'json' => $body,
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('MCP license request failed.', ['endpoint' => $endpoint, 'exception' => $e]);

            return ['ok' => false, 'error' => 'unreachable', 'message' => $e->getMessage()];
        }

        if ($status >= 400) {
            return [
                'ok' => false,
                'error' => (string) ($data['error'] ?? 'http_'.$status),
                'message' => (string) ($data['message'] ?? 'License server returned HTTP '.$status.'.'),
            ];
        }

        $token = (string) ($data['token'] ?? '');
        if ($token === '' || !$this->store->setToken($token)) {
            return ['ok' => false, 'error' => 'bad_response', 'message' => 'License server did not return a storable token.'];
        }

        return ['ok' => true, 'expires_at' => (int) ($data['expires_at'] ?? 0)];
    }

    private function serverUrl(): string
    {
        $override = trim((string) ($this->config->load()['license_server_url'] ?? ''));

        return rtrim('' !== $override ? $override : self::DEFAULT_LICENSE_SERVER_URL, '/');
    }

    /**
     * The licensed host. In CLI/cron there is no request, so an install that
     * never set `backend_url` would resolve to '' and every renewal would be
     * rejected as a domain mismatch — silently lapsing a paying customer. Fall
     * back to the domain the stored token was issued for (the server re-validates
     * it, so this only targets the right license, it never grants one).
     */
    private function domain(): string
    {
        return LicenseToken::resolveDomain(
            (string) ($this->config->load()['backend_url'] ?? ''),
            $this->requestStack->getCurrentRequest()?->getHost(),
        ) ?: LicenseToken::peekDomain($this->store->getToken());
    }
}
