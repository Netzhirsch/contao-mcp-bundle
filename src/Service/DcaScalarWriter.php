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
