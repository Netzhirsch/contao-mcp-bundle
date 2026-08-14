<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Controller\OAuth;

use Contao\CoreBundle\Monolog\ContaoContext;
use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\OAuth\InitialAccessTokenManager;
use Netzhirsch\ContaoMcpBundle\OAuth\Repository\ClientRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RFC 7591: OAuth 2.0 Dynamic Client Registration Protocol. Inspector
 * and Claude.ai's connectors call this before the authorize flow to get
 * themselves a `client_id`. Three admission paths:
 *
 *   - mode `open`: anonymous registration, anyone gets a client_id.
 *   - mode `restricted` + `Authorization: Bearer iat_…`: classic RFC-7591
 *     Initial Access Token (for scripts that can send headers).
 *   - mode `restricted` + active pairing window: the Backend button opens
 *     registration for 10 minutes / one successful registration — the only
 *     path standard MCP clients (mcp-remote, Claude Desktop) can use, since
 *     they cannot attach headers to the registration call.
 *
 * In every path a registered client still needs a logged-in Backend user to
 * grant consent at /authorize before it gets any token, so registration
 * itself never grants access.
 *
 * Request body (RFC 7591 §3.1):
 *   { client_name, redirect_uris, token_endpoint_auth_method, … }
 *
 * Response (RFC 7591 §3.2):
 *   { client_id, client_secret?, client_name, redirect_uris, … }
 */
// Public endpoint — clients self-register before they have any
// authentication. No `_scope: backend` so the backend firewall doesn't
// gate this behind a Contao login.
#[Route(
    '/_mcp_oauth/register',
    name: 'netzhirsch_mcp_oauth_register',
    methods: ['POST'],
)]
final class RegisterController
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly InitialAccessTokenManager $iat,
        private readonly McpServerConfigStorage $configStorage,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        // Hardening #3: Initial Access Token check. In 'restricted' mode
        // (the default) the request must carry a valid IAT issued by a
        // Backend admin. In 'open' mode anyone can register — convenient
        // for local dev, dangerous on a publicly reachable Backend.
        //
        // Pairing window: IATs are useless for the clients we actually pair
        // (mcp-remote, Claude Desktop, …) — none of them can send a header
        // during RFC-7591 registration. The Backend button therefore opens a
        // short window (registration_open_until, max 10 min) during which
        // restricted mode admits ONE anonymous registration; the first
        // success closes the window again (see below). The IAT path stays
        // for scripts/automation that CAN send the header.
        $config = $this->configStorage->load();
        $mode = $config['oauth_registration_mode'];
        $providedIat = null;
        $viaPairingWindow = false;
        if ($mode === 'restricted') {
            $auth = $request->headers->get('Authorization', '');
            if (str_starts_with($auth, 'Bearer ')) {
                $providedIat = trim(substr($auth, 7));
            } elseif (time() < $config['registration_open_until']) {
                $viaPairingWindow = true;
            } else {
                return $this->error(
                    'invalid_token',
                    'Dynamic client registration requires an Initial Access Token (`Authorization: Bearer iat_…`) — or open the pairing window in MCP-Server → Status and connect within 10 minutes.',
                    401,
                );
            }
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return $this->error('invalid_client_metadata', 'Request body must be a JSON object.', 400);
        }

        $name = (string) ($body['client_name'] ?? '');
        if ($name === '') {
            $name = 'Unnamed MCP client';
        }
        $redirectUris = $body['redirect_uris'] ?? [];
        if (!\is_array($redirectUris) || $redirectUris === []) {
            return $this->error('invalid_redirect_uri', 'redirect_uris must be a non-empty array.', 400);
        }
        $redirectUris = array_values(array_map('strval', $redirectUris));
        $authMethod = (string) ($body['token_endpoint_auth_method'] ?? 'none');
        // We support `none` (public clients with PKCE) + `client_secret_post`
        // / `client_secret_basic` (confidential clients). MCP Inspector
        // typically uses `none`.
        $isConfidential = $authMethod !== 'none';

        foreach ($redirectUris as $uri) {
            if (!self::isValidRedirectUri($uri, $isConfidential)) {
                return $this->error(
                    'invalid_redirect_uri',
                    "Invalid redirect_uri: {$uri}. Public clients must use https://, custom schemes, or http://localhost / 127.0.0.1 (RFC 8252).",
                    400,
                );
            }
        }
        $clientId = 'mcp-client-'.bin2hex(random_bytes(8));
        $clientSecret = $isConfidential ? bin2hex(random_bytes(24)) : null;

        // Atomically redeem the IAT (only after the input passed all
        // validation — otherwise we'd burn the token on a bad-shape body).
        if ($providedIat !== null) {
            if (!$this->iat->redeem($providedIat, $clientId)) {
                return $this->error(
                    'invalid_token',
                    'Initial Access Token is invalid, expired, or has already been used.',
                    401,
                );
            }
        }

        $this->clients->create(
            clientId: $clientId,
            name: $name,
            redirectUris: $redirectUris,
            isConfidential: $isConfidential,
            plainSecret: $clientSecret,
        );

        // One pairing per window: the first successful registration closes
        // it. Best-effort — a failed save leaves the window to expire on its
        // own (max 10 min), it never blocks the registration response.
        if ($viaPairingWindow) {
            $this->configStorage->save([...$this->configStorage->load(), 'registration_open_until' => 0]);
        }

        // Every successful registration lands in tl_log (ContaoContext,
        // source 'mcp_oauth') — visible in MCP-Server → Aktivität and the
        // system log; the Status page additionally flags recent
        // registrations as a backend notice.
        $via = $viaPairingWindow
            ? 'pairing window (window closed)'
            : ($providedIat !== null ? 'Initial Access Token' : 'open registration');
        // No quotes in the message — Contao's table handler escapes them and
        // the activity panel would render literal &quot; entities.
        $this->logger->info(
            \sprintf('New MCP client registered: %s (%s) via %s', $name, $clientId, $via),
            ['contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL, null, null, null, 'mcp_oauth')],
        );

        $response = [
            'client_id' => $clientId,
            'client_id_issued_at' => time(),
            'client_name' => $name,
            'redirect_uris' => $redirectUris,
            'token_endpoint_auth_method' => $authMethod,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ];
        if ($clientSecret !== null) {
            $response['client_secret'] = $clientSecret;
        }

        return new JsonResponse($response, 201);
    }

    private function error(string $code, string $description, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $code, 'error_description' => $description], $status);
    }

    /**
     * Validates a redirect_uri against RFC 8252 ("OAuth 2.0 for Native
     * Apps") + our local hardening rules:
     *
     *   - https://*           OK (production servers)
     *   - http://localhost    OK (loopback, RFC 8252)
     *   - http://127.0.0.1    OK (loopback, RFC 8252)
     *   - http://[::1]        OK (IPv6 loopback)
     *   - <custom-scheme>://  OK (mobile / desktop apps, e.g. claude://, mcp-inspector://)
     *   - http://example.com  REJECTED — open redirect risk
     *
     * `$confidential = true` (registered with a client_secret) relaxes the
     * loopback restriction because confidential clients are server-side and
     * can hold cookies / secrets safely.
     */
    /**
     * Schemes that can execute script or read local resources in a browser
     * context. Never acceptable as redirect targets, even via DCR.
     */
    private const DANGEROUS_SCHEMES = [
        'javascript', 'data', 'vbscript', 'file', 'about', 'blob', 'view-source',
        'chrome', 'chrome-extension', 'ms-appx', 'res',
    ];

    private static function isValidRedirectUri(string $uri, bool $confidential = false): bool
    {
        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);

        // Hard deny browser-dangerous schemes regardless of host form.
        if (\in_array($scheme, self::DANGEROUS_SCHEMES, true)) {
            return false;
        }

        if (!isset($parts['host'])) {
            // Custom scheme without host (e.g. com.example.app:/oauth).
            if (preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) !== 1) {
                return false;
            }
            return !\in_array($scheme, ['http', 'https'], true);
        }

        $host = strtolower($parts['host']);

        // https is always fine.
        if ($scheme === 'https') {
            return true;
        }
        // http only for loopback (RFC 8252 §7.3) — unless confidential.
        if ($scheme === 'http') {
            if ($confidential) {
                return true;
            }
            return \in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        }
        // Custom URI schemes for native apps (RFC 8252 §7.1).
        if (preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) === 1) {
            return true;
        }

        return false;
    }
}
