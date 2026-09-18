<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Kills every token a client holds for one user at once.
 *
 * Two situations call for this, and OAuth 2.1 prescribes the same answer to
 * both: an authorization code redeemed twice (§4.1.2) and a refresh token
 * presented after it was rotated away (§4.14.2). In each case somebody has a
 * copy of a single-use credential, and nothing in the protocol can tell us
 * whether that somebody is the legitimate client or a thief. So both lose
 * access and the user authorises again — annoying exactly once, and the only
 * answer that does not leave a thief holding a working session.
 *
 * It lives in one class because the two call sites had grown their own copies
 * of the SQL, and the copies had already drifted: one filtered on
 * `is_revoked = 0`, the other did not.
 */
final class TokenFamilyRevoker
{
    /**
     * Trigger labels — these end up in the log, where they are the only clue
     * to WHY a user was suddenly logged out.
     */
    public const TRIGGER_AUTH_CODE_REUSE = 'authorization_code_reuse';
    public const TRIGGER_REFRESH_TOKEN_REUSE = 'refresh_token_reuse';

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Revokes every live access and refresh token of this client + user.
     *
     * @return array{access: int, refresh: int} how many rows were actually killed
     */
    public function revoke(string $clientId, int $userId, string $trigger): array
    {
        $access = (int) $this->connection->executeStatement(
            'UPDATE tl_mcp_oauth_access_token SET is_revoked = 1, tstamp = ?
             WHERE client_id = ? AND user_id = ? AND is_revoked = 0',
            [time(), $clientId, $userId],
        );

        $refresh = (int) $this->connection->executeStatement(
            // tstamp = 0, deliberately, and this is the load-bearing detail.
            //
            // RefreshTokenRepository honours a revoked token for a minute after
            // its tstamp, because a token revoked seconds ago was almost
            // certainly just rotated by the client itself. A cascade like this
            // one revokes at exactly that moment — so stamping "now" here would
            // hand every token we just killed a further 60 seconds of life, and
            // the holder could rotate out of the revocation entirely. A row with
            // tstamp 0 can never fall inside that window.
            'UPDATE tl_mcp_oauth_refresh_token SET is_revoked = 1, tstamp = 0
             WHERE is_revoked = 0 AND access_token_identifier IN (
                 SELECT identifier FROM tl_mcp_oauth_access_token
                 WHERE client_id = ? AND user_id = ?
             )',
            [$clientId, $userId],
        );

        $this->logger->warning(
            'MCP OAuth: single-use credential presented twice — revoked every token of this client for this user.',
            [
                'trigger' => $trigger,
                'client_id' => $clientId,
                'user_id' => $userId,
                'access_tokens_revoked' => $access,
                'refresh_tokens_revoked' => $refresh,
            ],
        );

        return ['access' => $access, 'refresh' => $refresh];
    }
}
