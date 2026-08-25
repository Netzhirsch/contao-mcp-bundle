<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Content;

use Contao\Controller;
use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Netzhirsch\ContaoMcpBundle\Service\DcaPalette;
use Netzhirsch\ContaoMcpBundle\Service\ProviderFields;

/**
 * Field mapper for tl_content. Because tl_content's column set is extended by every
 * Contao bundle that registers new content-element types (gallery, accordion,
 * swiper, …), we can't pin a static field list. We resolve allowed fields at
 * runtime by loading the tl_content DCA and parsing the palette of the resolved
 * type.
 *
 * Input shape: tools pass a flat array merging the four required Top-Level fields
 * (pid, ptable, type, sorting) and a `fields` dict containing every type-specific
 * value. The mapper validates each key against the live palette + a few
 * always-allowed core fields, then casts/serialises the value to the DCA expectation.
 */
final class FieldMapper
{
    /**
     * Fields that every content element accepts, regardless of type. These are the
     * skeleton + publish + protection columns shared by all palettes.
     *
     * @var list<string>
     */
    public const COMMON_FIELDS = [
        'pid', 'ptable', 'type', 'sorting',
        'cssID', 'space', 'invisible', 'start', 'stop',
        'guests', 'protected', 'groups',
    ];

    /**
     * Binary(16) UUID fields — accept hex/UUID strings, store as binary, '' clears.
     *
     * @var list<string>
     */
    private const UUID_FIELDS = ['singleSRC'];

    /**
     * Serialised list<UUID> fields — accept list<hex>, store as serialised blob.
     *
     * @var list<string>
     */
    private const UUID_LIST_FIELDS = ['multiSRC', 'orderSRC'];

    /**
     * Serialised list<int> fields.
     *
     * @var list<string>
     */
    private const INT_LIST_FIELDS = ['groups', 'sizes', 'shClasses'];

    /**
     * Serialised 2D string-matrix fields (tableWizard). `tableitems` is a list
     * of rows, each row a list of cell strings — NOT an int list (that older
     * mapping silently destroyed the matrix to a row count).
     *
     * @var list<string>
     */
    private const MATRIX_FIELDS = ['tableitems'];

    /**
     * Serialised list<string> fields.
     *
     * @var list<string>
     */
    private const STRING_LIST_FIELDS = ['mooHeaders', 'sliderTypes', 'cssClasses', 'galleryTplOptions'];

    /**
     * Headline tuple field.
     *
     * @var list<string>
     */
    private const HEADLINE_TUPLE_FIELDS = ['headline'];

    /**
     * Serialised positional string-pair fields → accept an object
     * {keyA, keyB} (or a positional [a, b]) and store as serialise([a, b]),
     * matching Contao's cssID/space widgets. A plain string is passed through
     * unchanged (back-compat: an already-serialised value). This makes
     * content_create accept the object form too — consistent with
     * content_update / article_*.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const STRING_PAIR_FIELDS = [
        'cssID' => ['id', 'class'],
        'space' => ['top', 'bottom'],
    ];

    /**
     * tinyint flag fields — accept bool, store as 0/1.
     *
     * @var list<string>
     */
    private const BOOL_FIELDS = [
        'invisible', 'guests', 'protected', 'fullsize', 'addImage', 'overwriteMeta',
        'addBefore', 'autoplay', 'controls', 'showCaptions',
    ];

    /**
     * Date/time fields stored as unix timestamp in varchar(10) — accept ISO 8601.
     *
     * @var list<string>
     */
    private const DATETIME_FIELDS = ['start', 'stop'];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly ProviderFields $providerFields,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    public function apply(ContentModel $content, array $input, bool $detectChanges = true): array
    {
        $this->framework->initialize();

        $type = $this->resolveType($content, $input);

        if (!$this->isKnownType($type)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown content type "%s". Call content_types_list for the current list of types registered by Contao and its bundles.',
                $type,
            ));
        }

        $allowed = $this->allowedFieldsFor($type);

        // ─── Validate ───
        foreach (array_keys($input) as $field) {
            if (!\in_array($field, $allowed, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" is not valid for content type "%s". Use content_palette_get("%s") to see allowed fields. Currently allowed: %s.',
                    $field,
                    $type,
                    $type,
                    implode(', ', $allowed),
                ));
            }
        }

        $changed = [];
        $touch = static function (string $field) use (&$changed): void {
            if (!\in_array($field, $changed, true)) {
                $changed[] = $field;
            }
        };

        foreach ($input as $field => $value) {
            if ($value === null) {
                continue;
            }
            $newValue = $this->castValue($field, $value);
            if (!$detectChanges || self::isDifferent($content->$field, $newValue)) {
                $content->$field = $newValue;
                $touch($field);
            }
        }

        // Providers last. Unlike the theme mapper this one signals failure by
        // throwing, so a rejected value keeps that contract — the Tool layer
        // catches it and nothing is saved.
        $fromProviders = $this->providerFields->apply('tl_content', $content, $input, $detectChanges);
        if ($fromProviders['errors'] !== []) {
            throw new \InvalidArgumentException(implode(' ', $fromProviders['errors']));
        }
        foreach ($fromProviders['applied'] as $field) {
            $touch($field);
        }

        return $changed;
    }

    /**
     * Returns the full set of field names that are valid for a given content type.
     * Combines a hardcoded common set with the dynamic per-type palette parsed
     * from the live DCA. Sub-palette children (e.g. addImage → singleSRC, alt) are
     * always included, since we don't enforce the gate.
     *
     * @return list<string>
     */
    public function allowedFieldsFor(string $type): array
    {
        return array_values(array_unique(array_merge(
            self::COMMON_FIELDS,
            $this->resolvePalette($type)['fields'],
            // Extension-owned columns are not in the DCA palette, so without
            // this the validation above rejects them before their provider is
            // ever asked.
            $this->providerFields->declaredFor('tl_content'),
        )));
    }

    /**
     * The sub-palettes this type actually opens, as selector => child fields.
     *
     * Answers the question a caller otherwise has to reverse-engineer from a
     * rejection: which of these fields only exist because a toggle is in the
     * palette, and which toggle is it.
     *
     * @return array<string, list<string>>
     */
    public function subpalettesFor(string $type): array
    {
        return $this->resolvePalette($type)['subpalettes'];
    }

    /**
     * @return array{fields: list<string>, subpalettes: array<string, list<string>>}
     */
    private function resolvePalette(string $type): array
    {
        $this->framework->initialize();

        $adapter = $this->framework->getAdapter(Controller::class);
        $adapter->loadDataContainer('tl_content');

        return DcaPalette::resolve($GLOBALS['TL_DCA']['tl_content'] ?? [], $type);
    }

    /**
     * Flat list of every type registered in $GLOBALS['TL_CTE'] across all categories.
     *
     * @return list<string>
     */
    public function allKnownTypes(): array
    {
        $flat = [];
        foreach ($this->listTypesGrouped() as $list) {
            foreach ($list as $type) {
                $flat[] = $type;
            }
        }

        return array_values(array_unique($flat));
    }

    private function isKnownType(string $type): bool
    {
        return \in_array($type, $this->allKnownTypes(), true);
    }

    /**
     * Returns every type registered via $GLOBALS['TL_CTE'], grouped by category.
     *
     * @return array<string, list<string>>
     */
    public function listTypesGrouped(): array
    {
        $this->framework->initialize();

        $cte = $GLOBALS['TL_CTE'] ?? [];
        $grouped = [];
        foreach ($cte as $category => $types) {
            if (!\is_array($types)) {
                continue;
            }
            $grouped[(string) $category] = array_values(array_filter(array_map('strval', array_keys($types))));
        }

        return $grouped;
    }

    /**
     * Picks the type to validate against: input wins on change-type, model otherwise.
     *
     * @param array<string, mixed> $input
     */
    private function resolveType(ContentModel $content, array $input): string
    {
        if (\array_key_exists('type', $input) && $input['type'] !== null && $input['type'] !== '') {
            return (string) $input['type'];
        }
        $current = (string) $content->type;

        return $current !== '' ? $current : 'text';
    }


    private function castValue(string $field, mixed $value): mixed
    {
        if (\in_array($field, self::BOOL_FIELDS, true)) {
            return $value ? 1 : 0;
        }

        if (\in_array($field, self::UUID_FIELDS, true)) {
            $raw = (string) $value;
            if ($raw === '') {
                return null;
            }
            return self::hexToBin($raw, $field);
        }

        if (\in_array($field, self::UUID_LIST_FIELDS, true)) {
            if (!\is_array($value)) {
                throw new \InvalidArgumentException(sprintf('"%s" must be a list of UUID-hex strings.', $field));
            }
            $bins = [];
            foreach ($value as $hex) {
                $bins[] = self::hexToBin((string) $hex, $field);
            }
            return $bins === [] ? '' : serialize($bins);
        }

        if (\in_array($field, self::INT_LIST_FIELDS, true)) {
            if (!\is_array($value)) {
                throw new \InvalidArgumentException(sprintf('"%s" must be a list of integers.', $field));
            }
            $cleaned = array_values(array_map('intval', $value));
            return $cleaned === [] ? '' : serialize($cleaned);
        }

        if (\in_array($field, self::MATRIX_FIELDS, true)) {
            if (!\is_array($value)) {
                throw new \InvalidArgumentException(sprintf('"%s" must be a 2D array (list of rows, each a list of cell strings).', $field));
            }
            $matrix = [];
            foreach ($value as $row) {
                if (\is_object($row)) {
                    $row = (array) $row;
                }
                if (!\is_array($row)) {
                    throw new \InvalidArgumentException(sprintf('"%s": every row must itself be a list of cell strings.', $field));
                }
                $matrix[] = array_values(array_map('strval', $row));
            }

            return $matrix === [] ? '' : serialize($matrix);
        }

        if (\in_array($field, self::STRING_LIST_FIELDS, true)) {
            if (!\is_array($value)) {
                throw new \InvalidArgumentException(sprintf('"%s" must be a list of strings.', $field));
            }
            $cleaned = array_values(array_map('strval', $value));
            return $cleaned === [] ? '' : serialize($cleaned);
        }

        if (\in_array($field, self::HEADLINE_TUPLE_FIELDS, true)) {
            if (\is_string($value)) {
                // shorthand: pass just the text, default unit h2
                return serialize(['value' => $value, 'unit' => 'h2']);
            }
            if (\is_object($value)) {
                $value = (array) $value;
            }
            if (!\is_array($value)) {
                throw new \InvalidArgumentException('"headline" must be a string or an object {value, unit}.');
            }
            return serialize([
                'value' => (string) ($value['value'] ?? ''),
                'unit' => (string) ($value['unit'] ?? 'h2'),
            ]);
        }

        if (isset(self::STRING_PAIR_FIELDS[$field])) {
            // Plain string → store as-is (already-serialised / raw, back-compat).
            if (\is_string($value)) {
                return $value;
            }
            if (\is_object($value)) {
                $value = (array) $value;
            }
            if (!\is_array($value)) {
                [$ka, $kb] = self::STRING_PAIR_FIELDS[$field];

                throw new \InvalidArgumentException(sprintf('"%s" must be a string or an object {%s, %s}.', $field, $ka, $kb));
            }
            [$keyA, $keyB] = self::STRING_PAIR_FIELDS[$field];
            $a = (string) ($value[$keyA] ?? $value[0] ?? '');
            $b = (string) ($value[$keyB] ?? $value[1] ?? '');

            return ($a === '' && $b === '') ? '' : serialize([$a, $b]);
        }

        if (\in_array($field, self::DATETIME_FIELDS, true)) {
            $raw = (string) $value;
            if ($raw === '') {
                return '';
            }
            $ts = strtotime($raw);
            if ($ts === false) {
                throw new \InvalidArgumentException(sprintf('Invalid datetime "%s" for "%s". Use ISO 8601.', $raw, $field));
            }
            return (string) $ts;
        }

        if (\is_array($value)) {
            // Unknown serialised field — store as PHP-serialise.
            return serialize($value);
        }
        if (\is_object($value)) {
            return serialize((array) $value);
        }

        return $value;
    }

    private static function hexToBin(string $raw, string $field): string
    {
        $hex = str_replace('-', '', $raw);
        if (\strlen($hex) !== 32 || preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" must be a 32-char hex UUID or a UUID with dashes (got "%s").',
                $field,
                $raw,
            ));
        }

        return hex2bin($hex);
    }

    private static function isDifferent(mixed $a, mixed $b): bool
    {
        // Compare loosely on string-coerced values for scalar fields; for binary/serial
        // values an identity check after cast is enough since we always re-serialise.
        if (\is_scalar($a) && \is_scalar($b)) {
            return (string) $a !== (string) $b;
        }

        return $a !== $b;
    }
}
