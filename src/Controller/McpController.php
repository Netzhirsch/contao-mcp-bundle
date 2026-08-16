<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Controller;

use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\License\LicenseGate;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionEnforcer;
use Netzhirsch\ContaoMcpBundle\Service\UndoRecorder;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Server\HttpDispatcherFactory;
use Netzhirsch\ContaoMcpBundle\Service\McpOAuthValidator;
use PhpMcp\Schema\Content\TextContent;
use PhpMcp\Schema\Result\CallToolResult;
use PhpMcp\Schema\JsonRpc\BatchRequest;
use PhpMcp\Schema\JsonRpc\Error as JsonRpcError;
use PhpMcp\Schema\JsonRpc\Message;
use PhpMcp\Schema\JsonRpc\Notification;
use PhpMcp\Schema\JsonRpc\Parser;
use PhpMcp\Schema\JsonRpc\Request as JsonRpcRequest;
use PhpMcp\Schema\JsonRpc\Response as JsonRpcResponse;
use PhpMcp\Server\Exception\McpServerException;
use PhpMcp\Server\Session\ArraySessionHandler;
use PhpMcp\Server\Session\Session as McpSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Symfony controller for the "controller-mode" MCP transport — the
 * alternative to the long-running ReactPHP daemon.
 *
 * Each tool-call goes through this controller exactly like a normal Symfony
 * HTTP request: kernel.request → Security firewall (we don't put one in front
 * of /mcp — auth happens here via {@see McpOAuthValidator}) → controller →
 * php-mcp Dispatcher → controller → kernel.response → done.
 *
 * Trade-offs vs. daemon mode:
 *   + No long-running process, no port to expose, no reverse-proxy needed
 *     (the Contao Apache/nginx vhost terminates HTTPS and dispatches to
 *     PHP-FPM as usual).
 *   + No "daemon-stale-restart-required" footgun (no in-process Symfony-
 *     container cache that goes stale on code changes).
 *   − ~150ms extra per request for Symfony's kernel boot. Negligible for
 *     CRUD-style tool calls (Skill 2 builders, ad-hoc Claude sessions),
 *     painful for chatty AI-pair-programming.
 *   − No Server-Sent-Events streaming. Long tool outputs come back in one
 *     block instead of streaming. Affects nothing currently — none of our
 *     tools emit streams.
 *
 * Routes:
 *   POST /mcp                                       — JSON-RPC tool-call endpoint
 *   GET  /.well-known/oauth-authorization-server    — RFC 8414 metadata
 *   GET  /mcp/.well-known/oauth-authorization-server — same, MCP-spec location
 *
 * Both well-known paths advertise the OAuth endpoints on the Contao backend
 * (`/_mcp_oauth/*`) so Claude's mcp-remote bridge can complete the OAuth
 * dance against the standard Symfony Security stack.
 */
final class McpController
{
    public function __construct(
        private readonly McpServerConfigStorage $configStorage,
        private readonly HttpDispatcherFactory $dispatcherFactory,
        private readonly McpOAuthValidator $oauthValidator,
        private readonly RateLimiterFactory $mcpToolCallLimiter,
        private readonly McpPermissionGuard $permissionGuard,
        private readonly McpPermissionEnforcer $permissionEnforcer,
        private readonly UndoRecorder $undoRecorder,
        private readonly LicenseGate $licenseGate,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Main JSON-RPC endpoint. Accepts a single Request, a Notification, or a
     * BatchRequest. Returns the matching JSON-RPC envelope (or 204 for
     * notification-only batches).
     */
    #[Route(
        path: '/mcp',
        name: 'netzhirsch_contao_mcp_controller',
        methods: ['POST', 'OPTIONS'],
        defaults: ['_scope' => 'frontend'],
    )]
    public function handle(Request $request): Response
    {
        // CORS preflight — Inspector / mcp-remote both probe with OPTIONS
        // before sending the actual POST. Allowing Authorization + Content-Type
        // is enough; we don't need cookies on this endpoint.
        if ($request->getMethod() === 'OPTIONS') {
            return new Response('', 204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type, MCP-Session-Id',
                'Access-Control-Max-Age' => '3600',
            ]);
        }

        $config = $this->configStorage->load();
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
                        'WWW-Authenticate' => sprintf(
                            'Bearer realm="MCP", resource_metadata="%s/.well-known/oauth-authorization-server"',
                            rtrim((string) ($config['backend_url'] ?? ''), '/'),
                        ),
                        'Access-Control-Allow-Origin' => '*',
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
        if ($identity !== null) {
            $clientId = $identity['client_id'];
            if ($clientId !== '') {
                $limiter = $this->mcpToolCallLimiter->create($clientId);
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

        // 2. Parse JSON-RPC body. Empty body → 400.
        $raw = $request->getContent();
        if ($raw === '') {
            return new JsonResponse(['error' => 'empty_body', 'message' => 'POST body must be a JSON-RPC envelope.'], 400);
        }

        try {
            $message = Parser::parseRequestMessage($raw);
        } catch (\Throwable $e) {
            return $this->errorResponse(null, McpServerException::parseError($e->getMessage()));
        }

        // 3. Dispatch via php-mcp.
        $dispatcher = $this->dispatcherFactory->getDispatcher();
        $session = $this->makeEphemeralSession();

        try {
            if ($message instanceof BatchRequest) {
                $responses = [];
                /** @var iterable<JsonRpcRequest|Notification> $items */
                $items = $message->items;
                foreach ($items as $item) {
                    if ($item instanceof JsonRpcRequest) {
                        $responses[] = $this->dispatchSingle($dispatcher, $item, $session);
                    } elseif ($item instanceof Notification) {
                        try {
                            $dispatcher->handleNotification($item, $session);
                        } catch (\Throwable $e) {
                            $this->logger->warning('MCP notification handling failed.', ['exception' => $e]);
                        }
                    }
                }
                if ($responses === []) {
                    return new Response('', 204, ['Access-Control-Allow-Origin' => '*']);
                }
                return new JsonResponse(
                    array_map(static fn (Message $m): array => $m->toArray(), $responses),
                    200,
                    ['Access-Control-Allow-Origin' => '*'],
                );
            }

            if ($message instanceof Notification) {
                try {
                    $dispatcher->handleNotification($message, $session);
                } catch (\Throwable $e) {
                    $this->logger->warning('MCP notification handling failed.', ['exception' => $e]);
                }
                return new Response('', 204, ['Access-Control-Allow-Origin' => '*']);
            }

            // Single request.
            \assert($message instanceof JsonRpcRequest);
            $envelope = $this->dispatchSingle($dispatcher, $message, $session);

            return new JsonResponse(
                $envelope->toArray(),
                200,
                ['Access-Control-Allow-Origin' => '*'],
            );
        } catch (\Throwable $e) {
            return $this->errorResponse(
                $message instanceof JsonRpcRequest ? $message->getId() : null,
                McpServerException::internalError($this->opaqueError($e, 'MCP controller dispatch failed.')),
            );
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
        path: '/mcp/.well-known/oauth-authorization-server',
        name: 'netzhirsch_contao_mcp_oauth_metadata_mcp_path',
        methods: ['GET'],
        defaults: ['_scope' => 'frontend'],
    )]
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

        return new JsonResponse([
            'issuer' => $backendUrl,
            'authorization_endpoint' => $backendUrl.'/_mcp_oauth/authorize',
            'token_endpoint' => $backendUrl.'/_mcp_oauth/token',
            'registration_endpoint' => $backendUrl.'/_mcp_oauth/register',
            'scopes_supported' => ['mcp'],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic', 'none'],
            'code_challenge_methods_supported' => ['S256'],
        ], 200, ['Access-Control-Allow-Origin' => '*']);
    }

    /**
     * How many times a tool dispatch is retried after a transient
     * cold-DCA-cache filesystem race (Windows only). See dispatchSingle().
     */
    private const DCA_CACHE_RACE_RETRIES = 3;

    /**
     * Dispatches one JSON-RPC Request, returning either the success Response
     * envelope or the Error envelope (both are valid JSON-RPC payloads — we
     * return them as `Message` to keep the call-site shapeless).
     */
    private function dispatchSingle(\PhpMcp\Server\Dispatcher $dispatcher, JsonRpcRequest $request, \PhpMcp\Server\Contracts\SessionInterface $session): Message
    {
        $id = $request->getId() ?? '';

        // License gate: without an active license/trial, every tool call is
        // refused (core Contao stays untouched, the site keeps running). Returned
        // as an isError tool result, like a permission denial. Enforcement is
        // unconditional (single edition); `ping` stays allowed for health checks.
        if ($request->method === 'tools/call') {
            $params = $request->params ?? [];
            $toolName = \is_array($params) ? (string) ($params['name'] ?? '') : '';
            if ($licenseDenial = $this->licenseGate->denialForTool($toolName)) {
                return JsonRpcResponse::make($id, new CallToolResult(
                    [new TextContent((string) json_encode($licenseDenial))],
                    true,
                ));
            }
        }

        // Per-tool backend-permission parity: a non-admin caller may only run
        // a tool whose underlying backend operation they're allowed to do.
        // Denial is returned as an isError tool result (mirroring how the
        // tools themselves report errors), not a protocol error.
        if ($denial = $this->permissionDenialFor($request)) {
            return JsonRpcResponse::make($id, new CallToolResult(
                [new TextContent((string) json_encode($denial))],
                true,
            ));
        }

        // Snapshot deletions into tl_undo BEFORE they happen, so a human can
        // recover them through Contao's own backend undo. No-op for everything
        // that isn't a delete. See Service\UndoRecorder.
        $undoId = 0;
        if ('tools/call' === $request->method) {
            $params = \is_array($request->params ?? null) ? $request->params : [];
            $undoId = $this->undoRecorder->beforeToolCall(
                (string) ($params['name'] ?? ''),
                \is_array($params['arguments'] ?? null) ? $params['arguments'] : [],
            );
        }

        $lastError = null;
        for ($attempt = 1; $attempt <= self::DCA_CACHE_RACE_RETRIES; ++$attempt) {
            try {
                $response = JsonRpcResponse::make($id, $result = $dispatcher->handleRequest($request, $session));

                // The tool refused (not found, cascade guard, …) — the rows are
                // still there, so the snapshot would be a lie.
                if ($undoId > 0 && $result instanceof CallToolResult && $result->isError) {
                    $this->undoRecorder->discard($undoId);
                }

                return $response;
            } catch (McpServerException $mcpError) {
                $this->undoRecorder->discard($undoId);

                return $mcpError->toJsonRpcError($id);
            } catch (\Throwable $e) {
                // Windows-only transient: when several requests hit a COLD DCA
                // cache at once, Contao regenerates
                // var/cache/<env>/contao/dca/*.php and the atomic rename of the
                // temp file fails ("Access is denied") while a sibling process
                // still holds the target open. loadDataContainer runs before
                // any DB write, so re-running the whole dispatch is safe — the
                // losing process just waits for the winner to finish the rename.
                // Linux/prod warm the cache at deploy time and never hit this.
                if (!$this->isTransientCacheError($e)) {
                    $this->undoRecorder->discard($undoId);

                    return McpServerException::internalError(
                        $this->opaqueError($e, 'MCP tool dispatch failed.'),
                    )->toJsonRpcError($id);
                }
                // Transient: the dispatch is retried, so the snapshot must stay.
                $lastError = $e;
                if ($attempt < self::DCA_CACHE_RACE_RETRIES) {
                    usleep(150_000 * $attempt); // 150ms, then 300ms backoff
                }
            }
        }

        // Every attempt failed — nothing was deleted.
        $this->undoRecorder->discard($undoId);

        $this->logger->error('MCP dispatch failed after DCA-cache retries.', ['exception' => $lastError]);

        return McpServerException::internalError(
            'Transient filesystem error (DCA cache busy) persisted after '.self::DCA_CACHE_RACE_RETRIES.' attempts: '
            .$lastError->getMessage(),
        )->toJsonRpcError($id);
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
     * True when a thrown error looks like the Windows cold-DCA-cache rename
     * race (see {@see dispatchSingle}). Walks the exception chain so a wrapped
     * IOException is still recognised, and falls back to a message signature
     * for cases where Contao re-throws a plain \RuntimeException.
     */
    private function isTransientCacheError(\Throwable $e): bool
    {
        $cur = $e;
        do {
            if ($cur instanceof IOExceptionInterface) {
                return true;
            }
            $msg = $cur->getMessage();
            $isRenameFail = stripos($msg, 'rename') !== false
                || stripos($msg, 'access is denied') !== false
                || stripos($msg, 'zugriff verweigert') !== false
                || stripos($msg, 'permission denied') !== false;
            if ($isRenameFail && (stripos($msg, 'cache') !== false || stripos($msg, 'dca') !== false)) {
                return true;
            }
            $cur = $cur->getPrevious();
        } while ($cur !== null);

        return false;
    }

    /**
     * Resolve a permission denial for a `tools/call` request, or null when the
     * call is allowed / not a tool call. Reads the tool name + arguments from
     * the JSON-RPC params and delegates to the enforcer.
     *
     * @return array{error: string, message: string}|null
     */
    private function permissionDenialFor(JsonRpcRequest $request): ?array
    {
        if ($request->method !== 'tools/call') {
            return null;
        }
        $params = $request->params ?? [];
        $name = (string) ($params['name'] ?? '');
        if ($name === '') {
            return null;
        }
        $args = $params['arguments'] ?? [];
        if (!\is_array($args)) {
            $args = [];
        }

        return $this->permissionEnforcer->check($name, $args);
    }

    /**
     * Builds a transient SessionInterface that satisfies the Dispatcher's
     * contract but doesn't actually persist anything across requests.
     * Tool-calls don't need stateful sessions — the session is only used
     * by `initialize` to negotiate protocol version. Stateless is the
     * cleanest fit for an HTTP-request-scoped controller; each call
     * effectively does its own initialize internally if the dispatcher
     * cares about session state.
     */
    private function makeEphemeralSession(): \PhpMcp\Server\Contracts\SessionInterface
    {
        return new McpSession(
            handler: new ArraySessionHandler(),
            id: bin2hex(random_bytes(8)),
        );
    }

    private function errorResponse(string|int|null $id, McpServerException $exception): JsonResponse
    {
        $jsonRpcError = $exception->toJsonRpcError($id ?? '');

        return new JsonResponse(
            $jsonRpcError->toArray(),
            200,
            ['Access-Control-Allow-Origin' => '*'],
        );
    }
}
