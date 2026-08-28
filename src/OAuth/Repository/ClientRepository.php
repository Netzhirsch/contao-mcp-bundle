<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Repository;

use Doctrine\DBAL\Connection;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\CimdClientProvider;
use Netzhirsch\ContaoMcpBundle\OAuth\Entity\ClientEntity;

/**
 * Method signatures match league/oauth2-server 8.x interface (untyped) —
 * see AccessTokenRepository for the same compatibility note.
 */
final class ClientRepository implements ClientRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CimdClientProvider $cimdClientProvider,
    ) {
    }

    public function getClientEntity($clientIdentifier)
    {
        // A CIMD client prepared earlier in THIS request wins over the stored
        // row, because it may carry the concrete loopback redirect URI of the
        // request in progress — the port a native client binds per session and
        // that must never be persisted. See CimdClientProvider.
        $pending = $this->cimdClientProvider->pending((string) $clientIdentifier);
        if ($pending !== null) {
            return $pending;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM tl_mcp_oauth_client WHERE client_id = ?',
            [(string) $clientIdentifier],
        );
        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function validateClient($clientIdentifier, $clientSecret, $grantType)
    {
        $row = $this->connection->fetchAssociative(
            'SELECT client_secret_hash, is_confidential FROM tl_mcp_oauth_client WHERE client_id = ?',
            [(string) $clientIdentifier],
        );
        if ($row === false) {
            return false;
        }
        if (!$row['is_confidential']) {
            return true;
        }
        if ($clientSecret === null || $clientSecret === '') {
            return false;
        }

        return password_verify((string) $clientSecret, (string) $row['client_secret_hash']);
    }

    /**
     * @param list<string> $redirectUris
     */
    public function create(
        string $clientId,
        string $name,
        array $redirectUris,
        bool $isConfidential,
        ?string $plainSecret = null,
    ): int {
        $this->connection->insert('tl_mcp_oauth_client', [
            'tstamp' => time(),
            'client_id' => $clientId,
            'client_secret_hash' => $plainSecret !== null && $plainSecret !== ''
                ? password_hash($plainSecret, \PASSWORD_DEFAULT)
                : '',
            'name' => $name,
            'redirect_uris' => json_encode(array_values($redirectUris), \JSON_UNESCAPED_SLASHES) ?: '[]',
            'is_confidential' => $isConfidential ? 1 : 0,
            'created_at' => time(),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ClientEntity
    {
        $client = new ClientEntity();
        $client->setIdentifier((string) $row['client_id']);
        $client->setName((string) $row['name']);
        $redirectUris = json_decode((string) ($row['redirect_uris'] ?? '[]'), true);
        $client->setRedirectUri(\is_array($redirectUris) ? array_values(array_map('strval', $redirectUris)) : []);
        $client->setConfidential((bool) $row['is_confidential']);

        return $client;
    }
}
