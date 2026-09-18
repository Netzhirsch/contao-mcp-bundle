<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\License;

use Composer\InstalledVersions;
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

    /** HTTP timeout for background calls (cron, CLI), in seconds. */
    private const DEFAULT_TIMEOUT_SECONDS = 10;

    /** Shorter timeout for calls made while a backend page is rendering. */
    public const INTERACTIVE_TIMEOUT_SECONDS = 4;

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
     * @return array{ok: bool, error?: string, message?: string, expires_at?: int, type?: string, plan?: string, latest_version?: string, release_notes_url?: string, security_release?: bool}
     */
    public function startTrial(string $accountEmail): array
    {
        return $this->post('/trial', [
            'product' => LicenseToken::PRODUCT,
            'domain' => $this->domain(),
            'account_email' => trim($accountEmail),
            'instance_secret' => $this->store->getInstanceSecret(),
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
     * @return array{ok: bool, error?: string, message?: string, expires_at?: int, type?: string, plan?: string, latest_version?: string, release_notes_url?: string, security_release?: bool}
     */
    public function renew(bool $force = false, ?int $timeoutSeconds = null): array
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
            // Proof that THIS installation owns the license. Empty only on a
            // first activation — once the server has bound the license to an
            // instance it rejects a claim without the matching secret
            // (`instance_mismatch`), so knowing a customer's domain is no longer
            // enough to pull their token.
            'instance_secret' => $this->store->getInstanceSecret(),
        ], $timeoutSeconds);

        // Record the ATTEMPT for the cron path, so a failing install (unreachable
        // server, unpaid, domain mismatch) doesn't hit the server every hour
        // instead of once per window. A FORCED call that failed must not move the
        // marker — a user clicking a backend button would otherwise postpone the
        // cron's next real attempt by up to a full throttle window.
        if ($result['ok'] || !$force) {
            $this->store->setLastRenewAt(time());
        }

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
            // The portal can cancel the subscription and change payment details,
            // so it must be reachable only from the bound installation — not by
            // anyone who knows the customer's domain.
            'instance_secret' => $this->store->getInstanceSecret(),
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
        if ($url === '' || !self::isStripeUrl($url)) {
            return ['ok' => false, 'error' => 'bad_response', 'message' => 'License server did not return a valid Stripe URL.'];
        }

        return ['ok' => true, 'url' => $url];
    }

    /**
     * Whether a URL is one the backend may send an administrator to.
     *
     * The checkout and portal endpoints return a link the backend redirects to
     * immediately, so the license server decides where a logged-in Contao
     * administrator lands. `https://` alone was not much of a check: an
     * https URL anywhere is still an open redirect out of the backend, and a
     * payment page is exactly the context in which a convincing one pays off.
     *
     * Stripe hosts both pages, so the host can simply be required to be
     * Stripe's — matched on the parsed host, never with str_ends_with alone,
     * because `notstripe.com` ends with `stripe.com`.
     */
    private static function isStripeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        return $host === 'stripe.com' || str_ends_with($host, '.stripe.com');
    }

    /**
     * The three version fields the license server accepts on /trial and /renew.
     *
     * Why they exist: the server had no way to tell which bundle version runs
     * at a customer. The only signal was indirect — whoever sends
     * `instance_secret` is at least 1.0.9 — which is a threshold, not a
     * version. That left two questions open on every support case, and left a
     * compatibility fallback in /renew that nobody could prove was still
     * needed.
     *
     * This is NOT a telemetry channel. Three fields, nothing else: no installed
     * bundles, no page or usage counts, no content, no user data. The server
     * does not accept more either. It is written up in the CHANGELOG so a
     * customer can read what leaves their installation without reading code.
     *
     * Values are sanitised to the server's own rules before sending
     * ({@see VersionString}). The server
     * silently discards what does not fit and keeps the previous value — which
     * is the right behaviour there, but it means a malformed value would show
     * up as "this instance never reported", indistinguishable from an old
     * installation. So a field that cannot be made valid is omitted here
     * instead: a field that IS sent is a field that will be accepted.
     *
     * @return array<string, string>
     */
    private function versionFields(): array
    {
        $fields = [
            'bundle_version' => InstalledVersions::isInstalled('netzhirsch/contao-mcp-bundle')
                ? (string) InstalledVersions::getPrettyVersion('netzhirsch/contao-mcp-bundle')
                : 'dev',
            'contao_version' => InstalledVersions::isInstalled('contao/core-bundle')
                ? (string) InstalledVersions::getPrettyVersion('contao/core-bundle')
                : 'unknown',
            // Deliberately not PHP_VERSION: on some distributions it carries a
            // packaging suffix (8.3.14-1+deb12u1). The server would take it —
            // the characters are allowed — but the column then holds a Debian
            // build id rather than a PHP version, and two instances on the same
            // PHP would not group.
            'php_version' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION,
        ];

        return array_filter(
            array_map(VersionString::sanitise(...), $fields),
            static fn (string $value): bool => $value !== '',
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{ok: bool, error?: string, message?: string, expires_at?: int, type?: string, plan?: string, latest_version?: string, release_notes_url?: string, security_release?: bool}
     */
    private function post(string $endpoint, array $body, ?int $timeoutSeconds = null): array
    {
        $server = $this->serverUrl();

        // `+=` rather than overwriting: a caller could set these itself, and a
        // later rewrite of post() must not be able to displace `product` or
        // `token` by accident.
        $body += $this->versionFields();

        try {
            $response = $this->httpClient->request('POST', $server.$endpoint, [
                'json' => $body,
                // Interactive callers pass a short timeout: a backend page must
                // not sit for 10s per attempt when the license server is slow.
                'timeout' => $timeoutSeconds ?? self::DEFAULT_TIMEOUT_SECONDS,
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

        // The server hands out the instance secret exactly once — on the claim
        // that binds the license to this installation. Persist it; every later
        // trial/renew presents it as proof of possession. Never logged.
        $instanceSecret = (string) ($data['instance_secret'] ?? '');
        if ($instanceSecret !== '') {
            $this->store->setInstanceSecret($instanceSecret);
        }

        // What kind of entitlement this is. `type` distinguishes a trial from a
        // paid/internal license (the subscribe button must still open Stripe
        // while only a trial runs); `plan` marks an internal license, which
        // renews indefinitely instead of expiring. Older servers omit both.
        $plan = (string) ($data['plan'] ?? '');
        $this->store->setPlan($plan);

        // Optional and additive: the server MAY announce a newer release. The
        // fields can be absent, null or nonsense — none of that is allowed to
        // affect the license, so everything below is read defensively and the
        // result is only ever displayed. See UpdateNotice.
        $latestVersion = VersionString::sanitise(self::stringField($data, 'latest_version'));
        $releaseNotesUrl = UpdateNotice::safeUrl(self::stringField($data, 'release_notes_url'));
        $securityRelease = filter_var(self::stringField($data, 'security_release'), FILTER_VALIDATE_BOOLEAN);
        $this->store->setUpdateNotice($latestVersion, $releaseNotesUrl, $securityRelease);

        return [
            'ok' => true,
            'expires_at' => (int) ($data['expires_at'] ?? 0),
            'type' => (string) ($data['type'] ?? ''),
            'plan' => $plan,
            'latest_version' => $latestVersion,
            'release_notes_url' => $releaseNotesUrl,
            'security_release' => $securityRelease,
        ];
    }

    /**
     * A response field as a string, or '' when it is not a scalar.
     *
     * The plain `(string)` cast used for the license fields is fine for those,
     * because the license is void without them anyway. Here it would not be: an
     * array or object in `latest_version` raises "Array to string conversion",
     * and PHP's error handler turns that warning into an exception in dev and
     * under PHPUnit. The renewal would then fail as 'unreachable' — a malformed
     * announcement taking down the licensing it must not touch.
     *
     * @param array<mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return \is_scalar($value) ? (string) $value : '';
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
