<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Repository;

use Doctrine\DBAL\Connection;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use Netzhirsch\ContaoMcpBundle\OAuth\Entity\AuthCodeEntity;

final class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function getNewAuthCode()
    {
        return new AuthCodeEntity();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity)
    {
        $scopes = [];
        foreach ($authCodeEntity->getScopes() as $scope) {
            $scopes[] = $scope->getIdentifier();
        }

        $this->connection->insert('tl_mcp_oauth_authcode', [
            'tstamp' => time(),
            'identifier' => $authCodeEntity->getIdentifier(),
            'client_id' => $authCodeEntity->getClient()->getIdentifier(),
            'user_id' => (int) $authCodeEntity->getUserIdentifier(),
            'redirect_uri' => $authCodeEntity->getRedirectUri(),
            'scopes' => json_encode($scopes, \JSON_UNESCAPED_SLASHES) ?: '[]',
            'expires_at' => $authCodeEntity->getExpiryDateTime()->getTimestamp(),
            'is_revoked' => 0,
        ]);
    }

    public function revokeAuthCode($codeId)
    {
        $this->connection->update(
            'tl_mcp_oauth_authcode',
            ['is_revoked' => 1, 'tstamp' => time()],
            ['identifier' => (string) $codeId],
        );
    }

    public function isAuthCodeRevoked($codeId)
    {
        $row = $this->connection->fetchAssociative(
            'SELECT is_revoked, client_id, user_id FROM tl_mcp_oauth_authcode WHERE identifier = ?',
            [(string) $codeId],
        );
        if ($row === false) {
            return true;
        }

        $revoked = (bool) $row['is_revoked'];

        // Hardening #12 (OAuth 2.1 §4.1.2): if a client tries to redeem
        // the same authorization code twice, treat it as a leaked-code
        // attack and cascade-revoke every token derived from that
        // client + user. The legit client is locked out (they'll need to
        // re-authorize) but the attacker can't pivot any further.
        if ($revoked) {
            $clientId = (string) $row['client_id'];
            $userId = (int) $row['user_id'];

            $this->connection->executeStatement(
                'UPDATE tl_mcp_oauth_access_token SET is_revoked = 1, tstamp = ?
                 WHERE client_id = ? AND user_id = ? AND is_revoked = 0',
                [time(), $clientId, $userId],
            );
            // Refresh tokens reference access tokens by identifier — kill
            // anything pointing at one of this client+user's tokens.
            $this->connection->executeStatement(
                'UPDATE tl_mcp_oauth_refresh_token SET is_revoked = 1, tstamp = ?
                 WHERE access_token_identifier IN (
                     SELECT identifier FROM tl_mcp_oauth_access_token
                     WHERE client_id = ? AND user_id = ?
                 )',
                [time(), $clientId, $userId],
            );
        }

        return $revoked;
    }
}
