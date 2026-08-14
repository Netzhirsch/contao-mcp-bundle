<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

/**
 * Request-scoped identity holder for the long-running MCP daemon.
 *
 * Each PHP-FPM worker serves one MCP request at a time, so a process-wide
 * singleton is safe as long as we write the identity on every authenticated
 * request and clear it when no identity applies. The OAuth auth-validator
 * (McpController) does both before the tool dispatch.
 *
 * Lifecycle per request:
 *   - auth_mode=oauth, valid JWT  → setIdentity(...)   ← Tool sees real user
 *   - auth_mode=oauth, no/bad JWT → request rejected, no tool call happens
 *   - auth_mode=none              → clear()            ← Tool falls back to default
 *
 * Read by AuthorResolver, which is injected into every Tool.
 */
final class McpCallContext
{
    private ?int $userId = null;
    private ?string $clientId = null;
    private ?string $clientName = null;
    private ?string $accessTokenId = null;

    public function setIdentity(
        int $userId,
        string $clientId,
        ?string $clientName,
        ?string $accessTokenId = null,
    ): void {
        $this->userId = $userId > 0 ? $userId : null;
        $this->clientId = $clientId !== '' ? $clientId : null;
        $this->clientName = ($clientName !== null && $clientName !== '') ? $clientName : null;
        $this->accessTokenId = ($accessTokenId !== null && $accessTokenId !== '') ? $accessTokenId : null;
    }

    public function clear(): void
    {
        $this->userId = null;
        $this->clientId = null;
        $this->clientName = null;
        $this->accessTokenId = null;
    }

    public function isAuthenticated(): bool
    {
        return $this->userId !== null;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function getClientName(): ?string
    {
        return $this->clientName;
    }

    public function getAccessTokenId(): ?string
    {
        return $this->accessTokenId;
    }
}
