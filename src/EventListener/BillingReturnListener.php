<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\EventListener;

use Contao\CoreBundle\Routing\ScopeMatcher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\RouterInterface;

/**
 * Stripe returns the customer to the backend after checkout / portal. The
 * license server points the success/cancel URL at the bare backend
 * (`/contao?mcp_billing=success|cancel`), which lands on the dashboard — where
 * our module never runs. Route that to the MCP-Server status module so it can
 * show the confirmation/cancel message (and, on success, refresh the token).
 *
 * No-op unless `mcp_billing` is present on a backend request; when the caller is
 * already on the module it steps aside so the module handles the param (no loop).
 */
final class BillingReturnListener
{
    private const MODULE = 'netzhirsch_mcp_status';

    public function __construct(
        private readonly ScopeMatcher $scopeMatcher,
        private readonly RouterInterface $router,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $billing = (string) $request->query->get('mcp_billing', '');
        if ('' === $billing || !$this->scopeMatcher->isBackendRequest($request)) {
            return;
        }
        if (self::MODULE === $request->query->get('do')) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->router->generate('contao_backend', [
            'do' => self::MODULE,
            'mcp_billing' => $billing,
        ])));
    }
}
