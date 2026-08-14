<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth;

use Doctrine\DBAL\Connection;

/**
 * Backend-module facade for OAuth client read + revocation. Exists so the
 * Backend module (instantiated by Contao outside the DI container, fetches
 * collaborators via System::getContainer()) doesn't have to grab
 * Doctrine\DBAL\Connection directly — that service is private and gets
 * inlined.
 *
 * Service is declared public in services.yaml.
 */
final class OAuthClientAdministration
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Returns every registered client for the admin table — newest first.
     *
     * Each row carries a display-ready `authorized_by`: the username recorded
     * at the latest consent (see {@see recordAuthorization()}); for legacy
     * rows registered before that column existed, the newest access token's
     * user is used as fallback. Empty string = never authorized.
     *
     * @return list<array<string, mixed>>
     */
    public function listClients(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.client_id, c.name, c.redirect_uris, c.is_confidential, c.created_at,
                    c.authorized_user_id, c.authorized_username, c.authorized_at,
                    u.username AS token_username, t.user_id AS token_user_id
             FROM tl_mcp_oauth_client c
             LEFT JOIN (
                 SELECT client_id, MAX(id) AS max_id
                 FROM tl_mcp_oauth_access_token
                 GROUP BY client_id
             ) lt ON lt.client_id = c.client_id
             LEFT JOIN tl_mcp_oauth_access_token t ON t.id = lt.max_id
             LEFT JOIN tl_user u ON u.id = t.user_id
             ORDER BY c.created_at DESC',
        );

        foreach ($rows as &$row) {
            $tokenUserId = (int) ($row['token_user_id'] ?? 0);
            $row['authorized_by'] = (string) ($row['authorized_username'] ?? '')
                ?: (string) ($row['token_username'] ?? '')
                ?: ($tokenUserId > 0 ? 'tl_user #'.$tokenUserId : '');
            unset($row['token_username'], $row['token_user_id']);
        }

        return $rows;
    }

    /**
     * Persists who granted consent for a client — called by
     * AuthorizeController after a successful, APPROVED authorization.
     * Last consent wins (a client is practically 1:1 with an installation;
     * a re-consent by another user is the more current truth).
     */
    public function recordAuthorization(string $clientId, int $userId, string $username): void
    {
        $this->connection->update('tl_mcp_oauth_client', [
            'authorized_user_id' => $userId,
            'authorized_username' => $username,
            'authorized_at' => time(),
            'tstamp' => time(),
        ], ['client_id' => $clientId]);
    }

    /**
     * Cascade-revokes a client: refresh tokens → access tokens → auth codes →
     * the client row itself. Returns the number of client rows deleted (0 if
     * the id wasn't found).
     */
    public function revokeClient(string $clientId): int
    {
        if ($clientId === '') {
            return 0;
        }

        // Order matters: refresh tokens reference access tokens by identifier,
        // so kill them first.
        $this->connection->executeStatement(
            'DELETE FROM tl_mcp_oauth_refresh_token
             WHERE access_token_identifier IN (
                 SELECT identifier FROM tl_mcp_oauth_access_token WHERE client_id = ?
             )',
            [$clientId],
        );
        $this->connection->executeStatement(
            'DELETE FROM tl_mcp_oauth_access_token WHERE client_id = ?',
            [$clientId],
        );
        $this->connection->executeStatement(
            'DELETE FROM tl_mcp_oauth_authcode WHERE client_id = ?',
            [$clientId],
        );

        return (int) $this->connection->executeStatement(
            'DELETE FROM tl_mcp_oauth_client WHERE client_id = ?',
            [$clientId],
        );
    }
}
