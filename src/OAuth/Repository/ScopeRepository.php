<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Repository;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Netzhirsch\ContaoMcpBundle\OAuth\Entity\ScopeEntity;

/**
 * Single hard-coded scope `mcp` — all-or-nothing access to every MCP tool.
 * Granular scopes (read/write/files/...) are a future-phase extension.
 */
final class ScopeRepository implements ScopeRepositoryInterface
{
    private const VALID_SCOPES = ['mcp'];

    public function getScopeEntityByIdentifier($identifier)
    {
        if (!\in_array((string) $identifier, self::VALID_SCOPES, true)) {
            return null;
        }
        $scope = new ScopeEntity();
        $scope->setIdentifier((string) $identifier);

        return $scope;
    }

    public function finalizeScopes(
        array $scopes,
        $grantType,
        ClientEntityInterface $clientEntity,
        $userIdentifier = null,
        $authCodeId = null
    ) {
        if ($scopes === []) {
            $scope = new ScopeEntity();
            $scope->setIdentifier('mcp');
            $scopes = [$scope];
        }

        return $scopes;
    }
}
