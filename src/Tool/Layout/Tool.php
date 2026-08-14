<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Layout;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\LayoutModel;
use Contao\PageModel;
use Contao\ThemeModel;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\QueryFilterResolver;
use Netzhirsch\ContaoMcpBundle\Service\UpdateDiff;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;

/**
 * MCP facade for tl_layout. Five operations:
 *   layouts_list, layout_get, layout_create, layout_update, layout_delete.
 *
 * Layouts have a fixed schema (unlike tl_module which is type-driven), so we
 * model them with explicit named parameters. The bulk of the field mapping
 * lives in {@see FieldMapper}.
 *
 * Delete-safety: refuses to drop a layout that is still referenced by
 * tl_page.layout or tl_page.subpageLayout unless force=true, in which case
 * those pages get their layout reset to 0 (fall back to inherited layout).
 */
final class Tool
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly FieldMapper $mapper,
        private readonly QueryFilterResolver $filterResolver,
        private readonly McpPermissionGuard $guard,
    ) {
    }

    // ───────────────────────────── list ─────────────────────────────

    /**
     * @param object|null $filters DCA-validated filter map. See entity_query_options("tl_layout").
     *
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'layouts_list',
        description: 'Lists Contao page layouts (tl_layout). Use the returned id as page.layout or page.subpageLayout. Optional theme_id filters to a single theme. q does LIKE-search across DCA-searchable fields; filters is a DCA-validated equality map; updated_after/before take Unix-ts or ISO-8601.',
    )]
    public function list(
        ?int $theme_id = null,
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
            $columns[] = 'tl_layout.pid = ?';
            $values[] = $theme_id;
        }

        $search = $this->filterResolver->buildSearchClause('tl_layout', $q);
        if ($search !== null) {
            $columns[] = $search['clause'];
            $values = array_merge($values, $search['params']);
        }
        try {
            $filtersArr = $this->filterResolver->normaliseFilters($filters);
            $fr = $this->filterResolver->buildFilterClauses('tl_layout', $filtersArr);
            $columns = array_merge($columns, $fr['clauses']);
            $values = array_merge($values, $fr['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_filter', 'message' => $e->getMessage()];
        }
        try {
            $ts = $this->filterResolver->buildTstampRange('tl_layout', $updated_after, $updated_before);
            $columns = array_merge($columns, $ts['clauses']);
            $values = array_merge($values, $ts['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $items = LayoutModel::findBy(
            $columns === [] ? null : $columns,
            $values === [] ? null : $values,
            ['order' => 'tl_layout.pid, tl_layout.name', 'limit' => $limit, 'offset' => $offset],
        );

        $out = [];
        foreach ($items ?? [] as $layout) {
            if (!$this->guard->mayRead('tl_layout', $layout->row())) {
                continue;
            }
            $out[] = Serializer::summary($layout);
        }

        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    // ───────────────────────────── get ──────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'layout_get',
        description: 'Returns a single layout by id, including all 30+ DCA columns (sections, modules[], framework[], external CSS/JS file paths, scripts, viewport, head…). Also reports how many tl_page rows reference this layout via .layout / .subpageLayout.',
    )]
    public function get(int $id): array
    {
        $this->framework->initialize();

        $layout = LayoutModel::findByPk($id);
        if ($layout === null) {
            return ['error' => 'not_found', 'message' => sprintf('No layout with id %d', $id)];
        }

        return Serializer::full($layout) + [
            'page_references' => $this->countPageReferences($id),
        ];
    }

    // ──────────────────────────── create ────────────────────────────

    /**
     * @param object|null $fields Optional tl_layout columns as a JSON object, e.g. {"external": ["files/app.scss"], "framework": ["layout.css","responsive.css"]}.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'layout_create',
        description: <<<'DESC'
            Creates a new tl_layout row inside the given theme.

            Required: theme_id, name. Optional via `fields` (any tl_layout column):
              - type ("default" | "modern")
              - rows ("1rw"|"2rwh"|"2rwf"|"3rw"), cols ("1cl"|"2cll"|"2clr"|"3cl")
              - header_height, footer_height, width_left, width_right (string with unit, e.g. "200px")
              - template (template name, e.g. "fe_page")
              - sections: list<{id, title, position?, template?, cssID?}>
              - modules:  list<{mod: <module-id, or 0 for the article/content placeholder>, col: "main"|"header"|..., enable?: bool}>
                (Contao's article-content placeholder is the pseudo-id 0 — e.g. {mod:0, col:"main"}; without it the column renders empty)
              - framework: list<string> from {layout.css, responsive.css, grid.css, reset.css, form.css, icons.css}
              - external: list<string>  CSS/SCSS/LESS file paths (relative to files/)
              - external_js: list<string> JS file paths
              - jquery / mootools / analytics / scripts: list<string> template names
              - combine_scripts, minify_markup, add_jquery, add_mootools, static (bool)
              - viewport, title_tag, css_class, onload, head, script (text)
              - width, align ("left"|"center"|"right")
              - lightbox_size, default_image_densities
        DESC,
    )]
    public function create(int $theme_id, string $name, #[Schema(type: 'object')] mixed $fields = null): array
    {
        $this->framework->initialize();

        $theme = ThemeModel::findByPk($theme_id);
        if ($theme === null) {
            return ['error' => 'invalid_input', 'message' => sprintf('No theme with id %d', $theme_id)];
        }
        if (trim($name) === '') {
            return ['error' => 'invalid_input', 'message' => 'name is required'];
        }

        try {
            $extras = self::normaliseFields($fields);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }
        $payload = ['name' => $name] + $extras;

        $layout = new LayoutModel();
        $layout->pid = $theme_id;
        $layout->tstamp = time();
        // Pick a sensible default template so the row passes mandatory-validation
        // even when the caller didn't pass one. They can override via `fields`.
        $layout->template = $payload['template'] ?? 'fe_page';

        // tl_layout has several `blob NULL` columns that store serialized
        // arrays. Empty values need DIFFERENT representations per column:
        //
        //   - `sections` → '' (empty string). The backend sectionWizard does an
        //     unguarded `!$this->varValue[0]` on the deserialized value; a:0:{}
        //     deserializes to [] (is_array → true) and the [0] access throws
        //     "Undefined array key 0" → layout edit dies with HTTP 500. '' makes
        //     is_array=false so the guard short-circuits. The frontend readers
        //     of `sections` all guard with `!empty() && is_array()`, so '' (→
        //     null) is safe there too.
        //   - `modules`/`external`/`externalJs` → serialize([]) (= a:0:{}). The
        //     frontend reads these WITHOUT force-array, e.g.
        //     PageRegular::compile() `foreach (StringUtil::deserialize($layout->
        //     modules))` — '' deserializes to null and `foreach (null)` is a
        //     fatal frontend error. a:0:{} deserializes to [] and is safe.
        //
        // `framework` is a VARCHAR with a non-null DB default — left alone.
        // (Caller-provided overrides take effect through $this->mapper->apply.)
        $layout->sections = '';
        foreach (['modules', 'external', 'externalJs'] as $col) {
            $layout->{$col} = serialize([]);
        }

        $result = $this->mapper->apply($layout, $payload);
        if ($result['errors'] !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $result['errors']];
        }
        $layout->save();

        $this->log(sprintf('Created layout "%s" (id=%d, theme=%d)', $layout->name, (int) $layout->id, $theme_id), __METHOD__);

        return ['created' => true, 'id' => (int) $layout->id] + Serializer::full($layout);
    }

    // ──────────────────────────── update ────────────────────────────

    /**
     * @param object|null $fields tl_layout columns to change as a JSON object, e.g. {"external": ["files/app.scss","files/global.less"]}.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'layout_update',
        description: <<<'DESC'
            Updates a tl_layout row. Pass id, then `fields` as a JSON OBJECT (not a list!)
            whose keys are tl_layout column names. Same key set as layout_create:
            name, type, rows, cols, header_height, footer_height, width_left, width_right,
            template, sections, modules, framework, external, external_js, jquery, mootools,
            analytics, scripts, combine_scripts, minify_markup, add_jquery, add_mootools,
            static, viewport, title_tag, css_class, onload, head, script, width, align,
            lightbox_size, default_image_densities.

            Example: {"fields": {"external": ["app.scss", "global.less"]}}.

            Returns the full updated layout.
        DESC,
    )]
    public function update(int $id, #[Schema(type: 'object')] mixed $fields = null): array
    {
        $this->framework->initialize();

        $layout = LayoutModel::findByPk($id);
        if ($layout === null) {
            return ['error' => 'not_found', 'message' => sprintf('No layout with id %d', $id)];
        }

        try {
            $input = self::normaliseFields($fields);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }
        if ($input === []) {
            return ['error' => 'no_fields', 'message' => 'fields must be a non-empty JSON object {column: value}'];
        }

        $before = UpdateDiff::snapshot($layout);

        $result = $this->mapper->apply($layout, $input);
        if ($result['errors'] !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $result['errors']];
        }
        if ($result['applied'] === 0) {
            return [
                'error' => 'no_mappable_fields',
                'message' => 'No mappable fields were applied — every key in `fields` is either unknown or your FieldMapper rejected its value. Compare your keys against layout_get(id).',
                'submitted_keys' => array_keys($input),
            ];
        }

        // Map of public field-name → tl_layout column (where they differ).
        // Other input keys map 1:1 to columns so UpdateDiff::diff defaults to
        // the public name as the column name.
        $publicToColumn = [
            'header_height' => 'headerHeight',
            'footer_height' => 'footerHeight',
            'width_left' => 'widthLeft',
            'width_right' => 'widthRight',
            'lightbox_size' => 'lightboxSize',
            'default_image_densities' => 'defaultImageDensities',
            'title_tag' => 'titleTag',
            'css_class' => 'cssClass',
            'combine_scripts' => 'combineScripts',
            'minify_markup' => 'minifyMarkup',
            'add_jquery' => 'addJQuery',
            'add_mootools' => 'addMooTools',
            'external_js' => 'externalJs',
        ];
        $changedFields = UpdateDiff::diff($layout, $before, $publicToColumn, array_keys($input));
        if ($changedFields === []) {
            return [
                'updated' => false,
                'id' => (int) $layout->id,
                'changed_fields' => [],
                'applied' => 0,
            ] + Serializer::full($layout);
        }

        $versions = $this->bootVersions((int) $layout->id);
        $layout->tstamp = time();
        $layout->save();
        $versions->create();

        $this->log(sprintf('Updated layout "%s" (id=%d, fields=%s)', $layout->name, (int) $layout->id, implode(',', $changedFields)), __METHOD__);

        return [
            'updated' => true,
            'id' => (int) $layout->id,
            'changed_fields' => $changedFields,
            'applied' => \count($changedFields),
        ] + Serializer::full($layout);
    }

    // ──────────────────────────── delete ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'layout_delete',
        description: <<<'DESC'
            Deletes a tl_layout row.

            Safe-by-default: refuses to drop a layout that is still referenced by
            tl_page.layout or tl_page.subpageLayout, unless cascade=true. With
            cascade=true those page references are reset to 0 (the pages fall back
            to inherited layout from their root) before the layout row is removed.

            Requires confirm_destructive=true to proceed.
        DESC,
    )]
    public function delete(int $id, bool $confirm_destructive = false, bool $cascade = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'layout_delete is irreversible. Pass confirm_destructive=true to proceed. Use cascade=true to also drop children.',
            ];
        }

        $layout = LayoutModel::findByPk($id);
        if ($layout === null) {
            return ['error' => 'not_found', 'message' => sprintf('No layout with id %d', $id)];
        }

        $refs = $this->countPageReferences($id);
        $totalRefs = $refs['layout'] + $refs['subpage_layout'];
        if ($totalRefs > 0 && !$cascade) {
            return [
                'error' => 'in_use',
                'message' => sprintf('Layout is referenced by %d page(s) — pass cascade=true to reset those references and delete', $totalRefs),
                'page_references' => $refs,
            ];
        }

        if ($totalRefs > 0) {
            $this->connection->executeStatement(
                'UPDATE tl_page SET layout = 0, tstamp = ? WHERE layout = ?',
                [time(), $id],
            );
            $this->connection->executeStatement(
                'UPDATE tl_page SET subpageLayout = 0, tstamp = ? WHERE subpageLayout = ?',
                [time(), $id],
            );
        }

        $name = (string) $layout->name;
        $themeId = (int) $layout->pid;
        $layout->delete();

        $this->log(sprintf(
            'Deleted layout "%s" (id=%d, theme=%d, reset_page_refs=%d)',
            $name, $id, $themeId, $totalRefs,
        ), __METHOD__);

        return [
            'deleted' => true,
            'id' => $id,
            'reset_page_references' => $totalRefs,
        ];
    }

    // ─────────────────────────── helpers ────────────────────────────

    /**
     * Coerces the MCP-level `fields` parameter into an assoc array. We accept
     * either null, a JSON object (PHP stdClass after json_decode) or an assoc
     * array. JSON lists (numerically-indexed arrays) are rejected because
     * they're almost always a sign the caller meant an object and php-mcp's
     * schema mapping ate the keys.
     *
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
        if (\is_array($fields)) {
            if ($fields !== [] && array_is_list($fields)) {
                throw new \InvalidArgumentException(
                    '`fields` must be a JSON object {column: value, …}, not a list. '
                    .'Example: {"external": ["app.scss"]}.'
                );
            }
            return $fields;
        }

        throw new \InvalidArgumentException('`fields` must be a JSON object {column: value, …}.');
    }

    /**
     * @return array{layout: int, subpage_layout: int}
     */
    private function countPageReferences(int $layoutId): array
    {
        return [
            'layout' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_page WHERE layout = ?', [$layoutId]),
            'subpage_layout' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_page WHERE subpageLayout = ?', [$layoutId]),
        ];
    }

    private function bootVersions(int $id): Versions
    {
        $v = new Versions('tl_layout', $id);
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
