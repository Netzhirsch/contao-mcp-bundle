<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce;

use Netzhirsch\ContaoMcpBundle\Service\DcaPalette;

/**
 * The regular tl_content columns an RSCE type shows next to its own fields.
 *
 * RSCE assembles the palette in the same onload callback that creates its
 * virtual fields (CustomElements::createDca → generatePalette). A DCA loaded
 * outside the edit mask therefore has no palette for the type at all, and every
 * column looked foreign to the write tools: headline, customTpl, the image
 * toggle — refused on an element whose backend form shows them.
 *
 * This rebuilds that palette from the type's config the way RSCE 2.5 does for
 * tl_content, minus the virtual `rsce_field_*` entries. Those are not columns;
 * their values live in rsce_data, which ContentProvider writes.
 *
 *   config `standardFields`   headline, columns (rocksolid-columns), text,
 *                             slider (rocksolid-slider), image, cssID
 *   `standardField` entries   the column of that name
 *   always                    type (+ title where Contao has it), customTpl,
 *                             protected, guests, invisible, start, stop
 *
 * Sub-palettes (addImage → singleSRC, …) are not listed here; DcaPalette
 * expands them from the live DCA like for any other type.
 */
final class RscePalette
{
    /**
     * @param array<string, mixed> $config the type's rsce_*_config.php
     * @param array<string, mixed> $dca    the loaded tl_content DCA
     */
    public static function build(array $config, array $dca, bool $sliderInstalled): string
    {
        $standard = \is_array($config['standardFields'] ?? null)
            ? array_map(static fn (mixed $f): string => \is_scalar($f) ? (string) $f : '', $config['standardFields'])
            : [];
        $columns = \is_array($dca['fields'] ?? null) ? $dca['fields'] : [];
        $palettes = \is_array($dca['palettes'] ?? null) ? $dca['palettes'] : [];

        $fields = ['type'];

        // Contao 5.6 added the element title; RSCE puts it into the palette
        // from that version on. Asking the DCA answers the same question
        // without a version check.
        if (isset($columns['title'])) {
            $fields[] = 'title';
        }

        if (\in_array('headline', $standard, true)) {
            $fields[] = 'headline';
        }

        if (\in_array('columns', $standard, true)) {
            $fields = [...$fields, ...self::after((string) ($palettes['rs_columns_start'] ?? ''), '{rs_columns_legend},', ';')];
        }

        if (\in_array('text', $standard, true)) {
            $fields[] = 'text';
        }

        if ($sliderInstalled && \in_array('slider', $standard, true)) {
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

        if (\in_array('image', $standard, true)) {
            $fields[] = 'addImage';
        }

        $fields = [...$fields, 'customTpl', 'protected', 'guests'];

        if (\in_array('cssID', $standard, true)) {
            $fields[] = 'cssID';
        }

        $fields = [...$fields, 'invisible', 'start', 'stop'];

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
