<?php

declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;

/*
 * Group-level counterpart to tl_user.netzhirschMcpAccess. When set, every
 * member of the group may use the MCP server (subject to their fine-grained
 * backend permissions). The guard grants MCP access if the user's own flag
 * OR any of their groups' flag is set. Default 0 = secure-by-default.
 */
$GLOBALS['TL_DCA']['tl_user_group']['fields']['netzhirschMcpAccess'] = [
    'exclude'   => true,
    'inputType' => 'checkbox',
    'eval'      => ['tl_class' => 'w50'],
    'sql'       => ['type' => 'boolean', 'default' => false],
];

PaletteManipulator::create()
    ->addLegend('netzhirsch_mcp_legend', 'account_legend', PaletteManipulator::POSITION_BEFORE)
    ->addField('netzhirschMcpAccess', 'netzhirsch_mcp_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('default', 'tl_user_group')
;
