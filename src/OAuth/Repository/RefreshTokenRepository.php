<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Repository;

use Doctrine\DBAL\Connection;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Netzhirsch\ContaoMcpBundle\OAuth\Entity\RefreshTokenEntity;

final class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    /**
     * How long a rotated refresh token keeps working after it was replaced.
     *
     * Rotation is a plausible reason a connector suddenly demands a fresh
     * browser login: every /token exchange revokes the old refresh token and
     * issues a new one, so if the client's stored copy and ours ever drift
     * apart — two refreshes racing, a response lost on the wire, a retry
     * after a timeout — the client presents a token we just revoked, gets
     * rejected, and has nothing left but the full authorization-code flow.
     *
     * A short grace window closes that hole without giving up rotation: a
     * leaked token is still worthless a minute later, but the honest client
     * that retried survives.
     */
    private const ROTATION_GRACE_SECONDS = 60;

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
        $row = $this->connection->fetchAssociative(
            'SELECT is_revoked, tstamp FROM tl_mcp_oauth_refresh_token WHERE identifier = ?',
            [(string) $tokenId],
        );

        // Unknown token — never issued, or already purged by the cleanup
        // command. Deleted rows must stay rejected: that is how revoking a
        // client actually takes effect.
        if ($row === false) {
            return true;
        }

        if (!(bool) $row['is_revoked']) {
            return false;
        }

        // Revoked — but revokeRefreshToken() stamps the row at that moment,
        // so a token revoked seconds ago was almost certainly just rotated by
        // this same client. Honour it briefly (see the constant).
        return (time() - (int) $row['tstamp']) > self::ROTATION_GRACE_SECONDS;
    }
}
