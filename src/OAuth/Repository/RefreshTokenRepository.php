<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Repository;

use Doctrine\DBAL\Connection;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Netzhirsch\ContaoMcpBundle\OAuth\Entity\RefreshTokenEntity;
use Netzhirsch\ContaoMcpBundle\OAuth\TokenFamilyRevoker;
use Psr\Log\LoggerInterface;

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
        private readonly TokenFamilyRevoker $familyRevoker,
        private readonly LoggerInterface $logger,
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
            'SELECT is_revoked, tstamp, access_token_identifier FROM tl_mcp_oauth_refresh_token WHERE identifier = ?',
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
        if ((time() - (int) $row['tstamp']) <= self::ROTATION_GRACE_SECONDS) {
            return false;
        }

        // Past the window this is no longer a retry. Somebody is presenting a
        // refresh token that was rotated away a while ago, which means two
        // parties hold it — OAuth 2.1 §4.14.2 calls this a replay and says to
        // invalidate everything derived from it. We cannot tell the thief from
        // the client, so both lose the session and the user authorises again.
        //
        // Not a complete net, and it is worth knowing where the hole is: the
        // cleanup command deletes revoked refresh tokens, and once the row is
        // gone a replay simply lands in the "unknown token" branch above —
        // rejected, but with nothing left to trace the family by.
        $this->detectReuse((string) $row['access_token_identifier']);

        return true;
    }

    /**
     * Resolves the owner of the replayed token and kills their whole family.
     *
     * Deliberately never throws: this runs inside the token endpoint, and the
     * decision that matters — reject the token — has already been made by the
     * caller. A database problem while cleaning up must not turn a clean 401
     * into a 500 that tells the caller nothing.
     */
    private function detectReuse(string $accessTokenIdentifier): void
    {
        try {
            $owner = $this->connection->fetchAssociative(
                'SELECT client_id, user_id FROM tl_mcp_oauth_access_token WHERE identifier = ?',
                [$accessTokenIdentifier],
            );

            if ($owner === false) {
                $this->logger->warning(
                    'MCP OAuth: a rotated refresh token was replayed, but its access token is gone — cannot revoke the rest of the family.',
                    ['access_token_identifier' => $accessTokenIdentifier],
                );

                return;
            }

            $this->familyRevoker->revoke(
                (string) $owner['client_id'],
                (int) $owner['user_id'],
                TokenFamilyRevoker::TRIGGER_REFRESH_TOKEN_REUSE,
            );
        } catch (\Throwable $e) {
            $this->logger->error('MCP OAuth: refresh-token reuse detected but the cascade failed.', ['exception' => $e]);
        }
    }
}
