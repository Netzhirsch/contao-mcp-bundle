<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Layout;

use Contao\FilesModel;
use Contao\LayoutModel;
use Netzhirsch\ContaoMcpBundle\Service\ProviderFields;

/**
 * Maps the MCP-facing parameter dict to LayoutModel column writes.
 *
 * Pass-through fields go in as plain strings/bools/ints. Special handling:
 *   - modules:    list<{mod: int, col: string, enable?: bool}> → serialized
 *   - sections:   list<{id: string, title: string, position?: 'top'|...}> → serialized
 *   - framework:  list<string>  (e.g. ['layout.css','responsive.css'])    → serialized
 *   - jquery / mootools / analytics / scripts: list<string> template names → serialized
 *   - external / external_js: list<string> paths → list<UUID> → serialized
 *   - title_tag / css_class / viewport / head / script / onload: text, validated length
 *   - bool flags persisted as 1/0 (MySQL strict mode + tinyint requirement)
 */
final class FieldMapper
{
    private const TEXT_FIELDS = [
        'header_height' => 'headerHeight',
        'footer_height' => 'footerHeight',
        'width_left' => 'widthLeft',
        'width_right' => 'widthRight',
        'lightbox_size' => 'lightboxSize',
        'default_image_densities' => 'defaultImageDensities',
        'viewport' => 'viewport',
        'title_tag' => 'titleTag',
        'css_class' => 'cssClass',
        'onload' => 'onload',
        'head' => 'head',
        'script' => 'script',
        'width' => 'width',
        'align' => 'align',
        'template' => 'template',
    ];

    private const ROWS_VALUES = ['1rw', '2rwh', '2rwf', '3rw'];
    private const COLS_VALUES = ['1cl', '2cll', '2clr', '3cl'];
    private const TYPE_VALUES = ['default', 'modern'];
    private const FRAMEWORK_VALUES = ['layout.css', 'responsive.css', 'grid.css', 'reset.css', 'form.css', 'icons.css'];

    public function __construct(
        private readonly ProviderFields $providerFields,
    ) {
    }

    /**
     * Columns installed extensions contribute for tl_layout. Reported so the
     * Tool layer can tell "unknown key" from "key of a missing extension".
     *
     * @return list<string>
     */
    public function providerFields(): array
    {
        return $this->providerFields->declaredFor('tl_layout');
    }

    /**
     * @return array{errors: list<string>, applied: int, applied_keys: list<string>}
     */
    public function apply(LayoutModel $l, array $input): array
    {
        $errors = [];
        $applied = 0;
        $appliedKeys = [];

        if (\array_key_exists('name', $input)) {
            $value = trim((string) $input['name']);
            if ($value === '') {
                $errors[] = 'name must not be empty';
            } else {
                $l->name = mb_substr($value, 0, 255);
                ++$applied;
                $appliedKeys[] = 'name';
            }
        }

        if (\array_key_exists('type', $input)) {
            $value = (string) $input['type'];
            if (!\in_array($value, self::TYPE_VALUES, true)) {
                $errors[] = 'type must be one of: '.implode(', ', self::TYPE_VALUES);
            } else {
                $l->type = $value;
                ++$applied;
                $appliedKeys[] = 'type';
            }
        }

        if (\array_key_exists('rows', $input)) {
            $value = (string) $input['rows'];
            if (!\in_array($value, self::ROWS_VALUES, true)) {
                $errors[] = 'rows must be one of: '.implode(', ', self::ROWS_VALUES);
            } else {
                $l->rows = $value;
                ++$applied;
                $appliedKeys[] = 'rows';
            }
        }

        if (\array_key_exists('cols', $input)) {
            $value = (string) $input['cols'];
            if (!\in_array($value, self::COLS_VALUES, true)) {
                $errors[] = 'cols must be one of: '.implode(', ', self::COLS_VALUES);
            } else {
                $l->cols = $value;
                ++$applied;
                $appliedKeys[] = 'cols';
            }
        }

        foreach (self::TEXT_FIELDS as $key => $column) {
            if (\array_key_exists($key, $input)) {
                $l->{$column} = (string) ($input[$key] ?? '');
                ++$applied;
                $appliedKeys[] = $key;
            }
        }

        if (\array_key_exists('combine_scripts', $input)) {
            $l->combineScripts = $this->toBool($input['combine_scripts']);
            ++$applied;
            $appliedKeys[] = 'combine_scripts';
        }
        if (\array_key_exists('minify_markup', $input)) {
            $l->minifyMarkup = $this->toBool($input['minify_markup']);
            ++$applied;
            $appliedKeys[] = 'minify_markup';
        }
        if (\array_key_exists('add_jquery', $input)) {
            $l->addJQuery = $this->toBool($input['add_jquery']);
            ++$applied;
            $appliedKeys[] = 'add_jquery';
        }
        if (\array_key_exists('add_mootools', $input)) {
            $l->addMooTools = $this->toBool($input['add_mootools']);
            ++$applied;
            $appliedKeys[] = 'add_mootools';
        }
        if (\array_key_exists('static', $input)) {
            $l->static = $this->toBool($input['static']);
            ++$applied;
            $appliedKeys[] = 'static';
        }

        if (\array_key_exists('framework', $input)) {
            [$blob, $err] = $this->mapStringList($input['framework'], 'framework', self::FRAMEWORK_VALUES);
            if ($err !== null) {
                $errors[] = $err;
            } else {
                $l->framework = $blob;
                ++$applied;
                $appliedKeys[] = 'framework';
            }
        }

        foreach (['jquery', 'mootools', 'analytics', 'scripts'] as $listKey) {
            if (\array_key_exists($listKey, $input)) {
                [$blob, $err] = $this->mapStringList($input[$listKey], $listKey);
                if ($err !== null) {
                    $errors[] = $err;
                } else {
                    $l->{$listKey} = $blob;
                    ++$applied;
                    $appliedKeys[] = $listKey;
                }
            }
        }

        if (\array_key_exists('modules', $input)) {
            [$blob, $err] = $this->mapModules($input['modules']);
            if ($err !== null) {
                $errors[] = $err;
            } else {
                $l->modules = $blob;
                ++$applied;
                $appliedKeys[] = 'modules';
            }
        }

        if (\array_key_exists('sections', $input)) {
            [$blob, $err] = $this->mapSections($input['sections']);
            if ($err !== null) {
                $errors[] = $err;
            } else {
                $l->sections = $blob;
                ++$applied;
                $appliedKeys[] = 'sections';
            }
        }

        foreach (['external' => 'external', 'external_js' => 'externalJs'] as $key => $column) {
            if (\array_key_exists($key, $input)) {
                [$blob, $err] = $this->mapFilePaths($input[$key], $key);
                if ($err !== null) {
                    $errors[] = $err;
                } else {
                    $l->{$column} = $blob;
                    ++$applied;
                    $appliedKeys[] = $key;
                }
            }
        }

        // Providers last, and their errors block the save — same contract as
        // tl_theme, so an extension can reject a value instead of letting a
        // broken one through.
        $fromProviders = $this->providerFields->apply('tl_layout', $l, $input);
        $applied += \count($fromProviders['applied']);
        $appliedKeys = array_merge($appliedKeys, $fromProviders['applied']);
        $errors = array_merge($errors, $fromProviders['errors']);

        return ['errors' => $errors, 'applied' => $applied, 'applied_keys' => $appliedKeys];
    }

    private function toBool(mixed $v): int
    {
        return (bool) $v ? 1 : 0;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function mapStringList(mixed $value, string $field, ?array $whitelist = null): array
    {
        if ($value === null || $value === '') {
            return ['', null];
        }
        if (!\is_array($value) || !array_is_list($value)) {
            return [null, sprintf('%s must be a list of strings', $field)];
        }
        $clean = [];
        foreach ($value as $entry) {
            if (!\is_string($entry)) {
                return [null, sprintf('%s entries must be strings', $field)];
            }
            if ($whitelist !== null && !\in_array($entry, $whitelist, true)) {
                return [null, sprintf('%s entry "%s" not allowed; valid: %s', $field, $entry, implode(', ', $whitelist))];
            }
            $clean[] = $entry;
        }

        return [serialize($clean), null];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function mapModules(mixed $value): array
    {
        if ($value === null) {
            return ['', null];
        }
        if (!\is_array($value) || !array_is_list($value)) {
            return [null, 'modules must be a list of {mod, col, enable?} objects'];
        }
        $clean = [];
        foreach ($value as $i => $entry) {
            if (!\is_array($entry)) {
                return [null, sprintf('modules[%d] must be an object', $i)];
            }
            // Do NOT int-cast `mod`: it is the article-content placeholder `0`
            // or a tl_module id (numeric) — BUT Contao also stores prefixed
            // string references like `content-149` (a specific content element)
            // here. `(int) 'content-149'` === 0 silently destroyed those
            // references. Keep numeric values as int, everything else verbatim.
            $rawMod = $entry['mod'] ?? 0;
            $mod = is_numeric($rawMod) ? (int) $rawMod : (string) $rawMod;
            $col = (string) ($entry['col'] ?? 'main');
            $enable = (bool) ($entry['enable'] ?? true);
            // col is free-form (custom sections allowed); mod=0 is the
            // article/content placeholder.
            if ($col === '') {
                return [null, sprintf('modules[%d].col must not be empty', $i)];
            }
            $clean[] = ['mod' => $mod, 'col' => $col, 'enable' => $enable ? '1' : ''];
        }

        // Empty modules → a:0:{} (NOT ''): the frontend reads modules without
        // force-array (PageRegular::compile foreach) — '' deserialises to null
        // and foreach(null) is a fatal frontend error.
        return [serialize($clean), null];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function mapSections(mixed $value): array
    {
        if ($value === null) {
            return ['', null];
        }
        if (!\is_array($value) || !array_is_list($value)) {
            return [null, 'sections must be a list of {id, title, position?, template?, cssID?} objects'];
        }
        $clean = [];
        foreach ($value as $i => $entry) {
            if (!\is_array($entry)) {
                return [null, sprintf('sections[%d] must be an object', $i)];
            }
            $id = (string) ($entry['id'] ?? '');
            $title = (string) ($entry['title'] ?? '');
            if ($id === '' || $title === '') {
                return [null, sprintf('sections[%d] requires id + title', $i)];
            }
            $clean[] = [
                'id' => $id,
                'title' => $title,
                'position' => (string) ($entry['position'] ?? 'manual'),
                'template' => (string) ($entry['template'] ?? ''),
                'cssID' => (string) ($entry['cssID'] ?? ''),
            ];
        }

        // Empty sections → '' (not a:0:{}): sectionWizard::generate() does an
        // unguarded `!$this->varValue[0]` that throws on a deserialized [],
        // crashing the backend layout edit (HTTP 500).
        return [$clean === [] ? '' : serialize($clean), null];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function mapFilePaths(mixed $value, string $field): array
    {
        if ($value === null) {
            return ['', null];
        }
        if (!\is_array($value) || !array_is_list($value)) {
            return [null, sprintf('%s must be a list of file paths', $field)];
        }
        $uuids = [];
        foreach ($value as $path) {
            if (!\is_string($path) || $path === '') {
                return [null, sprintf('%s entries must be non-empty strings', $field)];
            }
            $model = FilesModel::findByPath($path);
            if ($model === null) {
                return [null, sprintf('%s entry "%s" not found in tl_files', $field, $path)];
            }
            $uuids[] = $model->uuid;
        }

        return [serialize($uuids), null];
    }
}
