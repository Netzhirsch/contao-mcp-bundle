<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;

/**
 * Lädt die Backend-CSS des Bundles (Icon der Menügruppe "MCP-Server") auf
 * jeder Backend-Seite. Der getUserNavigation-Hook läuft backend-only —
 * anders als ein Load über contao/config/config.php landet die CSS damit
 * nie im Frontend.
 */
#[AsHook('getUserNavigation')]
class BackendCssListener
{
    /**
     * @param array<string, mixed> $modules
     *
     * @return array<string, mixed>
     */
    public function __invoke(array $modules, bool $showAll): array
    {
        $GLOBALS['TL_CSS']['contaomcp_backend'] = 'bundles/contaomcp/backend.css|static';

        return $modules; // Navigation unverändert zurückgeben — sonst verschwindet sie!
    }
}
