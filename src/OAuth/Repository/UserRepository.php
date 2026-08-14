<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Repository;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\UserModel;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use Netzhirsch\ContaoMcpBundle\OAuth\Entity\UserEntity;

/**
 * Resolves a user during password-grant requests — which we don't enable
 * (deprecated in OAuth 2.1). The user that flows through authorize/token
 * comes from the Symfony Security context (Backend Cookie), set by
 * AuthorizeController on consent submission.
 */
final class UserRepository implements UserRepositoryInterface
{
    public function __construct(
        private readonly ContaoFramework $framework,
    ) {
    }

    public function getUserEntityByUserCredentials(
        $username,
        $password,
        $grantType,
        ClientEntityInterface $clientEntity
    ) {
        // Password grant is disabled.
        return null;
    }

    public function findById(int $id): ?UserEntity
    {
        $this->framework->initialize();
        $user = UserModel::findById($id);
        if ($user === null) {
            return null;
        }

        return new UserEntity((string) $user->id, (string) $user->username);
    }
}
