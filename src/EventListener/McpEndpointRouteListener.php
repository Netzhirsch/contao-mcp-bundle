<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\EventListener;

use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\Routing\EndpointPath;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * Puts the MCP endpoint where MCP-Server → Konfiguration says: `path`, "mcp"
 * unless changed.
 *
 * A #[Route] attribute fixes its path when the container is built. This path
 * is changed in the backend at runtime and has to move the endpoint the moment
 * it is saved, not after the next cache:clear — so the four routes that depend
 * on it ({@see EndpointPath::route()}) are matched here, just before Symfony's
 * RouterListener (priority 32), which leaves a request alone once it carries a
 * _controller. The attributes set, and the 405 for a method the route does not
 * take, are the ones the router sets for an ordinary route.
 *
 * It reads var/mcp/config.json on every request, about 9 µs measured.
 */
final class McpEndpointRouteListener
{
    public function __construct(
        private readonly McpServerConfigStorage $configStorage,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || $request->attributes->has('_controller')) {
            return;
        }

        // Decoded before matching, as Symfony's UrlMatcher does.
        $route = EndpointPath::route(rawurldecode($request->getPathInfo()), $this->configStorage->load()['path']);

        if ($route === null) {
            return;
        }

        if (!\in_array($request->getMethod(), $route['methods'], true)) {
            throw new MethodNotAllowedHttpException($route['methods'], sprintf(
                'No route found for "%s %s": Method Not Allowed (Allow: %s)',
                $request->getMethod(),
                $request->getUriForPath($request->getPathInfo()),
                implode(', ', $route['methods']),
            ));
        }

        $request->attributes->add([
            '_route' => $route['name'],
            '_controller' => $route['controller'],
            // What the attribute routes declared as their defaults: Contao's
            // frontend firewall and request matchers key on it.
            '_scope' => 'frontend',
            '_route_params' => ['_scope' => 'frontend'],
        ]);
    }
}
