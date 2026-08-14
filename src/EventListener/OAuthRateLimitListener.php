<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\EventListener;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Throttles the OAuth endpoints to make brute-force / spam impractical:
 *
 *   /_mcp_oauth/register  → 10 requests / hour per source IP
 *   /_mcp_oauth/token     → 60 requests / minute per source IP
 *   /_mcp_oauth/authorize → 30 requests / minute per source IP
 *
 * Uses Symfony's RateLimiter component with the in-memory cache pool —
 * fine for the single-host setup we have; switch to Redis-backed pool
 * for multi-instance deployments.
 *
 * Listens at the `kernel.request` event with priority 240 so the limit
 * check runs after the CORS listener (priority 250 — handles OPTIONS
 * preflights before we throttle them) and before the firewall.
 */
final class OAuthRateLimitListener
{
    /**
     * @var array<string, RateLimiterFactory>
     */
    private array $factories;

    public function __construct(
        RateLimiterFactory $oauthRegisterLimiter,
        RateLimiterFactory $oauthTokenLimiter,
        RateLimiterFactory $oauthAuthorizeLimiter,
    ) {
        $this->factories = [
            '/_mcp_oauth/register' => $oauthRegisterLimiter,
            '/_mcp_oauth/token' => $oauthTokenLimiter,
            '/_mcp_oauth/authorize' => $oauthAuthorizeLimiter,
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->getMethod() === 'OPTIONS') {
            return; // CORS preflight, never rate-limited
        }

        $path = $request->getPathInfo();
        $factory = $this->factories[$path] ?? null;
        if ($factory === null) {
            return;
        }

        $limiter = $factory->create($request->getClientIp() ?? 'anon');
        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            $event->setResponse(new Response(
                (string) json_encode([
                    'error' => 'rate_limited',
                    'error_description' => 'Too many requests — try again later.',
                ]),
                429,
                [
                    'Content-Type' => 'application/json',
                    'Retry-After' => (string) $retryAfter,
                    'Access-Control-Allow-Origin' => '*',
                ],
            ));
        }
    }
}
