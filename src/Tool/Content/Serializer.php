<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Content;

use Contao\ContentModel;
use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Contao\Validator;
use Netzhirsch\ContaoMcpBundle\Security\UntrustedContent;
use Netzhirsch\ContaoMcpBundle\Service\ProviderFields;

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
     * Headline tuples: serialised {value, unit}. sectionHeadline (the section
     * title of an accordion child) is written as the same object, so it reads
     * back as one too.
     *
     * @var list<string>
     */
    private const HEADLINE_TUPLE_FIELDS = ['headline', 'sectionHeadline'];

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
    public function __construct(
        private readonly ProviderFields $providerFields,
        private readonly ?ContaoFramework $framework = null,
    ) {
    }

    public function summary(ContentModel $c): array
    {
        /** @var array<string, mixed> $row */
        $row = $c->row();

        [$singleFiles, $multipleFiles] = $this->fileTreeFields();

        foreach ([...self::UUID_FIELDS, ...$singleFiles] as $f) {
            if (\array_key_exists($f, $row)) {
                $row[$f] = self::decodeUuid($row[$f]);
            }
        }

        foreach ([...self::UUID_LIST_FIELDS, ...$multipleFiles] as $f) {
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

        // Provider representation wins over the raw column: an extension
        // may store serialised data a caller cannot use as-is.
        return UntrustedContent::annotate(
            'tl_content',
            $this->providerFields->serialize('tl_content', $c) + $row,
        );
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
     * Every fileTree column of tl_content that holds binary UUIDs, split into
     * single and multiple. Raw binary is no valid UTF-8 and comes out of the
     * JSON encoder as replacement characters — a posterSRC or an extension's
     * image field read back as garbage the caller could not even send again.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function fileTreeFields(): array
    {
        $this->framework?->getAdapter(Controller::class)->loadDataContainer('tl_content');

        $single = [];
        $multiple = [];
        foreach ($GLOBALS['TL_DCA']['tl_content']['fields'] ?? [] as $field => $definition) {
            match (FieldMapper::fileTreeKindOf($definition)) {
                'single' => $single[] = (string) $field,
                'multiple' => $multiple[] = (string) $field,
                default => null,
            };
        }

        return [$single, $multiple];
    }

    /**
     * Hex, as singleSRC has always been returned. A value that is no binary
     * UUID is passed through rather than mangled.
     */
    private static function decodeUuid(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return \is_string($value) && Validator::isBinaryUuid($value) ? bin2hex($value) : $value;
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
                $out[] = Validator::isBinaryUuid($bin) ? bin2hex($bin) : $bin;
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
