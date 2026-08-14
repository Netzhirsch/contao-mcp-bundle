<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Module;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\ModuleModel;
use Contao\ThemeModel;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\QueryFilterResolver;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;

/**
 * MCP facade for tl_module (front-end modules).
 *
 * Seven tools:
 *   modules_list, module_get, module_create, module_update, module_delete,
 *   module_types_list (which types exist?), module_palette_get (fields per type).
 *
 * Module rows are type-driven. The valid column set depends on the module's
 * `type` and we resolve it at runtime against the live `tl_module` DCA palette,
 * exactly like content elements do.
 *
 * Layout-module references: when a tl_module row is deleted, every tl_layout
 * row referencing it via its serialized `modules` blob keeps a stale entry.
 * We don't auto-prune those (the LLM can call layout_update with a cleaned
 * `modules` list) but `module_delete` reports how many layouts reference it.
 */
final class Tool
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly FieldMapper $mapper,
        private readonly Serializer $serializer,
        private readonly QueryFilterResolver $filterResolver,
        private readonly McpPermissionGuard $guard,
    ) {
    }

    // ───────────────────────────── list ─────────────────────────────

    /**
     * @param object|null $filters DCA-validated filter map. See entity_query_options("tl_module").
     *
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'modules_list',
        description: 'Lists tl_module rows. Optional theme_id filters to one theme; optional type filter narrows to a single module type. q does LIKE-search across DCA-searchable fields; filters is a DCA-validated equality map; updated_after/before take Unix-ts or ISO-8601.',
    )]
    public function list(
        ?int $theme_id = null,
        ?string $type = null,
        int $limit = 100,
        int $offset = 0,
        ?string $q = null,
        #[Schema(type: 'object')] mixed $filters = null,
        ?string $updated_after = null,
        ?string $updated_before = null,
    ): array {
        $this->framework->initialize();

        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);

        $columns = [];
        $values = [];
        if ($theme_id !== null) {
            $columns[] = 'tl_module.pid = ?';
            $values[] = $theme_id;
        }
        if ($type !== null && $type !== '') {
            $columns[] = 'tl_module.type = ?';
            $values[] = $type;
        }

        $search = $this->filterResolver->buildSearchClause('tl_module', $q);
        if ($search !== null) {
            $columns[] = $search['clause'];
            $values = array_merge($values, $search['params']);
        }
        try {
            $filtersArr = $this->filterResolver->normaliseFilters($filters);
            $fr = $this->filterResolver->buildFilterClauses('tl_module', $filtersArr);
            $columns = array_merge($columns, $fr['clauses']);
            $values = array_merge($values, $fr['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_filter', 'message' => $e->getMessage()];
        }
        try {
            $ts = $this->filterResolver->buildTstampRange('tl_module', $updated_after, $updated_before);
            $columns = array_merge($columns, $ts['clauses']);
            $values = array_merge($values, $ts['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $items = ModuleModel::findBy(
            $columns === [] ? null : $columns,
            $values === [] ? null : $values,
            ['order' => 'tl_module.pid, tl_module.name', 'limit' => $limit, 'offset' => $offset],
        );

        $out = [];
        foreach ($items ?? [] as $m) {
            if (!$this->guard->mayRead('tl_module', $m->row())) {
                continue;
            }
            $out[] = Serializer::summary($m);
        }

        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    // ───────────────────────────── get ──────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'module_get',
        description: 'Returns a single tl_module row with every column. Serialised blobs (pages, rootPage, headline tuple, …) are decoded to JSON-friendly shapes. Also reports how many tl_layout rows include this module in their `modules` blob.',
    )]
    public function get(int $id): array
    {
        $this->framework->initialize();

        $module = ModuleModel::findByPk($id);
        if ($module === null) {
            return ['error' => 'not_found', 'message' => sprintf('No module with id %d', $id)];
        }

        return Serializer::full($module) + [
            'layout_references' => $this->countLayoutReferences($id),
        ];
    }

    // ──────────────────────────── create ────────────────────────────

    /**
     * @param object|null $fields Optional type-specific tl_module columns as a JSON object. Use module_palette_get(type) to discover allowed keys. Example: {"pages": [3,5,7], "showLevel": 2}.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'module_create',
        description: <<<'DESC'
            Creates a new tl_module row.

            Required: theme_id, type, name.
            Optional via `fields`: any column appearing in module_palette_get(type) —
            common ones: headline (string or {value, unit:h1..h6}), customTpl,
            cssID, jumpTo (page id), pages (list<int>), rootPage (list<int>),
            navigationTpl, levelOffset, showLevel, showProtected, defineRoot,
            protected (bool), guests (bool), groups (list<int>).

            Wraps in a Versions snapshot and writes to tl_log.
        DESC,
    )]
    public function create(int $theme_id, string $type, string $name, #[Schema(type: 'object')] mixed $fields = null): array
    {
        $this->framework->initialize();

        if (ThemeModel::findByPk($theme_id) === null) {
            return ['error' => 'invalid_input', 'message' => sprintf('No theme with id %d', $theme_id)];
        }
        if (trim($name) === '') {
            return ['error' => 'invalid_input', 'message' => 'name is required'];
        }

        $module = new ModuleModel();
        $module->pid = $theme_id;
        $module->tstamp = time();
        $module->type = $type;
        $module->name = $name;

        $input = array_merge(
            ['pid' => $theme_id, 'type' => $type, 'name' => $name],
            self::normaliseFields($fields),
        );

        try {
            $this->mapper->apply($module, $input, detectChanges: false);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        try {
            $module->save();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->bootVersions((int) $module->id)->create();
        $this->log(
            sprintf('Created module "%s" (id=%d, type=%s, theme=%d)', $name, (int) $module->id, $type, $theme_id),
            __METHOD__,
        );

        return Serializer::full($module) + ['created' => true];
    }

    // ──────────────────────────── update ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    /**
     * @param object|null $fields tl_module columns to change as a JSON object. Pass type= here to switch module type. Use module_palette_get(type) for allowed keys.
     */
    #[McpTool(
        name: 'module_update',
        description: 'Updates a tl_module row. Pass id; everything else goes in `fields`. Pass type= inside fields to switch the module to a different type (the palette of the new type is used for validation). Versions snapshot + tl_log.',
    )]
    public function update(
        int $id,
        #[Schema(type: 'object')] mixed $fields = null,
    ): array
    {
        $this->framework->initialize();

        $module = ModuleModel::findByPk($id);
        if ($module === null) {
            return ['error' => 'not_found', 'message' => sprintf('No module with id %d', $id)];
        }

        $input = self::normaliseFields($fields);
        if ($input === []) {
            return ['error' => 'no_fields', 'message' => 'Pass a non-empty `fields` object.'];
        }

        // If pid moves, validate the new theme.
        if (isset($input['pid']) && (int) $input['pid'] !== (int) $module->pid) {
            if (ThemeModel::findByPk((int) $input['pid']) === null) {
                return ['error' => 'invalid_input', 'message' => sprintf('No theme with id %d', (int) $input['pid'])];
            }
        }

        $versions = $this->bootVersions($id);

        try {
            $changed = $this->mapper->apply($module, $input, detectChanges: true);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        if ($changed === []) {
            return Serializer::full($module) + [
                'updated' => false,
                'id' => (int) $module->id,
                'changed_fields' => [],
                'applied' => 0,
            ];
        }

        $module->tstamp = time();

        try {
            $module->save();
            $versions->create();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->log(
            sprintf('Updated module id=%d (fields: %s)', $id, implode(', ', $changed)),
            __METHOD__,
        );

        return Serializer::full($module) + [
            'updated' => true,
            'id' => (int) $module->id,
            'changed_fields' => $changed,
            'applied' => \count($changed),
        ];
    }

    // ──────────────────────────── delete ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'module_delete',
        description: <<<'DESC'
            Deletes a tl_module row.

            Reports how many tl_layout rows still reference this module via their
            serialized `modules` blob (we don't auto-prune those — call layout_update
            with a cleaned modules[] list afterward if you care).

            Requires confirm_destructive=true to proceed.
        DESC,
    )]
    public function delete(int $id, bool $confirm_destructive = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'module_delete is irreversible. Pass confirm_destructive=true to proceed.',
            ];
        }

        $module = ModuleModel::findByPk($id);
        if ($module === null) {
            return ['error' => 'not_found', 'message' => sprintf('No module with id %d', $id)];
        }

        $layoutRefs = $this->countLayoutReferences($id);

        $this->bootVersions($id);
        $name = (string) $module->name;
        $type = (string) $module->type;
        $themeId = (int) $module->pid;
        $module->delete();

        $this->log(sprintf(
            'Deleted module "%s" (id=%d, type=%s, theme=%d, layouts_still_referencing=%d)',
            $name, $id, $type, $themeId, $layoutRefs,
        ), __METHOD__);

        return [
            'deleted' => true,
            'id' => $id,
            'stale_layout_references' => $layoutRefs,
        ];
    }

    // ─────────────────────── module_types_list ──────────────────────

    /**
     * @return array{categories: array<string, list<string>>, total: int}
     */
    #[McpTool(
        name: 'module_types_list',
        description: 'Lists every tl_module type registered via $GLOBALS["FE_MOD"] (built-in + bundle-contributed). Grouped by Contao category (navigationMenu, user, news, calendar, faq, application, miscellaneous, …). Pass any returned type to module_palette_get.',
    )]
    public function typesList(): array
    {
        $grouped = $this->mapper->listTypesGrouped();
        $total = 0;
        foreach ($grouped as $list) {
            $total += \count($list);
        }

        return ['categories' => $grouped, 'total' => $total];
    }

    // ───────────────────── module_palette_get ───────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'module_palette_get',
        description: 'Returns the field set valid for a given module type (live tl_module DCA palette + common columns). Sub-palette children are always included.',
    )]
    public function paletteGet(string $type): array
    {
        try {
            $fields = $this->mapper->allowedFieldsFor($type);
        } catch (\Throwable $e) {
            return ['error' => 'load_failed', 'message' => $e->getMessage()];
        }

        $known = \in_array($type, $this->mapper->allKnownTypes(), true);

        return [
            'type' => $type,
            'known' => $known,
            'fields' => $fields,
            'count' => \count($fields),
        ];
    }

    // ─────────────────────────── helpers ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function normaliseFields(mixed $fields): array
    {
        if ($fields === null) {
            return [];
        }
        if (\is_object($fields)) {
            return (array) $fields;
        }
        if (!\is_array($fields)) {
            throw new \InvalidArgumentException('`fields` must be a JSON object.');
        }

        return $fields;
    }

    /**
     * Counts how many tl_layout rows mention the given module id in their
     * serialized `modules` blob. We use a substring match — false positives
     * are unlikely because serialize() includes the int marker `i:<id>`.
     */
    private function countLayoutReferences(int $moduleId): int
    {
        $pattern = sprintf('%%i:%d;%%', $moduleId);

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_layout WHERE modules LIKE ?',
            [$pattern],
        );
    }

    private function bootVersions(int $id): Versions
    {
        $v = new Versions('tl_module', $id);
        $v->setUsername($this->authorResolver->getLogUsername());
        $v->setUserId($this->authorResolver->resolve());
        $v->initialize();

        return $v;
    }

    private function log(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::GENERAL, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }
}
