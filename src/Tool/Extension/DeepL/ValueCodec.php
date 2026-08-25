<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\DeepL;

use Contao\StringUtil;

/**
 * Takes the translatable strings out of a stored column value and puts the
 * translated ones back into the same shape.
 *
 * Contao stores a headline as `serialise(['value' => …, 'unit' => 'h2'])`, a
 * list element as a serialised `list<string>` and a table element as a
 * serialised `list<list<string>>`. Only the leaves are prose; the surrounding
 * structure (`unit`, row/column arrangement) must survive untouched, so
 * extraction and rebuilding are index-aligned: {@see rebuild()} expects exactly
 * as many strings as {@see extract()} returned, in the same order.
 *
 * Rebuilt values are returned in the SHAPE THE FIELD MAPPERS ACCEPT (arrays and
 * objects, not pre-serialised blobs) — the write always goes through the
 * table's own `*_update` tool, which does the serialising.
 */
final class ValueCodec
{
    /**
     * The translatable leaves of a stored value, in a stable order. Empty
     * slots are kept so {@see rebuild()} stays index-aligned; the caller skips
     * them when building the API batch.
     *
     * @return list<string>
     */
    public static function extract(string $format, mixed $stored): array
    {
        switch ($format) {
            case TranslatableFields::FORMAT_HEADLINE:
                $data = self::toArray($stored);
                if ($data === null) {
                    // A plain string headline (older records / shorthand).
                    return [(string) $stored];
                }

                return [(string) ($data['value'] ?? '')];

            case TranslatableFields::FORMAT_LIST:
                $data = self::toArray($stored);
                if ($data === null) {
                    return [];
                }

                return array_values(array_map(strval(...), $data));

            case TranslatableFields::FORMAT_MATRIX:
                $data = self::toArray($stored);
                if ($data === null) {
                    return [];
                }
                $cells = [];
                foreach ($data as $row) {
                    foreach ((array) $row as $cell) {
                        $cells[] = (string) $cell;
                    }
                }

                return $cells;

            default:
                return [(string) $stored];
        }
    }

    /**
     * @param list<string> $translated exactly as many entries as extract() produced
     */
    public static function rebuild(string $format, mixed $stored, array $translated): mixed
    {
        switch ($format) {
            case TranslatableFields::FORMAT_HEADLINE:
                $data = self::toArray($stored);
                $unit = \is_array($data) ? (string) ($data['unit'] ?? 'h2') : 'h2';

                return ['value' => $translated[0] ?? '', 'unit' => $unit];

            case TranslatableFields::FORMAT_LIST:
                return array_values($translated);

            case TranslatableFields::FORMAT_MATRIX:
                $data = self::toArray($stored) ?? [];
                $out = [];
                $i = 0;
                foreach ($data as $row) {
                    $newRow = [];
                    foreach ((array) $row as $_) {
                        $newRow[] = $translated[$i] ?? '';
                        ++$i;
                    }
                    $out[] = $newRow;
                }

                return $out;

            default:
                return $translated[0] ?? '';
        }
    }

    /**
     * Human-readable rendering of a stored value, for the `source` side of a
     * preview. Structured fields collapse to their leaves so the answer stays
     * readable instead of echoing serialised PHP.
     */
    public static function display(string $format, mixed $stored): mixed
    {
        return match ($format) {
            TranslatableFields::FORMAT_HEADLINE => self::extract($format, $stored)[0] ?? '',
            TranslatableFields::FORMAT_LIST, TranslatableFields::FORMAT_MATRIX => self::rebuild(
                $format,
                $stored,
                self::extract($format, $stored),
            ),
            default => (string) $stored,
        };
    }

    /**
     * @return array<mixed>|null null when the value is not a serialised array
     */
    private static function toArray(mixed $stored): ?array
    {
        if (\is_array($stored)) {
            return $stored;
        }
        if (!\is_string($stored) || $stored === '') {
            return null;
        }

        $data = StringUtil::deserialize($stored);

        return \is_array($data) ? $data : null;
    }
}
