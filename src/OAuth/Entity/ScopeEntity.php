<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Entity;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

final class ScopeEntity implements ScopeEntityInterface
{
    use EntityTrait;
    use ScopeTrait;

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }
}
