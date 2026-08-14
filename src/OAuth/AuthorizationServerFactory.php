<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;
use Netzhirsch\ContaoMcpBundle\OAuth\Repository\AccessTokenRepository;
use Netzhirsch\ContaoMcpBundle\OAuth\Repository\AuthCodeRepository;
use Netzhirsch\ContaoMcpBundle\OAuth\Repository\ClientRepository;
use Netzhirsch\ContaoMcpBundle\OAuth\Repository\RefreshTokenRepository;
use Netzhirsch\ContaoMcpBundle\OAuth\Repository\ScopeRepository;

/**
 * Builds league/oauth2-server's AuthorizationServer (for the Backend
 * controllers that issue codes & tokens) and ResourceServer (for the
 * MCP daemon that validates incoming JWTs).
 *
 * Configured grants:
 *   - AuthCodeGrant with PKCE (the only modern, MCP-Inspector-friendly flow)
 *   - RefreshTokenGrant (so clients don't have to re-authorize every hour)
 *
 * Token TTLs:
 *   - authorization code: 10 minutes (short, code is single-use)
 *   - access token:       1 hour    (short, encourages refresh)
 *   - refresh token:      30 days
 */
final class AuthorizationServerFactory
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly AccessTokenRepository $accessTokens,
        private readonly ScopeRepository $scopes,
        private readonly AuthCodeRepository $authCodes,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly KeyManager $keys,
    ) {
    }

    public function createAuthorizationServer(): AuthorizationServer
    {
        $server = new AuthorizationServer(
            $this->clients,
            $this->accessTokens,
            $this->scopes,
            $this->keys->privateCryptKey(),
            $this->keys->encryptionKey(),
        );

        $authCodeGrant = new AuthCodeGrant(
            $this->authCodes,
            $this->refreshTokens,
            new \DateInterval('PT10M'),  // auth code TTL
        );
        $authCodeGrant->setRefreshTokenTTL(new \DateInterval('P30D'));
        // Hardening #1: enforce PKCE for public clients (no client_secret).
        // Without this, league/oauth2-server lets clients skip the
        // code_challenge entirely and a stolen auth code is enough to mint
        // tokens. Inspector / Claude Desktop / mcp-remote all support PKCE
        // already, so this is purely defensive.
        // Note: league 8.x has `disableRequireCodeChallengeForPublicClients`
        // (i.e. PKCE for public clients is ON by default). We make that
        // intention explicit by NOT calling the disable method anywhere.
        $server->enableGrantType($authCodeGrant, new \DateInterval('PT1H'));

        // Hardening #6: refresh-token rotation. Each /token exchange issues
        // a fresh refresh token; the old one is revoked. If a refresh
        // token ever leaks, the attacker has at most one window before the
        // legitimate client rotates and locks them out. league/oauth2-server
        // rotates refresh tokens by default — RefreshTokenRepository's
        // `revokeRefreshToken()` is called at every use.
        $refreshGrant = new RefreshTokenGrant($this->refreshTokens);
        $refreshGrant->setRefreshTokenTTL(new \DateInterval('P30D'));
        $server->enableGrantType($refreshGrant, new \DateInterval('PT1H'));

        return $server;
    }

    public function createResourceServer(): ResourceServer
    {
        return new ResourceServer(
            $this->accessTokens,
            $this->keys->publicCryptKey(),
        );
    }

    /**
     * A ResourceServer wired against the PREVIOUS public key. Used by
     * {@see \Netzhirsch\ContaoMcpBundle\Service\McpOAuthValidator} as a
     * fallback during the rollover window after `contao:mcp:oauth:rotate-keys`:
     * tokens signed under the old key still validate until they expire
     * (1h for access tokens) or the operator runs `--prune-old`.
     *
     * Returns null when there is no previous key — caller should treat
     * this as "current key only" without raising an error.
     */
    public function createResourceServerWithPreviousKey(): ?ResourceServer
    {
        $previousKey = $this->keys->previousPublicCryptKey();
        if ($previousKey === null) {
            return null;
        }
        return new ResourceServer($this->accessTokens, $previousKey);
    }
}
