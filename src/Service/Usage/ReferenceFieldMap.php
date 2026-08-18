<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service\Usage;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Works out, from the DCA, which columns anywhere in the install can point at
 * a given table — the structural half of "where is X used?".
 *
 * Deliberately derived instead of hand-listed. A hardcoded list of "tables
 * with a jumpTo column" (which is what page_delete used to carry) goes stale
 * the moment an extension adds its own page picker, and it never covers the
 * long tail at all: `tl_content.singleSRC`, `tl_layout.modules`,
 * `tl_module.news_archives`, `tl_user.filemount`, …
 *
 * Contao gives us four usable signals per field, checked in this order:
 *
 *   1. `relation.table`          — explicit and unambiguous
 *   2. `foreignKey: 'tl_x.y'`    — how core declares most pickers
 *   3. `inputType: pageTree`     — always tl_page
 *      `inputType: fileTree`     — always tl_files
 *      `inputType: imageSize`    — always tl_image_size
 *
 * The ENCODING (how the value is written) comes from the real column type,
 * not from the DCA, because the same picker stores a single value in an int
 * or `binary(16)` column and a multi-select in a serialized blob.
 */
final class ReferenceFieldMap
{
    /** Encodings a reference column can use. */
    public const ENC_INT = 'int';
    public const ENC_INT_LIST = 'int_list';
    public const ENC_UUID = 'uuid';
    public const ENC_UUID_LIST = 'uuid_list';
    public const ENC_IMAGE_SIZE = 'image_size';
    public const ENC_MODULE_WIZARD = 'module_wizard';
    public const ENC_TEMPLATE_NAME = 'template_name';

    /**
     * Columns that hold a TEMPLATE NAME.
     *
     * These cannot be derived: Contao declares them as a plain `select` whose
     * options come from a callback, with no `relation`, no `foreignKey` and
     * nothing else that says "this is a template". What they do share is a
     * naming convention that core and every extension follow — `customTpl`,
     * `template`, and the `…Tpl` family (`navigationTpl`, `memberTpl`,
     * `searchTpl`, `galleryTpl`, `rss_template`, …).
     *
     * Matching is by EXACT value, so a convention-based column list is safe:
     * a column that is not a template selector will simply never hold a
     * template name.
     */
    private const TEMPLATE_COLUMN_SUFFIXES = ['Tpl', '_tpl', 'Template', '_template'];
    private const TEMPLATE_COLUMN_NAMES = ['customTpl' => true, 'template' => true];

    /**
     * References Contao does NOT declare in the DCA, so deriving alone would
     * miss them. Kept deliberately short — every entry here is a maintenance
     * liability, so it must earn its place by being a reference people
     * actually break.
     *
     * @var array<string, list<array{field: string, target: string, encoding: string}>>
     */
    private const SUPPLEMENT = [
        'tl_layout' => [
            // moduleWizard blob: [['mod' => 5, 'col' => 'main', 'enable' => 1], …].
            // No foreignKey, no relation — yet this is THE way a module gets
            // onto a page, and a stale entry silently drops it from the layout.
            ['field' => 'modules', 'target' => 'tl_module', 'encoding' => self::ENC_MODULE_WIZARD],
        ],
        'tl_content' => [
            // "Content element alias" — a picker without foreignKey, pointing
            // at the tl_content row whose output it repeats.
            ['field' => 'cteAlias', 'target' => 'tl_content', 'encoding' => self::ENC_INT],
        ],
    ];

    /** @var array<string, array<string, list<array{field: string, encoding: string}>>> */
    private array $cache = [];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly SchemaIndex $schema,
        private readonly CacheItemPoolInterface $pool,
    ) {
    }

    /**
     * What a column anchors its reference on — see {@see UsageScanner}'s
     * IDENTITY_* constants. Drives whether a rename breaks it.
     */
    public static function identityFor(string $encoding): string
    {
        return match ($encoding) {
            self::ENC_UUID, self::ENC_UUID_LIST => UsageScanner::IDENTITY_UUID,
            self::ENC_TEMPLATE_NAME => UsageScanner::IDENTITY_NAME,
            default => UsageScanner::IDENTITY_ID,
        };
    }

    /**
     * Columns that can hold a reference to `$targetTable`.
     *
     * @param list<string> $tables Tables to inspect
     *
     * @return array<string, list<array{field: string, encoding: string}>> table => columns
     */
    public function columnsPointingAt(string $targetTable, array $tables): array
    {
        if (isset($this->cache[$targetTable])) {
            return $this->cache[$targetTable];
        }

        // Building this means loading every DCA in the install — ~3 seconds on
        // a normal site, which is unacceptable in front of every delete. The
        // answer is derived purely from DCA files, so it is valid until the
        // code changes; `cache:clear` (which Contao Manager runs on every
        // install/update) is exactly the right invalidation point. The table
        // list goes into the key so adding an extension's tables rebuilds it
        // even without a cache clear.
        $item = $this->pool->getItem(sprintf(
            'netzhirsch_mcp.usage_refs.%s.%s',
            preg_replace('/[^a-z0-9_]/', '', $targetTable) ?? 'x',
            substr(hash('xxh128', implode(',', $tables)), 0, 12),
        ));

        if ($item->isHit()) {
            $cached = $item->get();

            if (\is_array($cached)) {
                /** @var array<string, list<array{field: string, encoding: string}>> $cached */
                return $this->cache[$targetTable] = $cached;
            }
        }

        if (UsageTarget::TABLE_TEMPLATES === $targetTable) {
            $out = $this->templateColumns($tables);
            $this->pool->save($item->set($out));

            return $this->cache[$targetTable] = $out;
        }

        $this->framework->initialize();
        $controller = $this->framework->getAdapter(Controller::class);

        $out = [];

        foreach ($tables as $table) {
            try {
                $controller->loadDataContainer($table);
            } catch (\Throwable) {
                // No DCA (pure storage table, or a bundle that isn't booted) —
                // structural references can't be declared, so there is nothing
                // to find here. The insert-tag scan still covers the table.
                continue;
            }

            $fields = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];

            if (!\is_array($fields)) {
                continue;
            }

            foreach ($fields as $field => $config) {
                if (!\is_string($field) || !\is_array($config)) {
                    continue;
                }

                // Virtual/derived DCA fields have no column to search.
                if (!$this->schema->hasColumn($table, $field)) {
                    continue;
                }

                if ($targetTable !== $this->resolveTargetTable($config)) {
                    continue;
                }

                $encoding = $this->resolveEncoding($table, $field, $config, $targetTable);

                if (null === $encoding) {
                    continue;
                }

                $out[$table][] = ['field' => $field, 'encoding' => $encoding];
            }
        }

        foreach (self::SUPPLEMENT as $table => $entries) {
            if (!\in_array($table, $tables, true)) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry['target'] !== $targetTable || !$this->schema->hasColumn($table, $entry['field'])) {
                    continue;
                }

                foreach ($out[$table] ?? [] as $existing) {
                    if ($existing['field'] === $entry['field']) {
                        continue 2;
                    }
                }

                $out[$table][] = ['field' => $entry['field'], 'encoding' => $entry['encoding']];
            }
        }

        $this->pool->save($item->set($out));

        return $this->cache[$targetTable] = $out;
    }

    /**
     * Template selectors, found by naming convention rather than by DCA —
     * see {@see TEMPLATE_COLUMN_SUFFIXES}. Needs no DCA load at all, which is
     * why template lookups are the fast ones.
     *
     * @param list<string> $tables
     *
     * @return array<string, list<array{field: string, encoding: string}>>
     */
    private function templateColumns(array $tables): array
    {
        $out = [];

        foreach ($tables as $table) {
            foreach (array_keys($this->schema->columns($table)) as $column) {
                if (!$this->schema->isTextType($this->schema->type($table, $column))) {
                    continue;
                }

                if (!isset(self::TEMPLATE_COLUMN_NAMES[$column])) {
                    $matches = false;

                    foreach (self::TEMPLATE_COLUMN_SUFFIXES as $suffix) {
                        if (str_ends_with($column, $suffix)) {
                            $matches = true;
                            break;
                        }
                    }

                    if (!$matches) {
                        continue;
                    }
                }

                $out[$table][] = ['field' => $column, 'encoding' => self::ENC_TEMPLATE_NAME];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveTargetTable(array $config): ?string
    {
        $relation = $config['relation'] ?? null;

        if (\is_array($relation) && \is_string($relation['table'] ?? null) && '' !== $relation['table']) {
            return $relation['table'];
        }

        $foreignKey = $config['foreignKey'] ?? null;

        if (\is_string($foreignKey) && str_contains($foreignKey, '.')) {
            $table = explode('.', $foreignKey, 2)[0];

            if (str_starts_with($table, 'tl_')) {
                return $table;
            }
        }

        return match ($config['inputType'] ?? null) {
            'pageTree' => 'tl_page',
            'fileTree' => 'tl_files',
            'imageSize' => 'tl_image_size',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveEncoding(string $table, string $field, array $config, string $targetTable): ?string
    {
        $type = $this->schema->type($table, $field);

        if ('imageSize' === ($config['inputType'] ?? null)) {
            // Serialized [width, height, sizeIdOrName] — only the third
            // element is a tl_image_size reference.
            return self::ENC_IMAGE_SIZE;
        }

        if ('tl_files' === $targetTable) {
            if ($this->schema->isBinaryType($type)) {
                return self::ENC_UUID;
            }

            return $this->schema->isTextType($type) ? self::ENC_UUID_LIST : null;
        }

        if ($this->schema->isIntType($type)) {
            return self::ENC_INT;
        }

        // A varchar/text/blob picker column holds a serialized id list.
        return $this->schema->isTextType($type) ? self::ENC_INT_LIST : null;
    }
}
