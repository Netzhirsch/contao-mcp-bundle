<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Entity;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

/**
 * OAuth 2.1 client (RFC 6749). One row per registered consumer of the
 * MCP API — e.g. one for Claude Desktop, one for the Inspector, one for
 * an external script. Created via /oauth/register (Dynamic Client
 * Registration, RFC 7591).
 */
final class ClientEntity implements ClientEntityInterface
{
    use EntityTrait;
    use ClientTrait;

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * @param string|list<string> $redirectUris
     */
    public function setRedirectUri(string|array $redirectUris): void
    {
        $this->redirectUri = $redirectUris;
    }

    public function setConfidential(bool $isConfidential = true): void
    {
        $this->isConfidential = $isConfidential;
    }
}
