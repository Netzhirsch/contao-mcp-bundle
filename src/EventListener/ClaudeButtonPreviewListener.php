<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\EventListener;

use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Backend\ClaudeButton;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * The "Claude" button on front-end pages, while a backend user looks at them
 * through the preview — bottom right, the same form as in the backend, with
 * the page as context.
 *
 * Visitors never see it. Three things have to hold before anything is added:
 * the request comes through the preview entry point with a logged-in backend
 * user, that user is one the MCP server lets in, and the response is already
 * private — so it can never land in the shared page cache and be served to
 * someone else.
 *
 * Runs after the listener that decides it: Contao switches Symfony's own
 * automatic cache control off (-896) and makes the response private itself in
 * MakeResponsePrivateListener::makeResponsePrivate() at -1012. Checked any
 * earlier, a preview response is not private yet and the button would never
 * appear.
 */
#[AsEventListener(priority: -1020)]
final class ClaudeButtonPreviewListener
{
    public function __construct(
        private readonly ClaudeButton $button,
        private readonly ScopeMatcher $scopeMatcher,
        private readonly TokenChecker $tokenChecker,
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!$event->isMainRequest()
            || !$this->scopeMatcher->isFrontendRequest($request)
            || $response instanceof StreamedResponse
            || $response instanceof BinaryFileResponse
            || $response->getStatusCode() !== 200
            || !str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')
            || !$response->headers->hasCacheControlDirective('private')
            // The preview entry point, not TokenChecker::isPreviewMode() —
            // that one also wants the "show unpublished" session and is false
            // in a plain preview.
            || !$request->attributes->get('_preview', false)
        ) {
            return;
        }

        $content = $response->getContent();
        if (!\is_string($content) || ($bodyEnd = strripos($content, '</body>')) === false) {
            return;
        }

        // A backend user, not a guest who came in through a preview link.
        $username = $this->tokenChecker->getBackendUsername();
        $userId = $username !== null ? (int) $this->connection->fetchOne('SELECT id FROM tl_user WHERE username = ?', [$username]) : 0;
        if (!$this->button->isAvailableFor($userId)) {
            return;
        }

        $page = $request->attributes->get('pageModel');
        $payload = $this->button->frontendPayload(
            $request,
            $page instanceof PageModel ? (string) ($page->pageTitle ?: $page->title) : '',
            $page instanceof PageModel ? (int) $page->id : 0,
        );

        $base = rtrim($request->getBasePath(), '/').'/bundles/contaomcp/';
        $markup = \sprintf(
            '<link rel="stylesheet" href="%1$sclaude-button.css">'
            .'<button type="button" class="netzhirsch-mcp-claude netzhirsch-mcp-claude--floating" aria-haspopup="dialog" title="%2$s" data-mcp-claude="%3$s">%4$s</button>'
            .'<script src="%1$sclaude-button.js" defer></script>',
            htmlspecialchars($base, ENT_QUOTES),
            htmlspecialchars($this->button->translate('claude_button_title'), ENT_QUOTES),
            htmlspecialchars($payload, ENT_QUOTES),
            htmlspecialchars($this->button->translate('claude_button_label'), ENT_QUOTES),
        );

        $response->setContent(substr($content, 0, $bodyEnd).$markup.substr($content, $bodyEnd));
        $response->headers->remove('Content-Length');
    }
}
