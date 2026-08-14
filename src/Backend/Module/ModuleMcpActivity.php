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
        $this->Template->mcpActivity = $container->get(McpActivityLog::class)->recent(100);
    }
}
