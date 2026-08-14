<?php

declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;

/*
 * Per-user toggle that grants access to the MCP server at all.
 *
 * Security model (see McpPermissionGuard): a non-admin backend user may only
 * use the MCP server when THIS checkbox is set on their account OR on one of
 * their groups (tl_user_group.netzhirschMcpAccess). Admins are always allowed
 * and don't need the flag. Default 0 = secure-by-default: after the migration
 * every non-admin is locked out of MCP until explicitly enabled.
 *
 * This is a coarse on/off gate. Once past it, fine-grained authorisation
 * mirrors the user's real Contao backend permissions (module/table/row/field)
 * via Contao's own security voters.
 */
$GLOBALS['TL_DCA']['tl_user']['fields']['netzhirschMcpAccess'] = [
    'exclude'   => true,
    'inputType' => 'checkbox',
    'eval'      => ['tl_class' => 'w50'],
    'sql'       => ['type' => 'boolean', 'default' => false],
];

// Only show the field in palettes where the user's OWN permissions are
// editable — i.e. 'extend' (extend group rights) and 'custom' (own rights).
// Under 'group' ("Nur Gruppenrechte verwenden") Contao hides every own
// permission field and only the group governs; the MCP flag behaves the
// same way (BackendUserContext::hasMcpAccess() ignores the own flag there).
// 'account_legend' exists in both target palettes, so it's a safe anchor.
PaletteManipulator::create()
    ->addLegend('netzhirsch_mcp_legend', 'account_legend', PaletteManipulator::POSITION_BEFORE)
    ->addField('netzhirschMcpAccess', 'netzhirsch_mcp_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('extend', 'tl_user')
    ->applyToPalette('custom', 'tl_user')
;
