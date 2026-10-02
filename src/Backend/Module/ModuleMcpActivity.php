<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Backend\Module;

use Netzhirsch\ContaoMcpBundle\Backend\McpActivityLog;
use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * MCP-Server → Aktivität: the last 100 tl_log entries with source 'mcp%' as
 * a plain Contao listing — "what has the AI been doing" without unfiltering
 * the global system log.
 */
class ModuleMcpActivity extends AbstractMcpModule
{
    /**
     * @var string
     */
    protected $strTemplate = 'be_mcp_activity';

    protected function compileModule(ContainerInterface $container, McpServerConfigStorage $configStorage, array $config): void
    {
        // Formatted here, not with Twig's |date — that one need not run in the
        // same time zone as PHP's date().
        $this->Template->mcpActivity = array_map(
            static fn (array $entry): array => [...$entry, 'date' => date('Y-m-d H:i:s', (int) $entry['tstamp'])],
            $container->get(McpActivityLog::class)->recent(100),
        );
    }
}
