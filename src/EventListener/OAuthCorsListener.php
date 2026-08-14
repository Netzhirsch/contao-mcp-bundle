<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\EventListener;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * CORS handling for /contao/oauth/* endpoints.
 *
 * OAuth flow involves cross-origin fetches from the client app
 * (Inspector at http://localhost:6274, Claude.ai, …) to our Backend
 * token / register / metadata endpoints. Browsers refuse those without
 * the right CORS headers.
 *
 * - OPTIONS preflight (`onKernelRequest`): we intercept early and return
 *   204 + CORS headers, because the controllers themselves are tagged
 *   `methods: ['POST']` and would otherwise yield 405.
 * - Every other response (`onKernelResponse`): we append the headers.
 *
 * `Allow-Origin: *` is safe here because none of these endpoints use
 * cookies for authentication — they take Bearer tokens and
 * client_id/client_secret. The browser blocks `*` only if combined with
 * credentials, which we don't enable.
 */
final class OAuthCorsListener
{
    private const PATH_PREFIX = '/_mcp_oauth/';

    private const CORS_HEADERS = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
        'Access-Control-Max-Age' => '3600',
    ];

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), self::PATH_PREFIX)) {
            return;
        }
        if ($request->getMethod() !== 'OPTIONS') {
            return;
        }

        $event->setResponse(new Response('', 204, self::CORS_HEADERS));
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), self::PATH_PREFIX)) {
            return;
        }
        $response = $event->getResponse();
        foreach (self::CORS_HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }
    }
}
