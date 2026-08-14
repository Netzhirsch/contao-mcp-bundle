<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Content;

use Contao\ContentModel;
use Contao\StringUtil;

/**
 * Flattens a ContentModel into a JSON-friendly array. Because tl_content has ~100
 * columns and the set grows with Bundles, we don't enumerate every field by hand —
 * we take Model::row() and apply targeted conversions for known special shapes
 * (UUIDs, serialised blobs, the headline tuple, etc.). Everything else passes
 * through as the DB value (strings, ints, bools).
 */
final class Serializer
{
    /**
     * Binary(16) UUID fields. NULL → null, otherwise hex string.
     *
     * @var list<string>
     */
    private const UUID_FIELDS = ['singleSRC'];

    /**
     * Serialised blobs containing a list of binary(16) UUIDs. Returns list<string> of hex.
     *
     * @var list<string>
     */
    private const UUID_LIST_FIELDS = ['multiSRC', 'orderSRC'];

    /**
     * Serialised blobs containing a list of integers (FKs).
     *
     * @var list<string>
     */
    private const INT_LIST_FIELDS = ['groups', 'sizes', 'shClasses'];

    /**
     * Serialised 2D string-matrix fields (tableWizard) — decoded to
     * list<list<string>>.
     *
     * @var list<string>
     */
    private const MATRIX_FIELDS = ['tableitems'];

    /**
     * Serialised lists of mixed scalar values (strings).
     *
     * @var list<string>
     */
    private const STRING_LIST_FIELDS = ['mooHeaders', 'sliderTypes', 'cssClasses', 'galleryTplOptions'];

    /**
     * Headline tuple: serialised {value, unit}.
     *
     * @var list<string>
     */
    private const HEADLINE_TUPLE_FIELDS = ['headline'];

    /**
     * tinyint flags rendered as PHP bool.
     *
     * @var list<string>
     */
    private const BOOL_FIELDS = [
        'invisible', 'guests', 'protected', 'fullsize', 'addImage', 'overwriteMeta',
        'addBefore', 'autoplay', 'controls', 'showCaptions',
    ];

    /**
     * Date/time-as-unix-timestamp varchar(10) fields → ISO 8601.
     *
     * @var list<string>
     */
    private const DATETIME_FIELDS = ['start', 'stop'];

    /**
     * @return array<string, mixed>
     */
    public function summary(ContentModel $c): array
    {
        /** @var array<string, mixed> $row */
        $row = $c->row();

        foreach (self::UUID_FIELDS as $f) {
            if (\array_key_exists($f, $row)) {
                $row[$f] = $row[$f] ? bin2hex((string) $row[$f]) : null;
            }
        }

        foreach (self::UUID_LIST_FIELDS as $f) {
            if (\array_key_exists($f, $row)) {
                $row[$f] = self::decodeUuidList($row[$f]);
            }
        }

        foreach (self::INT_LIST_FIELDS as $f) {
            if (\array_key_exists($f, $row)) {
                $row[$f] = self::decodeIntList($row[$f]);
            }
        }

        foreach (self::MATRIX_FIELDS as $f) {
            if (\array_key_exists($f, $row)) {
                $row[$f] = self::decodeMatrix($row[$f]);
            }
        }

        foreach (self::STRING_LIST_FIELDS as $f) {
            if (\array_key_exists($f, $row)) {
                $row[$f] = self::decodeStringList($row[$f]);
            }
        }

        foreach (self::HEADLINE_TUPLE_FIELDS as $f) {
            if (\array_key_exists($f, $row)) {
                $row[$f] = self::decodeHeadlineTuple($row[$f]);
            }
        }

        foreach (self::BOOL_FIELDS as $f) {
            if (\array_key_exists($f, $row)) {
                $row[$f] = (bool) $row[$f];
            }
        }

        foreach (self::DATETIME_FIELDS as $f) {
            if (\array_key_exists($f, $row)) {
                $row[$f] = $row[$f] ? date(\DATE_ATOM, (int) $row[$f]) : null;
            }
        }

        // Always cast id/pid/sorting/ptable cleanly
        foreach (['id', 'pid', 'sorting', 'tstamp'] as $f) {
            if (\array_key_exists($f, $row)) {
                $row[$f] = (int) $row[$f];
            }
        }
        if (\array_key_exists('ptable', $row)) {
            $row['ptable'] = (string) $row['ptable'];
        }

        return $row;
    }

    /**
     * @return array{value: string, unit: string}|null
     */
    private static function decodeHeadlineTuple(mixed $value): ?array
    {
        if (!$value) {
            return null;
        }
        $arr = StringUtil::deserialize($value, true);
        if (!\is_array($arr)) {
            return null;
        }

        return [
            'value' => (string) ($arr['value'] ?? ''),
            'unit' => (string) ($arr['unit'] ?? 'h2'),
        ];
    }

    /**
     * Decode a serialised tableWizard blob to a 2D string matrix.
     *
     * @return list<list<string>>
     */
    private static function decodeMatrix(mixed $value): array
    {
        if (!$value) {
            return [];
        }
        $arr = StringUtil::deserialize($value, true);
        if (!\is_array($arr)) {
            return [];
        }

        $out = [];
        foreach ($arr as $rowValue) {
            if (!\is_array($rowValue)) {
                continue;
            }
            $out[] = array_values(array_map('strval', $rowValue));
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function decodeUuidList(mixed $value): array
    {
        if (!$value) {
            return [];
        }
        $arr = @unserialize((string) $value, ['allowed_classes' => false]);
        if (!\is_array($arr)) {
            return [];
        }

        $out = [];
        foreach ($arr as $bin) {
            if (\is_string($bin) && $bin !== '') {
                $out[] = bin2hex($bin);
            }
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private static function decodeIntList(mixed $value): array
    {
        if (!$value) {
            return [];
        }
        $arr = @unserialize((string) $value, ['allowed_classes' => false]);
        if (!\is_array($arr)) {
            return [];
        }

        return array_values(array_map('intval', $arr));
    }

    /**
     * @return list<string>
     */
    private static function decodeStringList(mixed $value): array
    {
        if (!$value) {
            return [];
        }
        $arr = @unserialize((string) $value, ['allowed_classes' => false]);
        if (!\is_array($arr)) {
            return [];
        }

        return array_values(array_map('strval', $arr));
    }
}
