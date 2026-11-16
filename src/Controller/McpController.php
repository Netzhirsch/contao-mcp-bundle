<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Controller;

use Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\CimdResolver;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Server\HttpDispatcherFactory;
use Netzhirsch\ContaoMcpBundle\Service\McpOAuthValidator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The MCP endpoint: Streamable HTTP, inside an ordinary Symfony request.
 *
 * kernel.request → controller → MCP SDK (`mcp/sdk`) → kernel.response. No
 * long-running process, no port to expose, no reverse proxy: the Contao vhost
 * terminates HTTPS and hands the request to PHP-FPM like any other.
 *
 * What stays here, in front of the SDK, is everything that decides whether
 * the request is served at all: a readable configuration, the OAuth bearer
 * token, the per-client rate limit, the coarse MCP-access flag, and a ceiling
 * on batches. Everything about a single tool call — licence, permissions,
 * operator switches, undo — lives in {@see \Netzhirsch\ContaoMcpBundle\Server\ToolCallHandler}.
 *
 * Both protocol eras arrive here. Up to revision 2025-11-25 a client opens
 * with `initialize`, gets an `Mcp-Session-Id` and sends it with every later
 * request; the session is kept in the database, so any worker on any node can
 * answer ({@see \Netzhirsch\ContaoMcpBundle\Server\DatabaseSessionStore}).
 * Revision 2026-07-28 has no handshake and no session at all.
 *
 * Routes (<path> is `path` from var/mcp/config.json, "mcp" by default; those
 * are matched by {@see \Netzhirsch\ContaoMcpBundle\EventListener\McpEndpointRouteListener},
 * so a path saved in the backend applies without a cache:clear):
 *   POST   /<path>                                       — JSON-RPC
 *   DELETE /<path>                                       — end a session
 *   GET  /.well-known/oauth-authorization-server         — RFC 8414 metadata
 *   GET  /<path>/.well-known/oauth-authorization-server  — same, MCP-spec location
 *   GET  /.well-known/oauth-protected-resource[/<path>]  — RFC 9728 metadata
 *
 * GET on the endpoint itself is not routed and answers 405: the server opens
 * no stream of its own, which the specification allows.
 */
final class McpController
{
    /**
     * Items a single JSON-RPC batch may carry.
     *
     * The ceiling is not about correctness — it is about the rate limiter
     * meaning something. A batch used to cost one token no matter how many
     * calls were in it (audit F06). 50 is well above what any agent sends in
     * one envelope and far below what would tie up a worker.
     */
    private const MAX_BATCH_ITEMS = 50;

    /**
     * Request headers a browser-based client (the MCP Inspector) may send.
     */
    private const CORS_ALLOWED_HEADERS = 'Authorization, Content-Type, Accept, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID';

    public function __construct(
        private readonly McpServerConfigStorage $configStorage,
        private readonly HttpDispatcherFactory $dispatcherFactory,
        private readonly McpOAuthValidator $oauthValidator,
        private readonly RateLimiterFactory $mcpToolCallLimiter,
        private readonly McpPermissionGuard $permissionGuard,
        private readonly CimdResolver $cimdResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Main endpoint. Checks whether the request may be served, then hands it
     * to the SDK's Streamable HTTP transport and returns what that answers.
     */
    public function handle(Request $request): Response
    {
        // CORS preflight — the Inspector probes with OPTIONS before the actual
        // POST. Answered before authentication: a preflight carries no token.
        if ($request->getMethod() === 'OPTIONS') {
            return new Response('', 204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'POST, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => self::CORS_ALLOWED_HEADERS,
                'Access-Control-Expose-Headers' => 'Mcp-Session-Id, WWW-Authenticate',
                'Access-Control-Max-Age' => '3600',
            ]);
        }

        $config = $this->configStorage->load();

        // A config file that exists but is unreadable or corrupt used to fall
        // back to `auth_mode = none` — an open server with ~190 tools, with
        // nothing in the response to say the guard had been switched off. Serve
        // nothing instead, and say why in the log where an operator will find
        // it. The message to the caller stays generic.
        if (isset($config['config_error'])) {
            $this->logger->critical(
                sprintf('MCP refused every request: %s', (string) $config['config_error']),
            );

            $notConfigured = ($config['config_state'] ?? '') === 'missing';

            return new JsonResponse(
                [
                    'error' => $notConfigured ? 'server_not_configured' : 'server_misconfigured',
                    'message' => $notConfigured
                        ? 'The MCP server is installed but has not been configured yet. '
                          .'Open MCP-Server → Konfiguration in the Contao backend and choose an authentication mode.'
                        : 'The MCP server configuration could not be read. Requests are refused until it is fixed.',
                ],
                503,
                ['Cache-Control' => 'no-store'],
            );
        }

        $authMode = (string) ($config['auth_mode'] ?? 'none');

        // 1. OAuth Bearer validation (if enabled).
        $identity = null;
        if ($authMode === 'oauth') {
            $identity = $this->oauthValidator->validateBearer($request->headers->get('Authorization'));
            if ($identity === null) {
                return new JsonResponse(
                    ['error' => 'unauthorized', 'message' => 'Valid Authorization: Bearer <token> required.'],
                    401,
                    [
                        // RFC 9728: this MUST point at the protected-resource
                        // metadata, not at the authorization-server document.
                        // Pointing it at the latter is what made spec-current
                        // clients fail to discover where to authenticate.
                        'WWW-Authenticate' => sprintf(
                            'Bearer realm="MCP", resource_metadata="%s/.well-known/oauth-protected-resource"',
                            rtrim((string) ($config['backend_url'] ?? ''), '/'),
                        ),
                        'Access-Control-Allow-Origin' => '*',
                        'Access-Control-Expose-Headers' => 'WWW-Authenticate',
                    ],
                );
            }
        }

        // 1b. Per-client rate-limit. The per-IP limit on /_mcp_oauth/token
        // already shields the auth flow from brute-force; this one shields
        // the TOOL-CALL endpoint from a runaway authenticated client. A
        // well-behaved agent runs nowhere near 600/min — that's a soft
        // ceiling, not a workflow throttle. Hitting it means a bug-loop
        // somewhere, and a 429 with Retry-After is the correct backpressure.
        //
        // Keyed by client_id (the DCR registration), NOT by access_token_id:
        // a single client can rotate tokens during the window, and we don't
        // want a refresh to grant a fresh budget.
        //
        // When auth_mode === 'none' there is no client_id to key against —
        // the deployment is presumed trusted (developer laptop, internal
        // network) and rate-limiting would just add latency.
        $batchLimiter = null;
        if ($identity !== null) {
            $clientId = $identity['client_id'];
            if ($clientId !== '') {
                $limiter = $this->mcpToolCallLimiter->create($clientId);
                $batchLimiter = $limiter;
                $limit = $limiter->consume(1);
                if (!$limit->isAccepted()) {
                    $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
                    $this->logger->warning('MCP tool-call rate-limit exceeded.', [
                        'client_id' => $clientId,
                        'client_name' => $identity['client_name'],
                        'retry_after_seconds' => $retryAfter,
                    ]);

                    return new JsonResponse(
                        [
                            'error' => 'rate_limited',
                            'message' => 'Too many tool calls — try again in a moment.',
                        ],
                        429,
                        [
                            'Retry-After' => (string) $retryAfter,
                            'Access-Control-Allow-Origin' => '*',
                        ],
                    );
                }
            }
        }

        // 1c. Coarse MCP-access gate. A non-admin backend user may only use
        // the MCP server when netzhirschMcpAccess is set on their account or
        // a group. No-op under auth_mode=none (no user to gate). Blocks the
        // whole request — including discovery — for users without the flag.
        if ($denial = $this->permissionGuard->ensureMcpAccess()) {
            return new JsonResponse($denial, 403, ['Access-Control-Allow-Origin' => '*']);
        }

        if ($request->getMethod() === 'POST') {
            $raw = $request->getContent();
            if ($raw === '') {
                return new JsonResponse(['error' => 'empty_body', 'message' => 'POST body must be a JSON-RPC envelope.'], 400);
            }

            // 2. A batch is many calls, and the limiter above charged for one.
            //
            // Without this, a single POST carrying thousands of tools/call cost
            // one token and executed all of them — expensive tools included —
            // which makes the limiter decorative and the FPM pool the actual
            // limit. Unreadable JSON is left to the SDK, which answers it with
            // the parse error the protocol prescribes.
            $decoded = json_decode($raw, true);
            if (\is_array($decoded) && array_is_list($decoded) && $decoded !== []) {
                $itemCount = \count($decoded);

                if ($itemCount > self::MAX_BATCH_ITEMS) {
                    return $this->jsonRpcError(JsonRpcError::forInvalidRequest(\sprintf(
                        'Batch too large: %d items, maximum is %d. Split it up.',
                        $itemCount,
                        self::MAX_BATCH_ITEMS,
                    )));
                }

                // Charge the rest of the batch before running any of it, so the
                // refusal lands before the work rather than after.
                if ($batchLimiter !== null && $itemCount > 1) {
                    $batchLimit = $batchLimiter->consume($itemCount - 1);
                    if (!$batchLimit->isAccepted()) {
                        $retryAfter = max(1, $batchLimit->getRetryAfter()->getTimestamp() - time());

                        return new JsonResponse(
                            [
                                'error' => 'rate_limited',
                                'message' => \sprintf('A batch of %d calls exceeds the remaining budget.', $itemCount),
                            ],
                            429,
                            ['Retry-After' => (string) $retryAfter, 'Access-Control-Allow-Origin' => '*'],
                        );
                    }
                }
            }
        }

        // 3. The SDK takes it from here.
        try {
            $psr17 = new Psr17Factory();
            $transport = new StreamableHttpTransport(
                (new PsrHttpFactory($psr17, $psr17, $psr17, $psr17))->createRequest($request),
                $psr17,
                $psr17,
                $this->logger,
                // Authentication is the bearer token above, on every request;
                // the SDK's DNS-rebinding guard would only admit localhost.
                [new CorsMiddleware(['*'])],
            );

            return (new HttpFoundationFactory())->createResponse(
                $this->dispatcherFactory->getServer()->run($transport),
            );
        } catch (\Throwable $e) {
            return $this->jsonRpcError(JsonRpcError::forInternalError(
                $this->opaqueError($e, 'MCP controller dispatch failed.'),
            ));
        }
    }

    /**
     * OAuth 2.0 Authorization Server Metadata (RFC 8414). Both Claude
     * Desktop's mcp-remote bridge and the MCP Inspector probe this endpoint
     * to discover the auth endpoints. We expose it at BOTH the standard
     * RFC location AND the MCP-spec-recommended path so either client works.
     *
     * @return JsonResponse
     */
    #[Route(
        path: '/.well-known/oauth-authorization-server',
        name: 'netzhirsch_contao_mcp_oauth_metadata_root',
        methods: ['GET'],
        defaults: ['_scope' => 'frontend'],
    )]
    public function oauthMetadata(): JsonResponse
    {
        $config = $this->configStorage->load();
        $backendUrl = rtrim((string) ($config['backend_url'] ?? ''), '/');

        if ($backendUrl === '') {
            return new JsonResponse([
                'error' => 'backend_url_missing',
                'message' => 'OAuth-Metadata cannot be advertised: backend_url is not configured in var/mcp/config.json.',
            ], 503);
        }

        $metadata = [
            'issuer' => $backendUrl,
            'authorization_endpoint' => $backendUrl.'/_mcp_oauth/authorize',
            'token_endpoint' => $backendUrl.'/_mcp_oauth/token',
            'registration_endpoint' => $backendUrl.'/_mcp_oauth/register',
            'scopes_supported' => ['mcp'],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic', 'none'],
            'code_challenge_methods_supported' => ['S256'],
        ];

        // This is the document a real client reads — the protected-resource
        // metadata points here. The standalone MetadataController serves the
        // same information at the bundle's own route; the two must not drift
        // apart, and the smoke test asserts they don't.
        if ($this->cimdResolver->isEnabled()) {
            $metadata['client_id_metadata_document_supported'] = true;
        }

        return new JsonResponse($metadata, 200, ['Access-Control-Allow-Origin' => '*']);
    }

    /**
     * OAuth 2.0 Protected Resource Metadata (RFC 9728). This is the document
     * a spec-current MCP client asks for FIRST: the 401 points at it, the
     * client reads `authorization_servers` from it and only then fetches the
     * authorization-server metadata above.
     *
     * We used to point `resource_metadata` at the RFC 8414 document instead
     * and serve nothing here at all — a client following RFC 9728 to the
     * letter found an AS document with no `authorization_servers` key, or a
     * 404 when it built the well-known URL itself. Clients with a fallback
     * survived that, which is why it only broke *sometimes*.
     *
     * Served at both the bare well-known path and the resource-suffixed one
     * (RFC 9728 §3.1: a resource with a path component moves the document to
     * `/.well-known/oauth-protected-resource/<path>`), because clients differ
     * on which they construct.
     */
    #[Route(
        path: '/.well-known/oauth-protected-resource',
        name: 'netzhirsch_contao_mcp_oauth_prm_root',
        methods: ['GET'],
        defaults: ['_scope' => 'frontend'],
    )]
    public function oauthProtectedResourceMetadata(): JsonResponse
    {
        $config = $this->configStorage->load();
        $backendUrl = rtrim((string) ($config['backend_url'] ?? ''), '/');

        if ($backendUrl === '') {
            return new JsonResponse([
                'error' => 'backend_url_missing',
                'message' => 'Protected-Resource-Metadata cannot be advertised: backend_url is not configured in var/mcp/config.json.',
            ], 503);
        }

        $path = trim((string) ($config['path'] ?? 'mcp'), '/');

        return new JsonResponse([
            'resource' => $backendUrl.'/'.$path,
            'authorization_servers' => [$backendUrl],
            'scopes_supported' => ['mcp'],
            'bearer_methods_supported' => ['header'],
        ], 200, ['Access-Control-Allow-Origin' => '*']);
    }

    /**
     * Log the real exception and hand the caller a message that says what to do
     * without saying what broke.
     *
     * Raw `getMessage()` output travels straight to the MCP client and readily
     * contains SQL fragments, file paths or connection details. The reference
     * keeps support workable: it appears in this response AND in the log line.
     */
    private function opaqueError(\Throwable $e, string $context): string
    {
        $reference = bin2hex(random_bytes(4));

        $this->logger->error($context, ['exception' => $e, 'reference' => $reference]);

        return \sprintf('Internal server error (reference %s). See the Contao application log for details.', $reference);
    }

    /**
     * A JSON-RPC error the controller answers itself, before or instead of the
     * SDK. HTTP 200, as JSON-RPC errors have always come back from here.
     */
    private function jsonRpcError(JsonRpcError $error): JsonResponse
    {
        return new JsonResponse($error, 200, ['Access-Control-Allow-Origin' => '*']);
    }
}
