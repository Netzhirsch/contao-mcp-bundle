<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Backend;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\System;

/**
 * Adds the two external-id columns (`external_id_namespace`, `external_id_key`)
 * to every Contao entity table the MCP bundle exposes for write operations.
 *
 * Design choice — decentral over central:
 *   Earlier versions used a single `tl_mcp_external_ref` lookup table. That
 *   had the cross-table-UNION advantage but two real footguns:
 *     1. Cascade-delete: when the Contao backend deleted a row, the mapping
 *        survived as a dangling pointer (no FK, no listener).
 *     2. Operator inspection: a SQL operator looking at `tl_news` had no
 *        signal that the row was manifest-managed.
 *   Decentral columns flip both: the mapping lives in the row, dies with it,
 *   and is visible in any normal SELECT. Cost is the cross-table-UNION cost
 *   for `external_ids_list(namespace)` without `table` filter — acceptable
 *   since the typical caller knows its target table.
 *
 * Hook timing — `loadDataContainer` fires once per table as Contao bootstraps
 * the DCA tree. Adding fields here means Contao's `contao:migrate` picks them
 * up on the next run and emits the ALTER TABLE statements. No separate
 * Doctrine-DBAL migration needed for the additive schema.
 *
 * The fields appear in the Backend edit-mask under an "external_id_legend"
 * fieldset, collapsed by default (`:hide`). Operators who edit a news entry
 * manually see them in the expert area — read-only by convention but
 * editable; the inputs are deliberately not `readonly` so a human can fix a
 * broken pipeline binding without dropping into SQL.
 *
 * UNIQUE index `(external_id_namespace, external_id_key)` is per-table — same
 * `(ns, key)` can exist in `tl_news` AND `tl_content` (table is part of the
 * logical mapping key, see briefing §6.4).
 */
#[AsHook('loadDataContainer')]
final class ExternalIdDcaInjector
{
    /**
     * Whitelist of tables that receive external-id columns. These are exactly
     * the tables for which the bundle exposes write tools. Read-only tables
     * (`tl_user`, `tl_user_group`) are not in the list — manifest-driven
     * backend permissions are a security anti-pattern.
     *
     * Extension tables (`tl_url_rewrite`, `tl_newsletter_*`, `tl_comments`)
     * only have their DCA loaded when the host extension bundle is present —
     * the hook simply never fires for those tables otherwise, so we don't
     * need an `if (extension_loaded)` check here.
     *
     * Keep this list in sync with the Tool class's SUPPORTED_TABLES constant.
     */
    public const SUPPORTED_TABLES = [
        // Theme world
        'tl_theme',
        'tl_image_size',
        'tl_image_size_item',
        'tl_layout',
        // Site structure
        'tl_page',
        'tl_article',
        'tl_content',
        'tl_module',
        // Files / DBAFS
        'tl_files',
        // Forms
        'tl_form',
        'tl_form_field',
        // Members
        'tl_member',
        'tl_member_group',
        // News
        'tl_news_archive',
        'tl_news',
        // Calendar
        'tl_calendar',
        'tl_calendar_events',
        // FAQ
        'tl_faq_category',
        'tl_faq',
        // Extensions (optional)
        'tl_url_rewrite',
        'tl_newsletter_channel',
        'tl_newsletter',
        'tl_newsletter_recipients',
        'tl_comments',
    ];

    public function __invoke(string $table): void
    {
        if (!\in_array($table, self::SUPPORTED_TABLES, true)) {
            return;
        }
        if (!isset($GLOBALS['TL_DCA'][$table]) || !\is_array($GLOBALS['TL_DCA'][$table])) {
            return;
        }

        // The field + legend labels live in the shared MSC.* namespace
        // (contao/languages/<lang>/default.xlf). loadDataContainer can fire
        // before the global `default` language file has been loaded for the
        // active backend language — without this the references below would
        // resolve against an empty MSC array and the backend would show the
        // raw field/legend keys. loadLanguageFile() is memoised, so calling it
        // here on every supported table is cheap.
        System::loadLanguageFile('default');

        $this->addFields($table);
        $this->addUniqueIndex($table);
        $this->appendToPalettes($table);
        $this->addLegendLabel($table);
    }

    /**
     * Contao resolves a palette legend's title from
     * `$GLOBALS['TL_LANG'][$table][<legend>]` per-table (see DC_Table::edit —
     * there is NO MSC fallback). Our translation lives once in
     * MSC.external_id_legend, so mirror it into every supported table's own
     * language namespace; otherwise the fieldset header renders the raw key
     * "external_id_legend". Idempotent on DCA reload.
     */
    private function addLegendLabel(string $table): void
    {
        if (isset($GLOBALS['TL_LANG'][$table]['external_id_legend'])) {
            return;
        }

        $GLOBALS['TL_LANG'][$table]['external_id_legend'] = $GLOBALS['TL_LANG']['MSC']['external_id_legend'] ?? 'External ID';
    }

    /**
     * The two fields. Both are nullable strings: NULL = "this row is not
     * managed by any external pipeline". MySQL/MariaDB allow multiple NULL
     * combinations in a UNIQUE index by default, so unmanaged rows do not
     * collide with each other.
     */
    private function addFields(string $table): void
    {
        // Field labels are looked up live from $GLOBALS['TL_LANG']['MSC'] so
        // the XLF translation reload (`System::loadLanguageFile('default')`)
        // can update them without reloading the DCA.
        $GLOBALS['TL_DCA'][$table]['fields']['external_id_namespace'] = [
            'label' => &$GLOBALS['TL_LANG']['MSC']['external_id_namespace'],
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'sorting' => true,
            'flag' => 11,                  // sort ascending
            'eval' => [
                'maxlength' => 64,
                'tl_class' => 'w50',
                'decodeEntities' => true,
            ],
            'sql' => [
                'type' => 'string',
                'length' => 64,
                'notnull' => false,
                'default' => null,
            ],
        ];

        $GLOBALS['TL_DCA'][$table]['fields']['external_id_key'] = [
            'label' => &$GLOBALS['TL_LANG']['MSC']['external_id_key'],
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'sorting' => false,
            'eval' => [
                'maxlength' => 255,
                'tl_class' => 'w50',
                'decodeEntities' => true,
            ],
            'sql' => [
                'type' => 'string',
                'length' => 255,
                'notnull' => false,
                'default' => null,
            ],
        ];
    }

    /**
     * Composite UNIQUE on (namespace, key) — duplicate mapping within the same
     * table is rejected at the DB layer. Contao's `contao:migrate` reads this
     * from `config.sql.keys` and emits the matching ADD INDEX statement.
     */
    private function addUniqueIndex(string $table): void
    {
        $GLOBALS['TL_DCA'][$table]['config']['sql']['keys']['external_id_namespace,external_id_key'] = 'unique';
    }

    /**
     * Append a collapsed "external_id_legend" subgroup to every concrete
     * palette of the table. We deliberately touch every palette (not just
     * `default`) — DCAs like tl_module / tl_content use dynamic palettes per
     * type, and the operator should see the field regardless of which type
     * is currently being edited.
     *
     * We skip:
     *   - `__selector__` (not a palette, a meta-key listing fields that drive
     *     palette selection).
     *   - Non-string palettes (subpalette arrays, etc.).
     *   - Palettes that already mention the legend (idempotency on reload).
     */
    private function appendToPalettes(string $table): void
    {
        $suffix = ';{external_id_legend:hide},external_id_namespace,external_id_key';

        $palettes = &$GLOBALS['TL_DCA'][$table]['palettes'];

        if (!\is_array($palettes)) {
            return;
        }

        foreach ($palettes as $name => $palette) {
            if ($name === '__selector__' || !\is_string($palette)) {
                continue;
            }
            if (str_contains($palette, 'external_id_legend')) {
                continue;
            }
            $palettes[$name] = $palette.$suffix;
        }
    }
}
