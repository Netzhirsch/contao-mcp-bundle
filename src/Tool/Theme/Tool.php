<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Theme;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\ImageSizeModel;
use Contao\LayoutModel;
use Contao\ModuleModel;
use Contao\ThemeModel;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\DbalRetry;
use Netzhirsch\ContaoMcpBundle\Service\ProviderFields;
use Netzhirsch\ContaoMcpBundle\Service\QueryFilterResolver;
use Netzhirsch\ContaoMcpBundle\Service\UpdateDiff;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;

/**
 * MCP facade for tl_theme. Five CRUD tools:
 *   themes_list, theme_get, theme_create, theme_update, theme_delete.
 *
 * Themes are the root container of the front-end design world: layouts,
 * front-end modules, image-sizes and (legacy) theme-scoped content elements
 * all hang off a theme via pid. Delete is safe-by-default — refuses to drop
 * a theme that still has any of these children unless force=true, in which
 * case it cascade-deletes everything.
 */
final class Tool
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly DbalRetry $dbalRetry,
        private readonly FieldMapper $mapper,
        private readonly QueryFilterResolver $filterResolver,
        private readonly ProviderFields $providerFields,
    ) {
    }

    // ───────────────────────────── list ─────────────────────────────

    /**
     * @param object|null $filters DCA-validated filter map. See entity_query_options("tl_theme").
     *
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'themes_list',
        description: 'Lists all Contao themes (tl_theme), alphabetically. Use the returned id as pid for layouts, modules and image-sizes. q does LIKE-search across DCA-searchable fields; filters is a DCA-validated equality map; updated_after/before take Unix-ts or ISO-8601.',
    )]
    public function list(
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

        $search = $this->filterResolver->buildSearchClause('tl_theme', $q);
        if ($search !== null) {
            $columns[] = $search['clause'];
            $values = array_merge($values, $search['params']);
        }
        try {
            $filtersArr = $this->filterResolver->normaliseFilters($filters);
            $fr = $this->filterResolver->buildFilterClauses('tl_theme', $filtersArr);
            $columns = array_merge($columns, $fr['clauses']);
            $values = array_merge($values, $fr['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_filter', 'message' => $e->getMessage()];
        }
        try {
            $ts = $this->filterResolver->buildTstampRange('tl_theme', $updated_after, $updated_before);
            $columns = array_merge($columns, $ts['clauses']);
            $values = array_merge($values, $ts['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $items = ThemeModel::findBy(
            $columns === [] ? null : $columns,
            $values === [] ? null : $values,
            ['order' => 'tl_theme.name', 'limit' => $limit, 'offset' => $offset],
        );

        $out = [];
        foreach ($items ?? [] as $theme) {
            $out[] = Serializer::summary($theme);
        }

        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    // ───────────────────────────── get ──────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'theme_get',
        description: 'Returns a single theme by id including counts of its layouts, front-end modules and image-sizes — handy before delete to see what would cascade. Also returns the columns installed extensions contribute for tl_theme, so a theme\'s full configuration is inspectable.',
    )]
    public function get(int $id): array
    {
        $this->framework->initialize();

        $theme = ThemeModel::findByPk($id);
        if ($theme === null) {
            return ['error' => 'not_found', 'message' => sprintf('No theme with id %d', $id)];
        }

        return $this->full($theme) + [
            'layout_count' => $this->countChildren('tl_layout', $id),
            'module_count' => $this->countChildren('tl_module', $id),
            'image_size_count' => $this->countChildren('tl_image_size', $id),
        ];
    }

    // ──────────────────────────── create ────────────────────────────

    /**
     * @param array<string, mixed>|null $fields
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'theme_create',
        description: <<<'DESC'
            Creates a new tl_theme row.

            Required: name, author.
            Optional via `fields`: templates (template-folder path), folders (list of
            files/-relative paths the theme owns), screenshot (path to a file), plus any
            columns installed extensions contribute (e.g. the Bootstrap bundle's compile
            mode and SCSS overrides).

            Unknown keys are NOT silently dropped: all-unknown fails the call, a partial
            mismatch returns `ignored_keys` alongside the created theme.

            Returns the new id + summary.
        DESC,
    )]
    /**
     * @param object|null $fields Optional tl_theme columns as a JSON object: templates (folder path), folders (list of file paths), screenshot (path).
     */
    public function create(string $name, string $author, #[Schema(type: 'object')] mixed $fields = null): array
    {
        $this->framework->initialize();

        if (trim($name) === '' || trim($author) === '') {
            return ['error' => 'invalid_input', 'message' => 'name and author are required'];
        }

        try {
            $extras = self::normaliseFields($fields);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }
        // Same key check update has always done. Without it create answered
        // "created: true" for fields it never wrote, because name and author
        // alone already made the applied counter non-zero.
        [$ignored, $keyError] = $this->unknownKeys($extras);
        if ($keyError !== null) {
            return $keyError;
        }

        $payload = ['name' => $name, 'author' => $author] + $extras;

        $theme = new ThemeModel();
        $theme->tstamp = time();
        $result = $this->mapper->apply($theme, $payload);
        if ($result['errors'] !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $result['errors']];
        }
        $theme->save();

        $this->log(sprintf('Created theme "%s" (id=%d)', $theme->name, (int) $theme->id), __METHOD__);

        $result = ['created' => true, 'id' => (int) $theme->id] + $this->full($theme);
        if ($ignored !== []) {
            $result['ignored_keys'] = $ignored;
        }

        return $result;
    }

    // ──────────────────────────── update ────────────────────────────

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'theme_update',
        description: <<<'DESC'
            Updates a tl_theme row. Pass id, then `fields` as a JSON OBJECT (not a list!).
            Core keys: name, author, templates, folders (list of file paths), screenshot
            (single file path).

            Installed extensions can contribute further columns (for example the Bootstrap
            bundle's compile mode and SCSS overrides). They are writable here and readable
            through theme_get; an unknown key is reported back rather than dropped, and a
            key whose extension is missing says so by name.

            Example: {"fields": {"author": "Jan"}}.
        DESC,
    )]
    /**
     * @param object|null $fields tl_theme columns to change as a JSON object: name, author, templates, folders, screenshot.
     */
    public function update(int $id, #[Schema(type: 'object')] mixed $fields = null): array
    {
        $this->framework->initialize();

        $theme = ThemeModel::findByPk($id);
        if ($theme === null) {
            return ['error' => 'not_found', 'message' => sprintf('No theme with id %d', $id)];
        }

        try {
            $input = self::normaliseFields($fields);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }
        if ($input === []) {
            return ['error' => 'no_fields', 'message' => 'fields must be a non-empty JSON object {column: value}'];
        }

        [$ignored, $keyError] = $this->unknownKeys($input);
        if ($keyError !== null) {
            return $keyError;
        }

        // Snapshot the row BEFORE applyFields so we can diff for a real
        // change-detect — the FieldMapper's `applied` counter only tells us
        // how many input keys mapped to a column, not whether any value
        // actually differs from what's stored.
        $before = UpdateDiff::snapshot($theme);

        $result = $this->mapper->apply($theme, $input);
        if ($result['errors'] !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $result['errors']];
        }

        $changedFields = UpdateDiff::diff($theme, $before, [], array_keys($input));
        if ($changedFields === []) {
            // True no-op: every submitted value matched what's already stored.
            // Skip save + Versions snapshot, return idempotent success.
            $result = [
                'updated' => false,
                'id' => (int) $theme->id,
                'changed_fields' => [],
                'applied' => 0,
            ] + $this->full($theme);
            if ($ignored !== []) {
                $result['ignored_keys'] = $ignored;
            }

            return $result;
        }

        $versions = $this->bootVersions((int) $theme->id);
        $theme->tstamp = time();
        $theme->save();
        $versions->create();

        $this->log(sprintf('Updated theme "%s" (id=%d, fields=%s)', $theme->name, (int) $theme->id, implode(',', $changedFields)), __METHOD__);

        $result = [
            'updated' => true,
            'id' => (int) $theme->id,
            'changed_fields' => $changedFields,
            'applied' => \count($changedFields),
        ] + $this->full($theme);
        if ($ignored !== []) {
            $result['ignored_keys'] = $ignored;
        }

        return $result;
    }

    // ──────────────────────────── delete ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'theme_delete',
        description: <<<'DESC'
            Deletes a tl_theme row.

            Safe-by-default: refuses to drop a theme that has any child rows
            (layouts, front-end modules, image-sizes) unless cascade=true. With
            cascade=true the delete cascades into:
              - tl_module (front-end modules of this theme)
              - tl_layout (page layouts)
              - tl_image_size + tl_image_size_item
              - tl_content rows that point at the theme via ptable=tl_theme (legacy)

            Requires confirm_destructive=true to proceed.
        DESC,
    )]
    public function delete(int $id, bool $confirm_destructive = false, bool $cascade = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'theme_delete is irreversible. Pass confirm_destructive=true to proceed. Use cascade=true to also drop children.',
            ];
        }

        $theme = ThemeModel::findByPk($id);
        if ($theme === null) {
            return ['error' => 'not_found', 'message' => sprintf('No theme with id %d', $id)];
        }

        $layoutCount = $this->countChildren('tl_layout', $id);
        $moduleCount = $this->countChildren('tl_module', $id);
        $imageSizeCount = $this->countChildren('tl_image_size', $id);

        $hasChildren = ($layoutCount + $moduleCount + $imageSizeCount) > 0;
        if ($hasChildren && !$cascade) {
            return [
                'error' => 'has_children',
                'message' => 'Theme has dependent rows — pass cascade=true to cascade-delete',
                'layout_count' => $layoutCount,
                'module_count' => $moduleCount,
                'image_size_count' => $imageSizeCount,
            ];
        }

        // Cascade order: items first, parents last. We touch tl_image_size_item
        // by pid → tl_image_size.id (so we need their ids before deleting tl_image_size).
        // The whole cascade runs inside a single DBAL transaction so a mid-
        // cascade failure (deadlock, dropped connection, constraint conflict)
        // doesn't leave the theme half-gutted (e.g. layouts gone but modules
        // still referencing the deleted theme). Without the wrapper, every
        // sub-DELETE auto-commits independently and a crash between two of
        // them is silently destructive.
        $name = (string) $theme->name;
        $this->dbalRetry->transactional($this->connection, function () use ($id, $theme) {
            $imageSizeIds = $this->connection->fetchFirstColumn(
                'SELECT id FROM tl_image_size WHERE pid = ?',
                [$id],
            );
            if ($imageSizeIds !== []) {
                $placeholders = implode(',', array_fill(0, \count($imageSizeIds), '?'));
                $this->connection->executeStatement(
                    "DELETE FROM tl_image_size_item WHERE pid IN ($placeholders)",
                    $imageSizeIds,
                );
            }
            $this->connection->executeStatement('DELETE FROM tl_image_size WHERE pid = ?', [$id]);
            $this->connection->executeStatement('DELETE FROM tl_module WHERE pid = ?', [$id]);
            $this->connection->executeStatement('DELETE FROM tl_layout WHERE pid = ?', [$id]);
            // Legacy theme-scoped content elements (Contao 4.x).
            $this->connection->executeStatement(
                'DELETE FROM tl_content WHERE ptable = ? AND pid = ?',
                ['tl_theme', $id],
            );
            $theme->delete();
        });

        $this->log(sprintf(
            'Deleted theme "%s" (id=%d, cascaded: layouts=%d, modules=%d, image_sizes=%d)',
            $name, $id, $layoutCount, $moduleCount, $imageSizeCount,
        ), __METHOD__);

        return [
            'deleted' => true,
            'id' => $id,
            'cascaded' => [
                'layouts' => $layoutCount,
                'modules' => $moduleCount,
                'image_sizes' => $imageSizeCount,
            ],
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
        if (\is_array($fields)) {
            if ($fields !== [] && array_is_list($fields)) {
                throw new \InvalidArgumentException(
                    '`fields` must be a JSON object {column: value, …}, not a list. '
                    .'Example: {"author": "Jan"}.'
                );
            }
            return $fields;
        }

        throw new \InvalidArgumentException('`fields` must be a JSON object {column: value, …}.');
    }

    private function countChildren(string $table, int $themeId): int
    {
        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE pid = ?', $table),
            [$themeId],
        );
    }

    private function bootVersions(int $id): Versions
    {
        $v = new Versions('tl_theme', $id);
        $v->setUsername($this->authorResolver->getLogUsername());
        $v->setUserId($this->authorResolver->resolve());
        $v->initialize();

        return $v;
    }

    private function log(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::GENERAL, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }

    /**
     * Serializer output plus whatever extensions contribute for tl_theme.
     * Without this, a field that can be written cannot be read back — and a
     * caller has no way to check what a theme is actually configured to do.
     *
     * @return array<string, mixed>
     */
    private function full(ThemeModel $theme): array
    {
        return Serializer::full($theme) + $this->providerFields->serialize('tl_theme', $theme);
    }

    /**
     * Reports submitted keys this mapper cannot place.
     *
     * Silently dropping them is the worst outcome for an agent: it reads
     * "created: true", believes the theme is configured, and builds on top of
     * a row that never received the values. All-unknown is an outright error;
     * a partial mismatch still applies the rest and names what it skipped.
     *
     * @param array<string, mixed> $input
     *
     * @return array{0: list<string>, 1: ?array<string, mixed>} [ignored, error]
     */
    private function unknownKeys(array $input): array
    {
        $allowed = $this->mapper->allowedFields();
        $ignored = array_values(array_diff(array_keys($input), $allowed));

        if ($ignored === [] || $ignored !== array_keys($input)) {
            return [$ignored, null];
        }

        return [$ignored, [
            'error' => 'no_mappable_fields',
            'message' => sprintf(
                'No mappable fields were applied — every submitted key is unknown for tl_theme. Allowed keys: %s.',
                implode(', ', $allowed),
            ),
            'submitted_keys' => array_keys($input),
        ]];
    }
}
