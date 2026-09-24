<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce;

/**
 * Reads and writes the one column an RSCE element keeps its settings in.
 *
 * RockSolid Custom Elements builds its form at edit time from a
 * `rsce_<type>_config.php` and stores every value in `tl_content.rsce_data` as
 * a JSON object: one key per field, a list as an array of objects. The backend
 * never writes that column as such — each virtual field saves into it through a
 * callback — so there is no widget whose rules a caller could follow. This class
 * stands in for them. Pure functions, so it can be tested without RSCE.
 *
 * Three decisions, each taken from how the backend behaves:
 *
 *   - Values are stored the way the backend stores them. An array for a
 *     multi-value field is serialised (DataContainer::row does that before any
 *     save callback runs), a file reference becomes a text UUID (RSCE's own save
 *     callback converts the binary one), a checkbox is '1' or ''. A caller
 *     sending a JSON list, the hex UUID content_get prints for singleSRC, or
 *     true/false gets what the backend would have written for the same input.
 *
 *   - A write is merged into what is stored, key by key. rsce_data is one column
 *     holding a dozen settings, and the lesson of the headline tuple applies
 *     twice over: changing a button URL must not reset the background colour.
 *     null removes a key; a list is replaced as a whole, because list items
 *     have no identity to merge on.
 *
 *   - Unknown keys are refused when the type's config is readable. A typo would
 *     otherwise be stored, reported as applied, and never rendered. Keys that are
 *     already in the stored JSON pass even when the config no longer knows them,
 *     so reading an element and writing it back keeps working after a field was
 *     renamed.
 */
final class RsceData
{
    /** RSCE form entries that hold no value of their own. */
    private const NO_VALUE_TYPES = ['group', 'standardField'];

    private const FILE_TYPES = ['fileTree', 'fineUploader'];

    private const DATE_RGXPS = ['date', 'time', 'datim'];

    /**
     * The caller's input as a key => value map: a JSON string, or the object
     * the MCP client already decoded.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    public static function parse(mixed $input): array
    {
        if (\is_string($input)) {
            if (trim($input) === '') {
                throw new \InvalidArgumentException('rsce_data must be a JSON object with one key per field — an empty string is not one. To change nothing, leave rsce_data out; to remove a key, set it to null.');
            }

            try {
                $input = json_decode($input, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \InvalidArgumentException(sprintf('rsce_data is not valid JSON: %s.', $e->getMessage()));
            }
        } elseif (\is_object($input)) {
            $input = self::objectToArray($input);
        }

        if (!\is_array($input) || ($input !== [] && array_is_list($input))) {
            throw new \InvalidArgumentException('rsce_data must be a JSON object with one key per field, e.g. {"buttonUrl": "https://…"} — not a list and not a single value.');
        }

        return $input;
    }

    /**
     * The stored column, decoded. Anything unreadable counts as empty — RSCE
     * itself reads such a value as "no data" (it only decodes what starts
     * with `{`), so there is nothing a merge could preserve.
     *
     * @return array<string, mixed>
     */
    public static function decode(mixed $column): array
    {
        if (!\is_string($column) || !str_starts_with(ltrim($column), '{')) {
            return [];
        }

        $decoded = json_decode($column, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Validates the input against the type's fields and brings every value
     * into the form RSCE stores. null stays null: it marks a key for removal.
     *
     * @param array<string, mixed>      $patch  parsed input
     * @param array<array-key, mixed>|null $fields the type's `fields` config; null when it could not be read
     * @param array<string, mixed>      $stored the decoded column as it is now
     * @param string                    $paletteTool the tool that lists the type's keys, named in refusals
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    public static function convert(array $patch, ?array $fields, array $stored, string $type, string $path = '', string $paletteTool = 'content_palette_get'): array
    {
        $out = [];

        foreach ($patch as $key => $value) {
            $key = (string) $key;

            if ($value === null || $fields === null) {
                // No config, no rules to apply — the value is stored as sent.
                $out[$key] = $value;
                continue;
            }

            $config = $fields[$key] ?? null;

            if (!self::isDataField($config)) {
                if (\array_key_exists($key, $stored)) {
                    $out[$key] = $value;
                    continue;
                }

                throw new \InvalidArgumentException(self::unknownKey($key, $config, $fields, $type, $path, $paletteTool));
            }

            /** @var array<string, mixed> $config */
            $out[$key] = self::convertValue($key, $value, $config, $stored[$key] ?? null, $type, $path, $paletteTool);
        }

        return $out;
    }

    /**
     * Top-level merge: a key in the patch replaces the stored one, null
     * removes it, everything not mentioned stays.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $patch
     *
     * @return array<string, mixed>
     */
    public static function merge(array $stored, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if ($value === null) {
                unset($stored[$key]);
                continue;
            }

            $stored[$key] = $value;
        }

        return $stored;
    }

    /**
     * Encoded the way RSCE encodes it (json_encode's defaults, `{}` for
     * nothing), so a version diff shows the values that changed and not a
     * different escaping of the same ones.
     *
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException
     */
    public static function encode(array $data): string
    {
        $json = json_encode($data);
        if ($json === false) {
            throw new \InvalidArgumentException(sprintf('rsce_data could not be encoded as JSON: %s.', json_last_error_msg()));
        }

        return $json === '[]' ? '{}' : $json;
    }

    /**
     * The type's fields as the *_palette_get tools list them: what each key is
     * called, what it takes, and what it is stored as.
     *
     * @param array<array-key, mixed> $fields the type's `fields` config
     *
     * @return list<array<string, mixed>>
     */
    public static function describe(array $fields): array
    {
        $out = [];

        foreach ($fields as $name => $config) {
            if (!self::isDataField($config)) {
                continue;
            }

            /** @var array<string, mixed> $config */
            $inputType = (string) ($config['inputType'] ?? '');
            $eval = \is_array($config['eval'] ?? null) ? $config['eval'] : [];

            $entry = ['name' => (string) $name, 'inputType' => $inputType];

            [$label, $description] = self::label($config['label'] ?? null);
            if ($label !== '') {
                $entry['label'] = $label;
            }
            if ($description !== '') {
                $entry['description'] = $description;
            }

            $entry['value'] = self::valueHint($inputType, $eval);

            if (!empty($eval['multiple'])) {
                $entry['multiple'] = true;
            }
            if (!empty($eval['mandatory'])) {
                $entry['mandatory'] = true;
            }

            $options = self::options($config);
            if ($options !== null) {
                $entry['options'] = $options;
            } elseif (isset($config['options_callback']) || isset($config['foreignKey'])) {
                // Computed per record at edit time; nothing here can run it.
                $entry['options'] = 'computed at edit time — not listable here';
            }

            if (\array_key_exists('default', $config) && (\is_scalar($config['default']) || \is_array($config['default']))) {
                $entry['default'] = $config['default'];
            }

            if (isset($config['dependsOn'])) {
                // Stored anyway, but the backend drops the value on its next
                // save while the dependency is not met.
                $entry['depends_on'] = \is_string($config['dependsOn']) ? ['field' => $config['dependsOn']] : $config['dependsOn'];
            }

            if ($inputType === 'list') {
                $entry['min_items'] = (int) ($config['minItems'] ?? 0);
                if (isset($config['maxItems'])) {
                    $entry['max_items'] = (int) $config['maxItems'];
                }
                $entry['fields'] = self::describe(\is_array($config['fields'] ?? null) ? $config['fields'] : []);
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * A config entry that stores a value in rsce_data. Group headings (a plain
     * string, or inputType "group") and standardField entries (a regular
     * column shown inside the RSCE form) do not.
     */
    private static function isDataField(mixed $config): bool
    {
        return \is_array($config) && !\in_array($config['inputType'] ?? '', self::NO_VALUE_TYPES, true);
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    private static function unknownKey(string $key, mixed $config, array $fields, string $type, string $path, string $paletteTool): string
    {
        if (\is_array($config) && ($config['inputType'] ?? '') === 'standardField') {
            return sprintf(
                'rsce_data: "%s%s" is a regular column of %s, not a key inside rsce_data — pass it as its own field next to rsce_data.',
                $path,
                $key,
                $type,
            );
        }

        if ($config !== null) {
            return sprintf('rsce_data: "%s%s" is a group heading of the %s form and holds no value.', $path, $key, $type);
        }

        $names = [];
        foreach ($fields as $name => $candidate) {
            if (self::isDataField($candidate)) {
                $names[] = (string) $name;
            }
        }

        return sprintf(
            'rsce_data: "%s%s" is not a field of %s. %s %s("%s") lists them with their input types.',
            $path,
            $key,
            $type,
            $names === [] ? 'It has no fields at this level.' : 'Its fields here are: '.implode(', ', $names).'.',
            $paletteTool,
            $type,
        );
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws \InvalidArgumentException
     */
    private static function convertValue(string $key, mixed $value, array $config, mixed $stored, string $type, string $path, string $paletteTool): mixed
    {
        $inputType = (string) ($config['inputType'] ?? '');
        $eval = \is_array($config['eval'] ?? null) ? $config['eval'] : [];
        $multiple = !empty($eval['multiple']);
        $where = $path.$key;

        if ($inputType === 'list') {
            return self::convertList($where, $value, $config, $stored, $type, $paletteTool);
        }

        if (\in_array($inputType, self::FILE_TYPES, true)) {
            return self::fileValue($where, $value, $multiple);
        }

        if (\is_bool($value)) {
            return $value ? '1' : '';
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if (\is_array($value)) {
            if ($value === []) {
                return '';
            }

            // What DataContainer::row does with every array a widget returns,
            // before RSCE's save callback ever sees it.
            ksort($value);

            return serialize($value);
        }

        if (!\is_string($value)) {
            throw new \InvalidArgumentException(sprintf('rsce_data: "%s" cannot store a value of type %s.', $where, get_debug_type($value)));
        }

        if ($value !== '' && \in_array($eval['rgxp'] ?? null, self::DATE_RGXPS, true) && !ctype_digit($value)) {
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                throw new \InvalidArgumentException(sprintf('rsce_data: "%s" expects a date — got "%s". Use ISO 8601 or a unix timestamp.', $where, $value));
            }

            return (string) $timestamp;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<array<string, mixed>>
     *
     * @throws \InvalidArgumentException
     */
    private static function convertList(string $where, mixed $value, array $config, mixed $stored, string $type, string $paletteTool): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException(sprintf('rsce_data: "%s" is a list — pass an array with one object per item.', $where));
        }

        if (isset($config['maxItems']) && (int) $config['maxItems'] > 0 && \count($value) > (int) $config['maxItems']) {
            throw new \InvalidArgumentException(sprintf(
                'rsce_data: "%s" takes at most %d items, got %d.',
                $where,
                (int) $config['maxItems'],
                \count($value),
            ));
        }

        $itemFields = \is_array($config['fields'] ?? null) ? $config['fields'] : [];

        // Stale-key tolerance for items, too: any key some stored item carries.
        $storedKeys = [];
        foreach (\is_array($stored) ? $stored : [] as $item) {
            if (\is_array($item)) {
                $storedKeys = array_merge($storedKeys, $item);
            }
        }

        $items = [];
        foreach ($value as $i => $item) {
            if (!\is_array($item) || ($item !== [] && array_is_list($item))) {
                throw new \InvalidArgumentException(sprintf('rsce_data: "%s[%d]" must be an object with the item\'s fields.', $where, $i));
            }

            $converted = self::convert($item, $itemFields, $storedKeys, $type, sprintf('%s[%d].', $where, $i), $paletteTool);

            // An item is written whole; a null inside it just leaves the key out.
            $items[] = array_filter($converted, static fn (mixed $v): bool => $v !== null);
        }

        return $items;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function fileValue(string $where, mixed $value, bool $multiple): string
    {
        if (!$multiple) {
            if (\is_array($value) && \count($value) === 1) {
                $value = reset($value);
            }
            if (!\is_string($value)) {
                throw new \InvalidArgumentException(sprintf('rsce_data: "%s" takes one file UUID as a string.', $where));
            }

            return $value === '' ? '' : self::uuid($where, $value);
        }

        if (\is_string($value)) {
            if ($value === '') {
                return '';
            }

            // Already in stored form (a serialised list), or a single UUID.
            $list = @unserialize($value, ['allowed_classes' => false]);
            $value = \is_array($list) ? $list : [$value];
        }

        if (!\is_array($value)) {
            throw new \InvalidArgumentException(sprintf('rsce_data: "%s" takes a list of file UUIDs.', $where));
        }

        $uuids = [];
        foreach ($value as $one) {
            $uuids[] = self::uuid($where, \is_string($one) ? $one : '');
        }

        return $uuids === [] ? '' : serialize($uuids);
    }

    /**
     * RSCE stores file references as text UUIDs; content_get prints the
     * binary columns of tl_content as 32-character hex. Both are accepted.
     *
     * @throws \InvalidArgumentException
     */
    private static function uuid(string $where, string $value): string
    {
        $raw = strtolower(trim($value));

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $raw) === 1) {
            return $raw;
        }

        if (preg_match('/^[0-9a-f]{32}$/', $raw) === 1) {
            return sprintf('%s-%s-%s-%s-%s', substr($raw, 0, 8), substr($raw, 8, 4), substr($raw, 12, 4), substr($raw, 16, 4), substr($raw, 20));
        }

        throw new \InvalidArgumentException(sprintf(
            'rsce_data: "%s" expects a file UUID (xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx, or the 32-character hex form) — got "%s".',
            $where,
            $value,
        ));
    }

    /**
     * @param array<array-key, mixed> $eval
     */
    private static function valueHint(string $inputType, array $eval): string
    {
        $multiple = !empty($eval['multiple']);

        return match (true) {
            $inputType === 'list' => 'list of objects, one per item, with the keys under "fields"',
            \in_array($inputType, self::FILE_TYPES, true) && $multiple => 'list of file UUIDs (the 32-character hex form is converted)',
            \in_array($inputType, self::FILE_TYPES, true) => 'one file UUID, e.g. from files_list (the 32-character hex form is converted)',
            $inputType === 'pageTree' && $multiple => 'list of page ids',
            $inputType === 'pageTree' => 'one page id',
            $inputType === 'checkbox' && !$multiple => '"1" when set, "" when not (true/false are converted)',
            $multiple => 'list of option values',
            \in_array($eval['rgxp'] ?? null, self::DATE_RGXPS, true) => 'ISO 8601 date or unix timestamp (stored as timestamp)',
            $inputType === 'inputUnit' => 'object {value, unit}',
            $inputType === 'imageSize' => 'list [width, height, mode or image size id]',
            $inputType === 'url' => 'URL (insert tags such as {{link_url::12}} work)',
            default => 'string',
        };
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<array<string, string>>|null
     */
    private static function options(array $config): ?array
    {
        if (!\is_array($config['options'] ?? null)) {
            return null;
        }

        $reference = \is_array($config['reference'] ?? null) ? $config['reference'] : [];
        $isList = array_is_list($config['options']);
        $out = [];

        foreach ($config['options'] as $key => $option) {
            if (\is_array($option)) {
                // An option group: the key is its label.
                foreach ($option as $subKey => $subOption) {
                    if (!\is_scalar($subOption)) {
                        continue;
                    }
                    $value = array_is_list($option) ? (string) $subOption : (string) $subKey;
                    $out[] = self::option($value, array_is_list($option) ? null : $subOption, $reference) + ['group' => (string) $key];
                }
                continue;
            }

            if (!\is_scalar($option)) {
                continue;
            }

            $value = $isList ? (string) $option : (string) $key;
            $out[] = self::option($value, $isList ? null : $option, $reference);
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $reference
     *
     * @return array<string, string>
     */
    private static function option(string $value, mixed $label, array $reference): array
    {
        if (\array_key_exists($value, $reference)) {
            $label = $reference[$value];
        }

        [$text] = self::label($label);

        return $text !== '' && $text !== $value ? ['value' => $value, 'label' => $text] : ['value' => $value];
    }

    /**
     * RSCE labels are a string, a [label, description] pair, or a map of
     * language => either of those. Resolved the way RSCE resolves them
     * (CustomElements::getLabelTranslated): current language, its short code,
     * English, then the first language key.
     *
     * @return array{0: string, 1: string}
     */
    private static function label(mixed $label): array
    {
        if (\is_array($label) && array_filter(array_keys($label), 'is_string') !== []) {
            $language = str_replace('-', '_', (string) ($GLOBALS['TL_LANGUAGE'] ?? 'en'));
            $resolved = $label[$language] ?? $label[substr($language, 0, 2)] ?? $label['en'] ?? null;

            if ($resolved === null) {
                foreach ($label as $key => $candidate) {
                    $key = (string) $key;
                    if (\strlen($key) === 2 || substr($key, 2, 1) === '_') {
                        $resolved = $candidate;
                        break;
                    }
                }
            }

            $label = $resolved;
        }

        if (\is_array($label)) {
            return [
                \is_scalar($label[0] ?? null) ? (string) $label[0] : '',
                \is_scalar($label[1] ?? null) ? (string) $label[1] : '',
            ];
        }

        return [\is_scalar($label) ? (string) $label : '', ''];
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws \InvalidArgumentException
     */
    private static function objectToArray(object $input): array
    {
        try {
            $decoded = json_decode(json_encode($input, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(sprintf('rsce_data could not be read: %s.', $e->getMessage()));
        }

        return \is_array($decoded) ? $decoded : [];
    }
}
