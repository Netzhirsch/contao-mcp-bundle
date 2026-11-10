<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\EventListener;

use Contao\CoreBundle\Event\MenuEvent;
use Knp\Menu\Util\MenuManipulator;
use Netzhirsch\ContaoMcpBundle\Backend\ClaudeButton;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Puts the "Claude" button into the backend header, right before "Preview",
 * for every backend user the MCP server would let in. What it does on a
 * click is in {@see ClaudeButton} and public/claude-button.js.
 *
 * Runs after Contao's own preview listener (priority -48), so the preview
 * item is already there to be placed in front of.
 */
#[AsEventListener(priority: -49)]
final class ClaudeButtonMenuListener
{
    public function __construct(
        private readonly ClaudeButton $button,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function __invoke(MenuEvent $event): void
    {
        $tree = $event->getTree();
        if ($tree->getName() !== 'headerMenu') {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$this->button->isAvailableFor($this->button->currentUserId())) {
            return;
        }

        $item = $event->getFactory()
            ->createItem('netzhirsch_mcp_claude')
            ->setLabel('mcp_server.claude_button_label')
            ->setUri('#')
            ->setLinkAttribute('class', 'netzhirsch-mcp-claude')
            ->setLinkAttribute('title', $this->button->translate('claude_button_title'))
            ->setLinkAttribute('role', 'button')
            ->setLinkAttribute('aria-haspopup', 'dialog')
            ->setLinkAttribute('data-mcp-claude', $this->button->backendPayload($request))
            ->setExtra('translation_domain', ClaudeButton::DOMAIN)
        ;

        $tree->addChild($item);

        $position = array_search('preview', array_keys($tree->getChildren()), true);
        (new MenuManipulator())->moveToPosition($item, \is_int($position) ? $position : max(0, $tree->count() - 3));

        // The backend template turns these into tags after the menus are
        // rendered, so registering them here still reaches the page. Under
        // Turbo the script stays loaded across navigations; it listens on the
        // document, not on the button.
        $GLOBALS['TL_JAVASCRIPT']['netzhirsch_mcp_claude'] = 'bundles/contaomcp/claude-button.js|static';
        $GLOBALS['TL_CSS']['netzhirsch_mcp_claude'] = 'bundles/contaomcp/claude-button.css|static';
    }
}
