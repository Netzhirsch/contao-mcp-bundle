<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce;

use Netzhirsch\ContaoMcpBundle\Service\DcaPalette;

/**
 * The regular columns an RSCE type shows next to its own fields.
 *
 * RSCE assembles the palette in the same onload callback that creates its
 * virtual fields (CustomElements::createDca → generatePalette). A DCA loaded
 * outside the edit mask therefore has no palette for the type at all, and every
 * column looked foreign to the write tools: headline, customTpl, the image
 * toggle — refused on an element whose backend form shows them.
 *
 * This rebuilds that palette from the type's config the way RSCE 2.5 does, minus
 * the virtual `rsce_field_*` entries. Those are not columns; their values live in
 * rsce_data, which the table's provider writes. RSCE builds a different palette
 * per table, and so does this:
 *
 *   tl_content      type (+ title where Contao has it); from `standardFields`
 *                   headline, columns (rocksolid-columns), text, slider
 *                   (rocksolid-slider), image, cssID; customTpl, protected,
 *                   guests, invisible, start, stop
 *   tl_module       name, type; headline and cssID from `standardFields`;
 *                   customTpl, protected, guests
 *   tl_form_field   type; columns and text from `standardFields`; class,
 *                   customTpl
 *
 * plus, everywhere, the column of every `standardField` entry in `fields`.
 * Sub-palettes (addImage → singleSRC, …) are not listed here; DcaPalette expands
 * them from the live DCA like for any other type.
 */
final class RscePalette
{
    /**
     * @param array<string, mixed> $config the type's rsce_*_config.php
     * @param array<string, mixed> $dca    the loaded DCA of $table
     */
    public static function build(array $config, array $dca, bool $sliderInstalled, string $table = 'tl_content'): string
    {
        $standard = \is_array($config['standardFields'] ?? null)
            ? array_map(static fn (mixed $f): string => \is_scalar($f) ? (string) $f : '', $config['standardFields'])
            : [];
        $columns = \is_array($dca['fields'] ?? null) ? $dca['fields'] : [];
        $palettes = \is_array($dca['palettes'] ?? null) ? $dca['palettes'] : [];
        $has = static fn (string $field): bool => \in_array($field, $standard, true);

        $fields = match ($table) {
            'tl_module' => $has('headline') ? ['name', 'headline', 'type'] : ['name', 'type'],
            default => ['type'],
        };

        if ($table === 'tl_content') {
            // Contao 5.6 added the element title; RSCE puts it into the palette
            // from that version on. Asking the DCA answers the same question
            // without a version check.
            if (isset($columns['title'])) {
                $fields[] = 'title';
            }
            if ($has('headline')) {
                $fields[] = 'headline';
            }
        }

        if ($table !== 'tl_module') {
            if ($has('columns')) {
                $fields = [...$fields, ...self::after((string) ($palettes['rs_columns_start'] ?? ''), '{rs_columns_legend},', ';')];
            }
            if ($has('text')) {
                $fields[] = 'text';
            }
        }

        if ($table === 'tl_content' && $sliderInstalled && $has('slider')) {
            // RSCE picks one of two slider palettes from the record's current
            // settings. Which one is open is edit-mask state; the write path
            // takes both, as DcaPalette does for every select-style toggle.
            $slider = [
                'rsce_slider',
                ...self::after((string) ($palettes['rocksolid_sliderrsts_import_settingsrsts_default'] ?? ''), '{rocksolid_slider_legend},', ';'),
                ...self::after((string) ($palettes['rocksolid_sliderrsts_default'] ?? ''), '{rocksolid_slider_legend},', ';{template_legend'),
            ];
            $fields = [...$fields, ...array_diff($slider, ['rsts_id', 'rsts_thumbs_imgSize'])];
        }

        foreach (\is_array($config['fields'] ?? null) ? $config['fields'] : [] as $name => $field) {
            if (\is_array($field) && ($field['inputType'] ?? null) === 'standardField' && isset($columns[$name])) {
                $fields[] = (string) $name;
            }
        }

        if ($table === 'tl_content' && $has('image')) {
            $fields[] = 'addImage';
        }

        if ($table === 'tl_form_field') {
            $fields[] = 'class';
        }

        $fields[] = 'customTpl';

        if ($table !== 'tl_form_field') {
            $fields = [...$fields, 'protected', 'guests'];
            if ($has('cssID')) {
                $fields[] = 'cssID';
            }
        }

        if ($table === 'tl_content') {
            $fields = [...$fields, 'invisible', 'start', 'stop'];
        }

        return implode(',', array_values(array_unique($fields)));
    }

    /**
     * The fields between a legend marker and the next terminator — the slice
     * RSCE copies out of another bundle's palette.
     *
     * @return list<string>
     */
    private static function after(string $palette, string $marker, string $until): array
    {
        $parts = explode($marker, $palette, 2);
        if (\count($parts) < 2) {
            return [];
        }

        return DcaPalette::extractFields(explode($until, $parts[1], 2)[0]);
    }
}
