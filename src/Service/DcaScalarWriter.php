<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Contao\Model;

/**
 * Writes a DCA field that no hand-written mapping covers, by reading what the
 * column actually is out of the DCA.
 *
 * The page and article mappers write through hand-maintained groups —
 * STRING_FIELDS, BOOL_FIELDS, INT_FIELDS and a handful of special cases. That
 * works for core Contao and for nothing else: a field another bundle hangs on
 * `tl_page` is in none of those groups, so it was validated and then never
 * written. The grass-merkur rollout hit exactly that with
 * `netzhirsch_megamenu_subtitle` — 35 menu subtitles translated by hand while
 * the rest of the site ran automatically.
 *
 * This closes that gap WITHOUT guessing. It handles the three shapes whose
 * storage is unambiguous — string, integer, boolean — and refuses everything
 * else with a message naming the widget it found. That refusal is the point:
 * a serialised column written from a guess is how `headline` lost its text
 * (see {@see SerializedTuple}), and being wrong silently is worse than being
 * unable to help.
 */
final class DcaScalarWriter
{
    /**
     * Widgets that store more than one value in one column. Their encoding is
     * the widget's business, and we will not reproduce it from the outside.
     *
     * @var list<string>
     */
    private const COMPLEX_WIDGETS = [
        'checkboxWizard', 'listWizard', 'tableWizard', 'keyValueWizard', 'optionWizard',
        'sectionWizard', 'moduleWizard', 'imageSize', 'fileTree', 'pageTree', 'picker',
        'metaWizard', 'serpPreview', 'inputUnit', 'timePeriod', 'trbl', 'chmod',
    ];

    /**
     * True when this field can be written from a plain scalar.
     */
    public static function supports(string $table, string $field): bool
    {
        return self::shapeOf($table, $field) !== null;
    }

    /**
     * @return bool whether the value differed and was written
     *
     * @throws \InvalidArgumentException when the column is not a plain scalar,
     *                                   or the value is not one
     */
    public static function write(
        string $table,
        Model $model,
        string $field,
        mixed $value,
        bool $detectChanges = true,
    ): bool {
        $shape = self::shapeOf($table, $field);

        if ($shape === null) {
            $definition = $GLOBALS['TL_DCA'][$table]['fields'][$field] ?? [];
            $widget = \is_array($definition) ? (string) ($definition['inputType'] ?? '') : '';

            throw new \InvalidArgumentException(sprintf(
                'Field "%s" on %s cannot be written generically%s. It stores more than a plain value, '
                .'and writing it from a guess would corrupt it. Use the tool that owns this field, '
                .'or the extension that provides it.',
                $field,
                $table,
                $widget !== '' ? sprintf(' (widget "%s")', $widget) : '',
            ));
        }

        if (\is_array($value) || \is_object($value)) {
            throw new \InvalidArgumentException(sprintf(
                'Field "%s" on %s takes a %s, not an object or list.',
                $field,
                $table,
                $shape,
            ));
        }

        self::assertValueIsAllowed($table, $field, (string) $value);

        $new = match ($shape) {
            'boolean' => $value ? 1 : 0,
            'integer' => (int) $value,
            default => (string) $value,
        };

        $current = $model->$field;

        $unchanged = match ($shape) {
            'boolean', 'integer' => (int) $current === $new,
            default => (string) $current === $new,
        };

        if ($detectChanges && $unchanged) {
            return false;
        }

        $model->$field = $new;

        return true;
    }

    /**
     * A column can have the right SHAPE and still be the wrong thing to write
     * a free value into. This is the check that was missing when foreign fields
     * first became writable, and its absence corrupted a live page:
     *
     *     page_update(id: 129, extras: {netzhirschPageState: "__probe_invalid__"})
     *     → updated: true, applied: 1
     *
     * `netzhirschPageState` is a foreign key into another table. A 17-character
     * string is not an invalid option there — it is a dangling reference, and
     * it also went around `pagestate_assign`, the tool that owns that field and
     * knows to take a version snapshot.
     *
     * So: a reference is refused outright and points at its target, and an
     * enumeration is checked against its options. Where the options cannot be
     * evaluated from here, the write is refused rather than waved through —
     * "we could not check" must not read as "it is fine".
     *
     * @throws \InvalidArgumentException
     */
    private static function assertValueIsAllowed(string $table, string $field, string $value): void
    {
        $definition = $GLOBALS['TL_DCA'][$table]['fields'][$field] ?? [];

        if (!\is_array($definition)) {
            return;
        }

        // ── References ────────────────────────────────────────────────────
        $foreignKey = (string) ($definition['foreignKey'] ?? '');
        $target = $foreignKey !== '' ? explode('.', $foreignKey)[0] : '';

        if ($target !== '' || isset($definition['relation'])) {
            throw new \InvalidArgumentException(sprintf(
                'Field "%s" on %s is a reference%s, not a plain value. Writing it here would '
                .'store whatever it is given, including an id that points at nothing, and would '
                .'skip the tool that owns this relation (which also records the change). '
                .'Look for that tool with contao_search_tools("%s") — for example a *_assign or '
                .'*_list pair — and use it instead.',
                $field,
                $table,
                $target !== '' ? sprintf(' into %s', $target) : '',
                // The search is word-based, so hand over the words rather than
                // the identifier: "netzhirsch page state" finds pagestate_assign,
                // "netzhirschPageState" finds nothing.
                NameTokens::phrase($target !== '' ? $target : $field),
            ));
        }

        // ── Enumerations ──────────────────────────────────────────────────
        $options = $definition['options'] ?? null;

        if (\is_array($options) && $options !== []) {
            $allowed = self::flattenOptions($options);

            if ($allowed !== [] && !\in_array($value, $allowed, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" on %s only accepts one of: %s. Got "%s".',
                    $field,
                    $table,
                    implode(', ', \array_slice($allowed, 0, 30)),
                    $value,
                ));
            }

            return;
        }

        if (isset($definition['options_callback'])) {
            throw new \InvalidArgumentException(sprintf(
                'Field "%s" on %s takes one of a set of options that is built at edit time, '
                .'so the value cannot be checked from here. It is not written rather than '
                .'written unchecked. Use the tool that owns this field, or set it in the backend.',
                $field,
                $table,
            ));
        }

        // ── Length ────────────────────────────────────────────────────────
        $eval = \is_array($definition['eval'] ?? null) ? $definition['eval'] : [];
        $maxLength = (int) ($eval['maxlength'] ?? 0);

        if ($maxLength > 0 && mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(sprintf(
                'Field "%s" on %s holds at most %d characters, got %d. MySQL would truncate it.',
                $field,
                $table,
                $maxLength,
                mb_strlen($value),
            ));
        }
    }

    /**
     * Contao writes `options` as a plain list, as value => label, or grouped
     * into optgroups. Only the VALUES matter here.
     *
     * @param array<int|string, mixed> $options
     *
     * @return list<string>
     */
    private static function flattenOptions(array $options): array
    {
        $out = [];

        foreach ($options as $key => $entry) {
            if (\is_array($entry)) {
                foreach (self::flattenOptions($entry) as $nested) {
                    $out[] = $nested;
                }

                continue;
            }

            // A list gives us values as entries; a map gives them as keys.
            $out[] = \is_int($key) ? (string) $entry : (string) $key;
        }

        return array_values(array_unique($out));
    }

    /**
     * "string" | "integer" | "boolean" for a plainly-stored column, null when
     * the column holds something this class will not touch.
     */
    private static function shapeOf(string $table, string $field): ?string
    {
        $definition = $GLOBALS['TL_DCA'][$table]['fields'][$field] ?? null;

        if (!\is_array($definition)) {
            return null;
        }

        $eval = \is_array($definition['eval'] ?? null) ? $definition['eval'] : [];

        // `multiple` means the column carries a serialised set, whatever the
        // widget is called.
        if (!empty($eval['multiple'])) {
            return null;
        }

        $inputType = (string) ($definition['inputType'] ?? '');

        if (\in_array($inputType, self::COMPLEX_WIDGETS, true)) {
            return null;
        }

        return self::shapeFromSql($definition['sql'] ?? null);
    }

    /**
     * Contao 5 DCAs declare `sql` either as a Doctrine-style array or as the
     * older raw column definition string. Both appear in the wild, including
     * inside one DCA, so both are read here.
     */
    private static function shapeFromSql(mixed $sql): ?string
    {
        if (\is_array($sql)) {
            $type = strtolower((string) ($sql['type'] ?? ''));

            return match (true) {
                $type === 'boolean' => 'boolean',
                \in_array($type, ['integer', 'smallint', 'bigint'], true) => 'integer',
                \in_array($type, ['string', 'text', 'ascii_string'], true) => 'string',
                default => null,
            };
        }

        if (!\is_string($sql) || $sql === '') {
            return null;
        }

        $lower = strtolower($sql);

        // A blob column is where Contao puts serialised data and binary UUIDs.
        if (str_contains($lower, 'blob') || str_contains($lower, 'binary')) {
            return null;
        }

        return match (true) {
            str_contains($lower, "tinyint(1)") => 'boolean',
            str_contains($lower, 'int(') || str_starts_with($lower, 'int ') => 'integer',
            str_contains($lower, 'varchar') || str_contains($lower, 'text') || str_contains($lower, 'char(') => 'string',
            default => null,
        };
    }
}
