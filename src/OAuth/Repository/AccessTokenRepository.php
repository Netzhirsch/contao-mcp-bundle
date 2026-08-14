<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Repository;

use Doctrine\DBAL\Connection;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Netzhirsch\ContaoMcpBundle\OAuth\Entity\AccessTokenEntity;

/**
 * Persists access-token records — used for revocation tracking. The token
 * value is a stateless JWT (signed with the OAuth server's private key,
 * verified by the daemon with the matching public key); only the JTI
 * lives in the DB so we can blacklist specific tokens.
 *
 * Method signatures here intentionally drop scalar typehints + return
 * types to match the league/oauth2-server 8.x interfaces verbatim —
 * adding stricter types causes PHP "incompatible declaration" compile
 * errors. Strict-typing is restored inside the bodies.
 */
final class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, $userIdentifier = null)
    {
        $token = new AccessTokenEntity();
        $token->setClient($clientEntity);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }
        if ($userIdentifier !== null) {
            $token->setUserIdentifier((string) $userIdentifier);
        }

        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity)
    {
        $scopes = [];
        foreach ($accessTokenEntity->getScopes() as $scope) {
            $scopes[] = $scope->getIdentifier();
        }

        $this->connection->insert('tl_mcp_oauth_access_token', [
            'tstamp' => time(),
            'identifier' => $accessTokenEntity->getIdentifier(),
            'client_id' => $accessTokenEntity->getClient()->getIdentifier(),
            'user_id' => (int) $accessTokenEntity->getUserIdentifier(),
            'scopes' => json_encode($scopes, \JSON_UNESCAPED_SLASHES) ?: '[]',
            'expires_at' => $accessTokenEntity->getExpiryDateTime()->getTimestamp(),
            'is_revoked' => 0,
        ]);
    }

    public function revokeAccessToken($tokenId)
    {
        $this->connection->update(
            'tl_mcp_oauth_access_token',
            ['is_revoked' => 1, 'tstamp' => time()],
            ['identifier' => (string) $tokenId],
        );
    }

    public function isAccessTokenRevoked($tokenId)
    {
        $value = $this->connection->fetchOne(
            'SELECT is_revoked FROM tl_mcp_oauth_access_token WHERE identifier = ?',
            [(string) $tokenId],
        );
        if ($value === false) {
            return true; // unknown ⇒ safe-by-default revoked
        }

        return (bool) $value;
    }
}
