<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\OAuth\AuthorizationServerFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;

/**
 * Validates an MCP Bearer-token Authorization header and populates the
 * per-call identity context. Shared between the ReactPHP daemon (called from
 * `setAuthValidator` closure) and the Symfony controller (`/mcp` POST route).
 *
 * Both transports must produce identical behaviour on auth — same token
 * accepted, same identity propagated to AuthorResolver.
 *
 * Returns identity-array on success, null on failure. Side-effect: sets/
 * clears {@see McpCallContext}.
 */
final class McpOAuthValidator
{
    /**
     * Per-process cache: client_id → display name. Avoids a DB hit on every
     * tool call once a client has been seen. In the controller mode this
     * cache is per-PHP-FPM-worker (reset on worker recycle); in the daemon
     * it lives for the whole daemon-process lifetime.
     *
     * @var array<string, string|null>
     */
    private array $clientNameCache = [];

    public function __construct(
        private readonly AuthorizationServerFactory $oauthServerFactory,
        private readonly McpCallContext $callContext,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{user_id: int, client_id: string, client_name: ?string, scopes: array<int, string>, access_token_id: string}|null
     */
    public function validateBearer(?string $authorizationHeader): ?array
    {
        // Always clear first — safer than relying on the next setIdentity()
        // to overwrite, because a non-Bearer call would otherwise leak the
        // previous request's user when the validator returns early.
        $this->callContext->clear();

        if ($authorizationHeader === null || !str_starts_with($authorizationHeader, 'Bearer ')) {
            return null;
        }

        $psr17 = new Psr17Factory();
        // ResourceServer wants a PSR-7 request with the Authorization
        // header — we synthesise one because the validator interface is
        // simpler that way (and identical between daemon and controller).
        $psrRequest = $psr17->createServerRequest('POST', '/')->withHeader('Authorization', $authorizationHeader);

        // Try the current key first. During a key-rotation rollover
        // (after `contao:mcp:oauth:rotate-keys`, before --prune-old),
        // tokens issued under the OLD key need to keep validating so
        // long-running clients aren't kicked out mid-session. Fall back
        // to the previous-key resource server if the current one
        // rejects with a signature error.
        $resourceServer = $this->oauthServerFactory->createResourceServer();
        $primaryError = null;

        try {
            $validated = $resourceServer->validateAuthenticatedRequest($psrRequest);
        } catch (\Throwable $e) {
            $primaryError = $e;
            $previousServer = $this->oauthServerFactory->createResourceServerWithPreviousKey();
            if ($previousServer === null) {
                $this->logger->info('MCP OAuth auth rejected.', ['reason' => $e->getMessage()]);
                return null;
            }
            try {
                $validated = $previousServer->validateAuthenticatedRequest($psrRequest);
                $this->logger->info('MCP OAuth: token validated against previous key (rotation rollover).', [
                    'primary_reason' => $primaryError->getMessage(),
                ]);
            } catch (\Throwable $previousError) {
                // Both keys rejected — log the PRIMARY error since the
                // previous-key try is just a fallback. The operator most
                // likely cares about "why didn't the current key match"
                // rather than "the old key also said no".
                $this->logger->info('MCP OAuth auth rejected.', [
                    'reason' => $primaryError->getMessage(),
                    'previous_key_reason' => $previousError->getMessage(),
                ]);
                return null;
            }
        }

        $userId = (int) $validated->getAttribute('oauth_user_id');
        $clientId = (string) $validated->getAttribute('oauth_client_id');
        $accessTokenId = (string) $validated->getAttribute('oauth_access_token_id');

        // Resolve the human-friendly client name once per process. If the
        // client row disappeared between issuance and validation we still
        // let the call through — league/oauth2-server's revocation check
        // already happened.
        $clientName = null;
        if ($clientId !== '') {
            if (\array_key_exists($clientId, $this->clientNameCache)) {
                $clientName = $this->clientNameCache[$clientId];
            } else {
                try {
                    $name = $this->connection->fetchOne(
                        'SELECT name FROM tl_mcp_oauth_client WHERE client_id = ?',
                        [$clientId],
                    );
                } catch (\Throwable) {
                    $name = false;
                }
                $clientName = $this->clientNameCache[$clientId] = ($name === false ? null : (string) $name);
            }
        }

        $this->callContext->setIdentity($userId, $clientId, $clientName, $accessTokenId);

        return [
            'user_id' => $userId,
            'client_id' => $clientId,
            'client_name' => $clientName,
            'scopes' => (array) $validated->getAttribute('oauth_scopes', []),
            'access_token_id' => $accessTokenId,
        ];
    }
}
