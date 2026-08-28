<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Controller\OAuth;

use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\CimdResolver;
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
        private readonly CimdResolver $cimdResolver,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $metadata = [
            'issuer' => $request->getSchemeAndHttpHost(),
            'authorization_endpoint' => $this->absUrl('netzhirsch_mcp_oauth_authorize'),
            'token_endpoint' => $this->absUrl('netzhirsch_mcp_oauth_token'),
            'registration_endpoint' => $this->absUrl('netzhirsch_mcp_oauth_register'),
            'scopes_supported' => ['mcp'],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            // `none` is what makes CIMD usable: Claude's CIMD client
            // authenticates as a public client at the token endpoint, and it
            // only selects CIMD when this method is advertised alongside the
            // flag below. Dropping either sends every client back to DCR.
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic', 'none'],
            'code_challenge_methods_supported' => ['S256'],
        ];

        // Advertised only while the feature is actually on. A server that
        // claims CIMD support and then refuses every document strands clients
        // that would have fallen back to registration had we stayed quiet.
        if ($this->cimdResolver->isEnabled()) {
            $metadata['client_id_metadata_document_supported'] = true;
        }

        return new JsonResponse($metadata);
    }

    private function absUrl(string $route): string
    {
        return $this->router->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
