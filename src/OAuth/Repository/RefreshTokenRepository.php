<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Repository;

use Doctrine\DBAL\Connection;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Netzhirsch\ContaoMcpBundle\OAuth\Entity\RefreshTokenEntity;

final class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function getNewRefreshToken(): RefreshTokenEntityInterface
    {
        return new RefreshTokenEntity();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity)
    {
        $this->connection->insert('tl_mcp_oauth_refresh_token', [
            'tstamp' => time(),
            'identifier' => $refreshTokenEntity->getIdentifier(),
            'access_token_identifier' => $refreshTokenEntity->getAccessToken()->getIdentifier(),
            'expires_at' => $refreshTokenEntity->getExpiryDateTime()->getTimestamp(),
            'is_revoked' => 0,
        ]);
    }

    public function revokeRefreshToken($tokenId)
    {
        $this->connection->update(
            'tl_mcp_oauth_refresh_token',
            ['is_revoked' => 1, 'tstamp' => time()],
            ['identifier' => (string) $tokenId],
        );
    }

    public function isRefreshTokenRevoked($tokenId)
    {
        $value = $this->connection->fetchOne(
            'SELECT is_revoked FROM tl_mcp_oauth_refresh_token WHERE identifier = ?',
            [(string) $tokenId],
        );
        if ($value === false) {
            return true;
        }

        return (bool) $value;
    }
}
