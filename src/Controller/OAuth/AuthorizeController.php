<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Controller\OAuth;

use Contao\BackendUser;
use League\OAuth2\Server\Exception\OAuthServerException;
use Netzhirsch\ContaoMcpBundle\OAuth\AuthorizationServerFactory;
use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\CimdClientProvider;
use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\CimdException;
use Netzhirsch\ContaoMcpBundle\OAuth\Entity\UserEntity;
use Netzhirsch\ContaoMcpBundle\OAuth\OAuthClientAdministration;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * OAuth 2.1 Authorization Endpoint (RFC 6749 §3.1). The browser arrives
 * here from Inspector/Claude. We:
 *
 *   1. Validate the auth request via league/oauth2-server.
 *   2. Require a logged-in Contao Backend user (redirect to login otherwise).
 *   3. Show a consent page on GET, accept consent on POST.
 *   4. On approval, mint an authorization code redirecting to the client's
 *      redirect_uri with `?code=…`.
 */
// /authorize lives outside `/contao` (so access_control `^/contao: ROLE_USER`
// doesn't redirect anonymous clients to login before our controller runs)
// but keeps `_scope: backend` so the request matcher puts us into the
// Backend firewall and `Security::getUser()` returns the BackendUser when
// the session cookie is present. We handle the "not logged in" case
// ourselves further down.
#[Route(
    '/_mcp_oauth/authorize',
    name: 'netzhirsch_mcp_oauth_authorize',
    defaults: ['_scope' => 'backend', '_token_check' => false],
    methods: ['GET', 'POST'],
)]
final class AuthorizeController
{
    /** Token-id used to bind the consent form to the user's session. */
    private const CSRF_INTENT = 'mcp_oauth_consent';

    public function __construct(
        private readonly AuthorizationServerFactory $serverFactory,
        private readonly Security $security,
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly OAuthClientAdministration $clientAdministration,
        private readonly CimdClientProvider $cimdClientProvider,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $psr17 = new Psr17Factory();
        $psrFactory = new PsrHttpFactory($psr17, $psr17, $psr17, $psr17);
        $psrRequest = $psrFactory->createRequest($request);

        $server = $this->serverFactory->createAuthorizationServer();

        // A URL-shaped client_id is a Client ID Metadata Document (CIMD): the
        // client has no registration here, and its metadata is fetched from the
        // URL it identifies itself with. This has to run BEFORE league
        // validates the request, because league resolves the client through the
        // repository and would otherwise find nothing.
        //
        // Note the parameters come from the raw request rather than from the
        // validated one — that ordering is unavoidable, and it is why
        // CimdResolver checks the URL's shape and the trust policy before
        // anything reaches the network.
        $clientId = (string) $request->query->get('client_id', '');
        if ($clientId !== '') {
            try {
                $this->cimdClientProvider->prepare(
                    $clientId,
                    $request->query->has('redirect_uri') ? (string) $request->query->get('redirect_uri') : null,
                );
            } catch (CimdException) {
                // Generic on purpose: the specific reason is in the Contao log.
                // Telling an unauthenticated caller "blocked private address"
                // versus "connection timed out" turns this endpoint into a
                // scanner for whatever the server can reach.
                return $this->renderOAuthError(OAuthServerException::invalidClient($psrRequest));
            }
        }

        // Validate query params (client_id, redirect_uri, scope, PKCE, …).
        try {
            $authRequest = $server->validateAuthorizationRequest($psrRequest);
        } catch (OAuthServerException $e) {
            return $this->renderOAuthError($e);
        }

        // We need a logged-in Contao Backend user. If not, redirect to
        // /contao/login with target_path back to here (base64 — Contao's
        // login form picks it up via the `_target_path` query param).
        $user = $this->security->getUser();
        if (!$user instanceof BackendUser) {
            $targetPath = base64_encode($request->getUri());
            $loginUrl = '/contao/login?_target_path='.$targetPath.'&_always_use_target_path=1';

            return new RedirectResponse($loginUrl);
        }

        // POST = user clicked Approve/Deny.
        if ($request->isMethod('POST')) {
            // Hardening #4: CSRF check on the consent form. Without it,
            // a malicious page in the same browser could silently submit
            // "approve=1" on the user's behalf as long as they're logged
            // into the Backend (clickjacking-style consent grab).
            $submittedToken = (string) $request->request->get('_csrf_token');
            if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_INTENT, $submittedToken))) {
                return new Response(
                    json_encode(['error' => 'invalid_csrf', 'error_description' => 'Consent form CSRF token missing or invalid.']) ?: '{}',
                    400,
                    ['Content-Type' => 'application/json'],
                );
            }

            $approved = $request->request->get('approve') === '1';
            $authRequest->setUser(new UserEntity((string) $user->id, (string) $user->username));
            $authRequest->setAuthorizationApproved($approved);

            $foundationFactory = new HttpFoundationFactory();
            try {
                $response = $server->completeAuthorizationRequest($authRequest, $psr17->createResponse());
            } catch (OAuthServerException $e) {
                return $this->renderOAuthError($e);
            }

            // Record WHO granted this consent on the client row — the admin
            // table shows it as "authorized by". Only on approval; a denial
            // must not stamp the user onto the client.
            if ($approved) {
                $this->clientAdministration->recordAuthorization(
                    $authRequest->getClient()->getIdentifier(),
                    (int) $user->id,
                    (string) $user->username,
                );
            }

            return $foundationFactory->createResponse($response);
        }

        // GET = render consent page.
        $redirectUri = (string) $authRequest->getRedirectUri();

        return new Response($this->twig->render('@ContaoMcp/oauth/authorize.html.twig', [
            'client_name' => $authRequest->getClient()->getName() ?: $authRequest->getClient()->getIdentifier(),
            'scopes' => array_map(static fn ($s) => $s->getIdentifier(), $authRequest->getScopes()),
            'username' => (string) $user->username,
            'redirect_uri' => $redirectUri,
            // Required by the MCP specification: the user must be able to see
            // WHERE approval sends the code, not just which name asked for it.
            'redirect_host' => (string) (parse_url($redirectUri, \PHP_URL_HOST) ?: $redirectUri),
            // Present only for a CIMD client: the host that vouched for this
            // client's metadata, plus the loopback warning the spec asks for.
            'cimd' => $this->cimdClientProvider->consentDetails($clientId),
            'csrf_token' => $this->csrf->getToken(self::CSRF_INTENT)->getValue(),
        ]));
    }

    private function renderOAuthError(OAuthServerException $e): Response
    {
        return new Response(
            json_encode([
                'error' => $e->getErrorType(),
                'error_description' => $e->getMessage(),
            ]) ?: '{}',
            $e->getHttpStatusCode(),
            ['Content-Type' => 'application/json'],
        );
    }
}
