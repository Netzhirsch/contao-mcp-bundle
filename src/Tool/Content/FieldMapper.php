<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Content;

use Contao\Controller;
use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Netzhirsch\ContaoMcpBundle\Service\DcaPalette;
use Netzhirsch\ContaoMcpBundle\Service\SerializedTuple;
use Netzhirsch\ContaoMcpBundle\Service\ProviderFields;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce\RsceElements;

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
 * always-allowed core fields + what the element's parent adds (an accordion's
 * sectionHeadline), then casts/serialises the value to the DCA expectation.
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
     * Headline tuple fields — serialised {value, unit}. sectionHeadline is the
     * same inputUnit widget with the same default.
     *
     * @var list<string>
     */
    private const HEADLINE_TUPLE_FIELDS = ['headline', 'sectionHeadline'];

    /**
     * Fields an element gains from WHERE it sits, not from its type:
     * field => the type of the parent element that adds it.
     *
     * Contao's AccordionListener (config.onpalette, unchanged from 5.3 to 6.0)
     * puts sectionHeadline — the title of an accordion section — into the
     * palette of every element whose parent is an accordion. A palette read per
     * type never shows it, so the field was refused on exactly the elements that
     * need it, and an accordion could not be built with titled sections.
     *
     * @var array<string, string>
     */
    private const CONTEXT_FIELDS = ['sectionHeadline' => 'accordion'];

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
        private readonly RsceElements $rsce,
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

        // Where the element sits after this write: a move in the same call
        // counts, because the palette it gets is the one of its NEW parent.
        $parentType = $this->parentTypeOf(
            (string) ($input['ptable'] ?? $content->ptable),
            (int) ($input['pid'] ?? $content->pid),
        );

        return $this->write($content, $input, $detectChanges, $type, $parentType);
    }

    /**
     * Everything apply() would check — field names, values, extension
     * providers — run against a throwaway element that is never saved.
     *
     * For content_create_tree, which promises to find every checkable problem
     * before its first write. A node's parent may not exist yet at that point
     * (it is created by the same call), so the parent's type is passed in.
     *
     * @param array<string, mixed> $fields
     *
     * @return string|null the refusal content_create would answer, null when the element can be written
     */
    public function check(string $type, array $fields, ?string $parentType = null): ?string
    {
        $this->framework->initialize();

        $probe = new ContentModel();
        $probe->type = $type;

        try {
            $this->write($probe, $fields, false, $type, $parentType);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    private function write(ContentModel $content, array $input, bool $detectChanges, string $type, ?string $parentType): array
    {
        // ─── Validate ───
        $rejected = $this->rejectedFields($type, array_map(strval(...), array_keys($input)), $parentType);
        if ($rejected !== []) {
            throw new \InvalidArgumentException((string) reset($rejected));
        }

        $changed = [];
        $touch = static function (string $field) use (&$changed): void {
            if (!\in_array($field, $changed, true)) {
                $changed[] = $field;
            }
        };

        // Extension-owned columns are left to their provider. It may merge into
        // what is stored (rsce_data does), and the generic cast below would
        // have overwritten the stored value before the provider got to read it.
        $providerOwned = $this->providerFields->declaredFor('tl_content');

        foreach ($input as $field => $value) {
            if ($value === null || \in_array($field, $providerOwned, true)) {
                continue;
            }
            $newValue = $this->castValue($field, $value, $content->$field);
            if (!$detectChanges || self::isDifferent($content->$field, $newValue)) {
                $content->$field = $newValue;
                $touch($field);
            }
        }

        // Providers last. Unlike the theme mapper this one signals failure by
        // throwing, so a rejected value keeps that contract — the Tool layer
        // catches it and nothing is saved.
        $fromProviders = $this->providerFields->apply('tl_content', $content, $input, $detectChanges, $type);
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
     * $parentType is the type of the tl_content element this one sits in (null
     * for an element directly in an article, news entry, …) — see
     * {@see CONTEXT_FIELDS} and {@see parentTypeOf()}.
     *
     * @return list<string>
     */
    public function allowedFieldsFor(string $type, ?string $parentType = null): array
    {
        return array_values(array_unique(array_merge(
            self::COMMON_FIELDS,
            $this->resolvePalette($type)['fields'],
            $this->contextFieldsFor($parentType),
            // Extension-owned columns are not in the DCA palette, so without
            // this the validation above rejects them before their provider is
            // ever asked. Only the ones their provider allows on this type:
            // a palette answer offering rsce_data on a text element would be
            // as wrong as refusing it on an RSCE element was.
            $this->providerFields->allowedFor('tl_content', $type),
        )));
    }

    /**
     * The fields among $fields that this element cannot be written with, each
     * with the message the write tools report.
     *
     * One answer for content_create/_update (through apply()), for
     * content_create_tree's check before its first write and for the overrides
     * of entity_duplicate — so the same mistake reads the same whichever tool
     * made it, and no route writes what another refuses. The v1.33.0 report
     * found exactly that: the regular write refused rsce_data and
     * sectionHeadline, a duplicate's overrides wrote both unchecked.
     *
     * Core fields come first, then provider refusals (extension missing, field
     * not valid for this type) in the words ProviderFields::apply() uses.
     *
     * @param list<string> $fields
     *
     * @return array<string, string> field => message
     */
    public function rejectedFields(string $type, array $fields, ?string $parentType = null): array
    {
        $allowed = $this->allowedFieldsFor($type, $parentType);
        $declared = $this->providerFields->declaredFor('tl_content');

        $rejected = [];
        $claimed = [];

        foreach ($fields as $field) {
            if (\in_array($field, $allowed, true)) {
                continue;
            }

            if (\in_array($field, $declared, true)) {
                $claimed[] = $field;
                continue;
            }

            $rejected[$field] = $this->rejectionMessage($field, $type, $allowed, $parentType);
        }

        return $rejected + $this->providerFields->refusals('tl_content', $claimed, $type);
    }

    /**
     * The type of the element a record sits in, when that is an element: the
     * context {@see CONTEXT_FIELDS} depends on. Null for any other parent
     * (article, news entry, event, …) and for a parent that does not exist.
     */
    public function parentTypeOf(string $ptable, int $pid): ?string
    {
        if ($ptable !== 'tl_content' || $pid <= 0) {
            return null;
        }

        $this->framework->initialize();

        $parent = $this->framework->getAdapter(ContentModel::class)->findByPk($pid);

        return $parent instanceof ContentModel ? (string) $parent->type : null;
    }

    /**
     * Every context-dependent field this installation has, with the parent
     * type that adds it — for content_palette_get, which answers per type and
     * would otherwise never mention them.
     *
     * @return array<string, string> field => parent type
     */
    public function contextFields(): array
    {
        return array_filter(
            self::CONTEXT_FIELDS,
            fn (string $parent, string $field): bool => $this->hasField($field),
            \ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return list<string>
     */
    private function contextFieldsFor(?string $parentType): array
    {
        if ($parentType === null) {
            return [];
        }

        return array_keys(array_filter(
            $this->contextFields(),
            static fn (string $parent): bool => $parent === $parentType,
        ));
    }

    /**
     * Whether the type has a palette this mapper can read. A type whose palette
     * is only assembled in the edit mask has none, and then the base fields
     * are all that is left.
     */
    public function hasPalette(string $type): bool
    {
        return $this->resolvePalette($type)['fields'] !== [];
    }

    /**
     * @param list<string> $allowed
     */
    private function rejectionMessage(string $field, string $type, array $allowed, ?string $parentType): string
    {
        $contextParent = $this->contextFields()[$field] ?? null;
        if ($contextParent !== null && $contextParent !== $parentType) {
            return sprintf(
                'Field "%1$s" only exists on an element inside an element of type "%2$s" — Contao adds it from the parent, not from the type. '
                .'Put this element into the %2$s: ptable "tl_content" and pid = the %2$s element (in content_create_tree: as a child of the %2$s node).',
                $field,
                $contextParent,
            );
        }

        // A type whose palette is built at edit time resolves to nothing here,
        // and the base list is all that is left. Saying "see
        // content_palette_get" would then send the caller to an answer that
        // repeats this same list — the field is not missing from a palette,
        // there IS no readable palette.
        if (!$this->hasPalette($type)) {
            return sprintf(
                'Field "%1$s" cannot be written on content type "%2$s": that type has no static palette (it is assembled at edit time), so only the base fields and extension-declared fields are writable here — %3$s. '
                .'This is a validation limit, not a permission check — nothing here can tell what a valid value for that field would be, so it is refused rather than guessed at. '
                .'Two ways to make it writable, both on the extension that owns the type: it can ship its own tools (see installed_bundles, enable them under MCP-Server → Tools), '
                .'or it can declare the field by implementing Netzhirsch\ContaoMcpBundle\Tool\Contract\FieldProvider and tagging that service `netzhirsch.field_provider` — declared fields skip the palette check and are validated by the extension itself.',
                $field,
                $type,
                implode(', ', $allowed),
            );
        }

        return sprintf(
            'Field "%1$s" is not valid for content type "%2$s". Use content_palette_get("%2$s") to see allowed fields. Currently allowed: %3$s.',
            $field,
            $type,
            implode(', ', $allowed),
        );
    }

    private function hasField(string $field): bool
    {
        $this->framework->initialize();
        $this->framework->getAdapter(Controller::class)->loadDataContainer('tl_content');

        return isset($GLOBALS['TL_DCA']['tl_content']['fields'][$field]);
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

        $dca = $GLOBALS['TL_DCA']['tl_content'] ?? [];

        // RSCE builds an rsce_* palette in the edit mask's onload callback,
        // which a DCA loaded here never runs. Rebuilt from the type's config
        // the same way (RscePalette), on a copy — the global DCA stays as
        // Contao loaded it.
        if (trim((string) ($dca['palettes'][$type] ?? '')) === '') {
            $palette = $this->rsce->paletteFor($type, $dca);
            if ($palette !== null) {
                $dca['palettes'][$type] = $palette;
            }
        }

        return DcaPalette::resolve($dca, $type);
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


    /**
     * @param mixed $current the value currently stored in that column, so a
     *                       partial update to a tuple field can be merged
     *                       instead of rebuilt from defaults
     */
    private function castValue(string $field, mixed $value, mixed $current = null): mixed
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
            // Merged against what is stored, not rebuilt from defaults. Sending
            // only {unit: "h1"} used to blank the headline text and report
            // success; sending only {value: "…"} reset the level to h2. See
            // Service\SerializedTuple.
            try {
                return serialize(SerializedTuple::headline($value, $current));
            } catch (\InvalidArgumentException) {
                throw new \InvalidArgumentException(sprintf('"%s" must be a string or an object {value, unit}.', $field));
            }
        }

        if (isset(self::STRING_PAIR_FIELDS[$field])) {
            // Plain string → store as-is (already-serialised / raw, back-compat).
            if (\is_string($value)) {
                return $value;
            }

            [$keyA, $keyB] = self::STRING_PAIR_FIELDS[$field];

            if (!\is_array($value) && !\is_object($value)) {
                throw new \InvalidArgumentException(sprintf('"%s" must be a string or an object {%s, %s}.', $field, $keyA, $keyB));
            }

            // Same merge as the headline tuple: one column holding two things
            // cannot be written from one of them.
            [$a, $b] = SerializedTuple::pair($value, $current, $keyA, $keyB);

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
