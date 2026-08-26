<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

/**
 * Resolves which fields a DCA palette actually offers for one record type,
 * sub-palettes included — and, crucially, only the sub-palettes that belong to
 * that type.
 *
 * The three palette-driven tables (tl_module, tl_content, tl_form_field) each
 * used to merge EVERY entry of `$dca['subpalettes']` into EVERY type. Contao
 * keeps one wide table per DCA, so that quietly offered the caller columns the
 * backend would never show for the type it was editing: a `netzhirsch_megamenu`
 * module listed the seven offcanvas fields of `netzhirsch_navigation`, plus
 * `reg_homeDir`, `reg_jumpTo` and `reg_text` from the login module.
 *
 * That is worse than it sounds. A read-back after a write shows every column of
 * the row regardless of type, so writing one of those fields looks like it
 * worked — the value is in the database, and nothing renders it. The AL-07
 * report reached exactly that conclusion in reverse ("you can set up the
 * offcanvas but not switch it on"): the setup fields were the ones that should
 * never have been on offer.
 *
 * A sub-palette belongs to a type when its SELECTOR sits in that type's
 * palette. Contao names them two ways:
 *
 *   checkbox   `subpalettes[$selector]`             — e.g. defineRoot → rootPage
 *   select     `subpalettes[$selector.'_'.$value]`  — e.g. type_navigation
 *
 * Whether a sub-palette is currently OPEN depends on the selector's value, and
 * that is backend UI state — it must not constrain the write path, or you could
 * never switch a toggle and fill its fields in one call. So for a select-style
 * selector every value variant is allowed. The one exception is the type
 * selector itself: the resolved type is known, so only that variant applies.
 */
final class DcaPalette
{
    /**
     * @param array<string, mixed> $dca       the loaded $GLOBALS['TL_DCA'][$table]
     * @param string               $type      resolved record type
     * @param string               $typeField the column carrying the type (almost always "type")
     *
     * @return array{fields: list<string>, subpalettes: array<string, list<string>>}
     */
    public static function resolve(array $dca, string $type, string $typeField = 'type'): array
    {
        $palettes = \is_array($dca['palettes'] ?? null) ? $dca['palettes'] : [];
        $subpalettes = \is_array($dca['subpalettes'] ?? null) ? $dca['subpalettes'] : [];

        $fields = self::extractFields((string) ($palettes[$type] ?? ''));
        $found = [];
        $seen = [];

        // Sub-palettes nest: a text element reaches `alt` through addImage →
        // overwriteMeta → alt, so one pass over the type's palette would stop
        // one level short and drop fields the backend really shows. Keep
        // expanding while newly reached fields turn out to be selectors
        // themselves; $seen makes a cyclic DCA terminate instead of hanging.
        do {
            $added = false;

            foreach (self::selectorsFor($palettes, $subpalettes, $fields) as $selector) {
                if (isset($seen[$selector])) {
                    continue;
                }
                $seen[$selector] = true;

                $children = self::childrenOf($subpalettes, $selector, $selector === $typeField ? $type : null);
                if ($children === []) {
                    continue;
                }

                $found[$selector] = $children;

                foreach ($children as $child) {
                    if (!\in_array($child, $fields, true)) {
                        $fields[] = $child;
                        $added = true;
                    }
                }
            }
        } while ($added);

        return [
            'fields' => array_values(array_unique($fields)),
            'subpalettes' => $found,
        ];
    }

    /**
     * Splits a palette string into plain field names, dropping the `{legend}`
     * markers.
     *
     * @return list<string>
     */
    public static function extractFields(string $palette): array
    {
        if ($palette === '') {
            return [];
        }

        $out = [];
        foreach (preg_split('/[;,]/', $palette) ?: [] as $token) {
            $token = trim($token);
            if ($token === '' || str_starts_with($token, '{')) {
                continue;
            }
            $out[] = $token;
        }

        return $out;
    }

    /**
     * Selector fields that are part of this type's palette.
     *
     * `__selector__` is the declared list, but an extension can register a
     * sub-palette without adding to it — sometimes by accident, sometimes on
     * purpose. Two more ways a selector is recognised, both widening the result
     * rather than silently dropping real fields:
     *
     *   - a `subpalettes` key that IS a field of this palette (checkbox style);
     *   - a prefix of a `subpalettes` key that is a field of this palette
     *     (select style, where the key is `<selector>_<value>` and only the
     *     selector ever appears in a palette).
     *
     * @param array<string, mixed> $palettes
     * @param array<string, mixed> $subpalettes
     * @param list<string>         $paletteFields
     *
     * @return list<string>
     */
    private static function selectorsFor(array $palettes, array $subpalettes, array $paletteFields): array
    {
        $declared = \is_array($palettes['__selector__'] ?? null) ? $palettes['__selector__'] : [];

        $candidates = array_map(strval(...), $declared);

        foreach (array_keys($subpalettes) as $key) {
            $key = (string) $key;
            $candidates[] = $key;

            // A select-style key is "<selector>_<value>", and only the SELECTOR
            // appears in the palette — the key itself never does. Without
            // walking back over the underscores, such a sub-palette is
            // unreachable whenever its selector is not in `__selector__`, which
            // a DCA may leave out on purpose (netzhirsch/contao-bootstrap-bundle
            // omits it so the backend does not render the group twice, and
            // declares the sub-palettes purely so a write tool accepts the
            // fields). Every prefix is offered as a candidate; the palette
            // membership check below is what makes one of them count.
            $parts = explode('_', $key);
            while (\count($parts) > 1) {
                array_pop($parts);
                $candidates[] = implode('_', $parts);
            }
        }

        $selectors = [];
        foreach (array_unique($candidates) as $candidate) {
            if (\in_array($candidate, $paletteFields, true) && !\in_array($candidate, $selectors, true)) {
                $selectors[] = $candidate;
            }
        }

        return $selectors;
    }

    /**
     * @param array<string, mixed> $subpalettes
     * @param string|null          $onlyValue   restrict a select-style selector to one value
     *
     * @return list<string>
     */
    private static function childrenOf(array $subpalettes, string $selector, ?string $onlyValue): array
    {
        $children = [];

        // Checkbox style: the selector name is the key.
        if (isset($subpalettes[$selector])) {
            foreach (self::extractFields((string) $subpalettes[$selector]) as $field) {
                $children[] = $field;
            }
        }

        // Select style: one key per value.
        $prefix = $selector.'_';
        foreach ($subpalettes as $key => $definition) {
            $key = (string) $key;
            if (!str_starts_with($key, $prefix)) {
                continue;
            }
            if ($onlyValue !== null && $key !== $prefix.$onlyValue) {
                continue;
            }
            foreach (self::extractFields((string) $definition) as $field) {
                $children[] = $field;
            }
        }

        return array_values(array_unique($children));
    }
}
