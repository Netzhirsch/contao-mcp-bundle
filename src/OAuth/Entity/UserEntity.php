<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Entity;

use League\OAuth2\Server\Entities\UserEntityInterface;

final class UserEntity implements UserEntityInterface
{
    public function __construct(
        private readonly string $identifier,
        public readonly string $username = '',
    ) {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }
}
