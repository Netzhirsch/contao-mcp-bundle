<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Controller\OAuth;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RFC 8414: OAuth 2.0 Authorization Server Metadata. Inspector and
 * Claude.ai's Custom Connectors hit this first to discover the
 * authorize/token/register endpoints.
 */
// Public endpoint, outside `/contao` so Contao's access_control
// `^/contao: ROLE_USER` rule doesn't lock unauthenticated clients out
// of OAuth discovery.
#[Route(
    '/_mcp_oauth/.well-known/oauth-authorization-server',
    name: 'netzhirsch_mcp_oauth_metadata',
    methods: ['GET'],
)]
final class MetadataController
{
    public function __construct(
        private readonly RouterInterface $router,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse([
            'issuer' => $request->getSchemeAndHttpHost(),
            'authorization_endpoint' => $this->absUrl('netzhirsch_mcp_oauth_authorize'),
            'token_endpoint' => $this->absUrl('netzhirsch_mcp_oauth_token'),
            'registration_endpoint' => $this->absUrl('netzhirsch_mcp_oauth_register'),
            'scopes_supported' => ['mcp'],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic', 'none'],
            'code_challenge_methods_supported' => ['S256'],
        ]);
    }

    private function absUrl(string $route): string
    {
        return $this->router->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
