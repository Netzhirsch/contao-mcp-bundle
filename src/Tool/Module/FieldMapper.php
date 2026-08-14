<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Module;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\ModuleModel;
use Contao\StringUtil;

/**
 * Field mapper for tl_module. tl_module is the type-driven sibling of tl_content:
 * one row backs one front-end module instance, and which columns are valid depends
 * on the module's type (selected from $GLOBALS['FE_MOD']).
 *
 * We resolve the allowed field set at runtime from the live DCA palette, exactly
 * like the Content tool. Tools pass:
 *   - (pid, type, name) as top-level params
 *   - a `fields` dict for everything else
 */
final class FieldMapper
{
    /**
     * Always-allowed core columns for every module type.
     *
     * @var list<string>
     */
    public const COMMON_FIELDS = [
        'pid', 'type', 'name', 'headline', 'cssID', 'customTpl', 'protected', 'guests', 'groups',
    ];

    /**
     * Serialised list<int> fields — typically tl_page id sets.
     *
     * @var list<string>
     */
    private const INT_LIST_FIELDS = ['pages', 'rootPage', 'groups', 'reg_groups', 'newsArchives', 'cal_calendar', 'faq_categories'];

    /**
     * Serialised list<string> fields.
     *
     * @var list<string>
     */
    private const STRING_LIST_FIELDS = ['orderField', 'orderEvents', 'orderFaqs', 'orderNews'];

    /**
     * Headline tuple (inputUnit): {value, unit:h1..h6} or plain string defaulting to h2.
     *
     * @var list<string>
     */
    private const HEADLINE_TUPLE_FIELDS = ['headline'];

    /**
     * tinyint flags — accept bool, store 0/1.
     *
     * @var list<string>
     */
    private const BOOL_FIELDS = [
        'protected', 'guests', 'showLevel', 'hardLimit', 'showProtected', 'defineRoot',
        'showHidden', 'autologin', 'redirectBack', 'tableless', 'numberOfItems', 'perPage',
    ];

    /**
     * Single int fields that point at a page id (pageTree, single-select).
     *
     * @var list<string>
     */
    private const SINGLE_PAGE_FIELDS = ['jumpTo', 'overviewPage'];

    public function __construct(private readonly ContaoFramework $framework)
    {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    public function apply(ModuleModel $module, array $input, bool $detectChanges = true): array
    {
        $this->framework->initialize();

        $type = $this->resolveType($module, $input);

        if (!$this->isKnownType($type)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown module type "%s". Call module_types_list for the currently registered types.',
                $type,
            ));
        }

        $allowed = $this->allowedFieldsFor($type);

        foreach (array_keys($input) as $field) {
            if (!\in_array($field, $allowed, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" is not valid for module type "%s". Call module_palette_get("%s"). Allowed fields: %s',
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
            if (!$detectChanges || self::isDifferent($module->$field, $newValue)) {
                $module->$field = $newValue;
                $touch($field);
            }
        }

        return $changed;
    }

    /**
     * @return list<string>
     */
    public function allowedFieldsFor(string $type): array
    {
        $this->framework->initialize();

        $adapter = $this->framework->getAdapter(Controller::class);
        $adapter->loadDataContainer('tl_module');

        $dca = $GLOBALS['TL_DCA']['tl_module'] ?? [];
        $palette = $dca['palettes'][$type] ?? '';
        $subpalettes = $dca['subpalettes'] ?? [];

        $fields = self::extractFields($palette);
        foreach ($subpalettes as $subFields) {
            foreach (self::extractFields((string) $subFields) as $f) {
                $fields[] = $f;
            }
        }

        return array_values(array_unique(array_merge(self::COMMON_FIELDS, $fields)));
    }

    /**
     * @return array<string, list<string>>
     */
    public function listTypesGrouped(): array
    {
        $this->framework->initialize();

        $feMod = $GLOBALS['FE_MOD'] ?? [];
        $grouped = [];
        foreach ($feMod as $category => $types) {
            if (!\is_array($types)) {
                continue;
            }
            $grouped[(string) $category] = array_values(array_filter(array_map('strval', array_keys($types))));
        }

        return $grouped;
    }

    /**
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
     * @param array<string, mixed> $input
     */
    private function resolveType(ModuleModel $module, array $input): string
    {
        if (\array_key_exists('type', $input) && $input['type'] !== null && $input['type'] !== '') {
            return (string) $input['type'];
        }
        $current = (string) $module->type;

        return $current !== '' ? $current : 'html';
    }

    /**
     * @return list<string>
     */
    private static function extractFields(string $palette): array
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

    private function castValue(string $field, mixed $value): mixed
    {
        if (\in_array($field, self::BOOL_FIELDS, true)) {
            return $value ? 1 : 0;
        }

        if (\in_array($field, self::SINGLE_PAGE_FIELDS, true)) {
            return (int) $value;
        }

        if (\in_array($field, self::INT_LIST_FIELDS, true)) {
            if (!\is_array($value)) {
                throw new \InvalidArgumentException(sprintf('"%s" must be a list of integers.', $field));
            }
            $cleaned = array_values(array_map('intval', $value));

            return $cleaned === [] ? '' : serialize($cleaned);
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
                return serialize(['unit' => 'h2', 'value' => $value]);
            }
            if (\is_array($value) || \is_object($value)) {
                $arr = (array) $value;
                $unit = (string) ($arr['unit'] ?? 'h2');
                $val = (string) ($arr['value'] ?? '');
                if (!\in_array($unit, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                    throw new \InvalidArgumentException(sprintf('headline.unit must be h1..h6, got "%s"', $unit));
                }
                return serialize(['unit' => $unit, 'value' => $val]);
            }
            throw new \InvalidArgumentException('headline must be a string or {value, unit:h1..h6}');
        }

        // pid + type + name + everything textual.
        if ($field === 'pid') {
            return (int) $value;
        }

        return $value;
    }

    private static function isDifferent(mixed $current, mixed $next): bool
    {
        if (\is_array($current) || \is_array($next)) {
            return serialize($current) !== serialize($next);
        }

        return (string) $current !== (string) $next;
    }
}
