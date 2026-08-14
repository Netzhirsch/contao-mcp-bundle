<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Controller\OAuth;

use League\OAuth2\Server\Exception\OAuthServerException;
use Netzhirsch\ContaoMcpBundle\OAuth\AuthorizationServerFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OAuth 2.1 Token Endpoint (RFC 6749 §3.2). Client posts authorization
 * code + PKCE code_verifier, gets back an access_token (JWT) + refresh_token.
 * Also handles refresh_token grants.
 *
 * No CSRF check — this endpoint takes JSON / form-encoded bodies from
 * non-browser clients and is protected by client credentials / PKCE.
 */
// Public endpoint — token exchange is protected by PKCE + the
// auth code itself, not by a Contao backend session.
#[Route(
    '/_mcp_oauth/token',
    name: 'netzhirsch_mcp_oauth_token',
    methods: ['POST'],
)]
final class TokenController
{
    public function __construct(
        private readonly AuthorizationServerFactory $serverFactory,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $psr17 = new Psr17Factory();
        $psrFactory = new PsrHttpFactory($psr17, $psr17, $psr17, $psr17);
        $psrRequest = $psrFactory->createRequest($request);
        $foundationFactory = new HttpFoundationFactory();

        $server = $this->serverFactory->createAuthorizationServer();

        try {
            $response = $server->respondToAccessTokenRequest($psrRequest, $psr17->createResponse());
        } catch (OAuthServerException $e) {
            return new Response(
                json_encode([
                    'error' => $e->getErrorType(),
                    'error_description' => $e->getMessage(),
                ]) ?: '{}',
                $e->getHttpStatusCode(),
                ['Content-Type' => 'application/json'],
            );
        }

        return $foundationFactory->createResponse($response);
    }
}
