<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\ImageSize;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\ImageSizeItemModel;
use Contao\ImageSizeModel;
use Contao\ThemeModel;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\DbalRetry;
use Netzhirsch\ContaoMcpBundle\Service\QueryFilterResolver;
use Netzhirsch\ContaoMcpBundle\Service\SubmittedKeys;
use Netzhirsch\ContaoMcpBundle\Service\UpdateDiff;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;

/**
 * MCP facade for tl_image_size + tl_image_size_item.
 *
 * Eleven tools split across two entities:
 *   - image_sizes_list, image_size_get, image_size_create, image_size_update, image_size_delete
 *   - image_size_items_list, image_size_item_get, image_size_item_create,
 *     image_size_item_update, image_size_item_delete
 *   - image_size_options_list (helper for "what's available as field-value?")
 *
 * Item rows hang off tl_image_size via pid. Delete-of-size is safe-by-default —
 * refuses if items exist, unless force=true (cascades).
 *
 * Note: tl_content / tl_layout reference image sizes by an opaque identifier
 * ("size") that can be either the numeric id of a tl_image_size row or a
 * built-in alias ("image_thumbnail", …). We don't try to find/prune those
 * references on delete because the substring match would yield too many
 * false positives.
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
        private readonly McpPermissionGuard $guard,
    ) {
    }

    // ─────────────────────── image_sizes_list ───────────────────────

    /**
     * @param object|null $filters DCA-validated filter map. See entity_query_options("tl_image_size").
     *
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'image_sizes_list',
        description: 'Lists tl_image_size rows. Optional theme_id filter. q does LIKE-search across DCA-searchable fields; filters is a DCA-validated equality map; updated_after/before take Unix-ts or ISO-8601.',
    )]
    public function listSizes(
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
            $columns[] = 'tl_image_size.pid = ?';
            $values[] = $theme_id;
        }

        $search = $this->filterResolver->buildSearchClause('tl_image_size', $q);
        if ($search !== null) {
            $columns[] = $search['clause'];
            $values = array_merge($values, $search['params']);
        }
        try {
            $filtersArr = $this->filterResolver->normaliseFilters($filters);
            $fr = $this->filterResolver->buildFilterClauses('tl_image_size', $filtersArr);
            $columns = array_merge($columns, $fr['clauses']);
            $values = array_merge($values, $fr['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_filter', 'message' => $e->getMessage()];
        }
        try {
            $ts = $this->filterResolver->buildTstampRange('tl_image_size', $updated_after, $updated_before);
            $columns = array_merge($columns, $ts['clauses']);
            $values = array_merge($values, $ts['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $items = ImageSizeModel::findBy(
            $columns === [] ? null : $columns,
            $values === [] ? null : $values,
            ['order' => 'tl_image_size.pid, tl_image_size.name', 'limit' => $limit, 'offset' => $offset],
        );

        $out = [];
        foreach ($items ?? [] as $s) {
            if (!$this->guard->mayRead('tl_image_size', $s->row())) {
                continue;
            }
            $out[] = Serializer::summary($s);
        }

        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    // ──────────────────────── image_size_get ────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'image_size_get',
        description: 'Returns a single tl_image_size row + count of its tl_image_size_item children (media-query overrides).',
    )]
    public function getSize(int $id): array
    {
        $this->framework->initialize();

        $size = ImageSizeModel::findByPk($id);
        if ($size === null) {
            return ['error' => 'not_found', 'message' => sprintf('No image_size with id %d', $id)];
        }

        return Serializer::full($size) + [
            'item_count' => $this->countItems($id),
        ];
    }

    // ─────────────────────── image_size_create ──────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'image_size_create',
        description: <<<'DESC'
            Creates a new tl_image_size row.

            Required: theme_id, name. Optional via `fields`:
              width, height (int, pixels)
              resize_mode: "proportional" | "box" | "crop"
              zoom: 0-100 (%)
              image_quality: 0-100 (%)
              css_class, densities, sizes (text)
              formats: list<string> like ["jpg:webp,jpg", "png:webp,png"]
              preserve_metadata: "default" | "overwrite" | "delete"
              skip_if_dimensions_match, lazy_loading (bool)
        DESC,
    )]
    /**
     * @param object|null $fields Optional tl_image_size columns as a JSON object: width, height (int), resize_mode ("proportional"|"box"|"crop"), zoom (0-100), image_quality (0-100), css_class, densities, sizes, formats (list), preserve_metadata, skip_if_dimensions_match, lazy_loading.
     */
    public function createSize(int $theme_id, string $name, #[Schema(type: 'object')] mixed $fields = null): array
    {
        $this->framework->initialize();

        if (ThemeModel::findByPk($theme_id) === null) {
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

        // `name` is merged into the payload below, so `applied` can never hit
        // zero and would hide unknown extras. Ask the mapper itself what it can
        // place — using the mapper as the source of truth rather than a
        // hand-maintained key list that would drift out of sync with it. Errors
        // mean the key WAS known and its value rejected; that belongs to the
        // real apply below, not here.
        if ($extras !== []) {
            $probe = $this->mapper->applyToSize(new ImageSizeModel(), $extras);
            if ($probe['applied'] === 0 && $probe['errors'] === []) {
                return [
                    'error' => 'no_mappable_fields',
                    'message' => 'No mappable fields were applied — every submitted key is unknown for tl_image_size. Check image_size_get(id) for valid keys.',
                    'submitted_keys' => array_keys($extras),
                ];
            }
        }

        $size = new ImageSizeModel();
        $size->pid = $theme_id;
        $size->tstamp = time();
        $size->name = $name;
        $size->preserveMetadata = 'default';

        $result = $this->mapper->applyToSize($size, ['name' => $name] + $extras);
        $ignored = SubmittedKeys::ignored($extras, $result['applied_keys']);
        if ($result['errors'] !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $result['errors']];
        }
        $size->save();

        $this->log(sprintf('Created image_size "%s" (id=%d, theme=%d)', $size->name, (int) $size->id, $theme_id), __METHOD__);

        return ['created' => true, 'id' => (int) $size->id] + SubmittedKeys::report($ignored) + Serializer::full($size);
    }

    // ─────────────────────── image_size_update ──────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'image_size_update',
        description: 'Updates a tl_image_size row. Pass id, then `fields` as a JSON OBJECT (not a list!): {"fields": {"width": 800, "height": 600}}.',
    )]
    /**
     * @param object|null $fields tl_image_size columns to change as a JSON object. Example: {"width": 800, "height": 600, "resize_mode": "crop"}.
     */
    public function updateSize(int $id, #[Schema(type: 'object')] mixed $fields = null): array
    {
        $this->framework->initialize();

        $size = ImageSizeModel::findByPk($id);
        if ($size === null) {
            return ['error' => 'not_found', 'message' => sprintf('No image_size with id %d', $id)];
        }

        try {
            $input = self::normaliseFields($fields);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }
        if ($input === []) {
            return ['error' => 'no_fields', 'message' => 'fields must be a non-empty JSON object {column: value}'];
        }

        $before = UpdateDiff::snapshot($size);

        $result = $this->mapper->applyToSize($size, $input);
        if ($result['errors'] !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $result['errors']];
        }
        if ($result['applied'] === 0) {
            return [
                'error' => 'no_mappable_fields',
                'message' => 'No mappable fields were applied — every submitted key is unknown for tl_image_size. Check image_size_get(id) for valid keys.',
                'submitted_keys' => array_keys($input),
            ];
        }

        $publicToColumn = [
            'resize_mode' => 'resizeMode',
            'css_class' => 'cssClass',
            'image_quality' => 'imageQuality',
            'lazy_loading' => 'lazyLoading',
            'preserve_metadata' => 'preserveMetadata',
            'skip_if_dimensions_match' => 'skipIfDimensionsMatch',
        ];
        $changedFields = UpdateDiff::diff($size, $before, $publicToColumn, array_keys($input));
        if ($changedFields === []) {
            return [
                'updated' => false,
                'id' => $id,
                'changed_fields' => [],
                'applied' => 0,
            ] + Serializer::full($size);
        }

        $versions = $this->bootSizeVersions($id);
        $size->tstamp = time();
        $size->save();
        $versions->create();

        $this->log(sprintf('Updated image_size "%s" (id=%d, fields=%s)', $size->name, $id, implode(',', $changedFields)), __METHOD__);

        return [
            'updated' => true,
            'id' => $id,
            'changed_fields' => $changedFields,
            'applied' => \count($changedFields),
        ] + Serializer::full($size);
    }

    // ─────────────────────── image_size_delete ──────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'image_size_delete',
        description: 'Deletes a tl_image_size row. Safe-by-default: refuses if it has tl_image_size_item children, unless cascade=true (cascades). Requires confirm_destructive=true to proceed.',
    )]
    public function deleteSize(int $id, bool $confirm_destructive = false, bool $cascade = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'image_size_delete is irreversible. Pass confirm_destructive=true to proceed. Use cascade=true to also drop children.',
            ];
        }

        $size = ImageSizeModel::findByPk($id);
        if ($size === null) {
            return ['error' => 'not_found', 'message' => sprintf('No image_size with id %d', $id)];
        }

        $itemCount = $this->countItems($id);
        if ($itemCount > 0 && !$cascade) {
            return [
                'error' => 'has_children',
                'message' => sprintf('image_size has %d item(s) — pass cascade=true to cascade-delete', $itemCount),
                'item_count' => $itemCount,
            ];
        }

        $name = (string) $size->name;
        // Wrap item-children DELETE + the parent size delete in one transaction
        // so a mid-cascade failure can't leave tl_image_size_item rows orphaned
        // (parent gone, children still pointing at the dead pid) or vice versa.
        $this->dbalRetry->transactional($this->connection, function () use ($id, $itemCount, $size): void {
            if ($itemCount > 0) {
                $this->connection->executeStatement('DELETE FROM tl_image_size_item WHERE pid = ?', [$id]);
            }
            $size->delete();
        });

        $this->log(sprintf('Deleted image_size "%s" (id=%d, cascaded_items=%d)', $name, $id, $itemCount), __METHOD__);

        return ['deleted' => true, 'id' => $id, 'cascaded_items' => $itemCount];
    }

    // ──────────────────── image_size_items_list ─────────────────────

    /**
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}
     */
    #[McpTool(
        name: 'image_size_items_list',
        description: 'Lists tl_image_size_item rows belonging to one image_size_id, sorted by `sorting`.',
    )]
    public function listItems(int $image_size_id, int $limit = 100, int $offset = 0): array
    {
        $this->framework->initialize();

        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);

        $items = ImageSizeItemModel::findBy(
            ['tl_image_size_item.pid = ?'],
            [$image_size_id],
            ['order' => 'tl_image_size_item.sorting', 'limit' => $limit, 'offset' => $offset],
        );

        $out = [];
        foreach ($items ?? [] as $i) {
            if (!$this->guard->mayRead('tl_image_size_item', $i->row())) {
                continue;
            }
            $out[] = Serializer::itemSummary($i);
        }

        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    // ───────────────────── image_size_item_get ──────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'image_size_item_get',
        description: 'Returns a single tl_image_size_item by id (full payload).',
    )]
    public function getItem(int $id): array
    {
        $this->framework->initialize();

        $item = ImageSizeItemModel::findByPk($id);
        if ($item === null) {
            return ['error' => 'not_found', 'message' => sprintf('No image_size_item with id %d', $id)];
        }

        return Serializer::itemFull($item);
    }

    // ──────────────────── image_size_item_create ────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'image_size_item_create',
        description: <<<'DESC'
            Creates a new tl_image_size_item under an image_size_id. Items hold
            media-query-specific overrides (e.g. different crop for mobile).

            Required: image_size_id. Optional via `fields`:
              media: CSS media-query string, e.g. "(max-width: 600px)"
              width, height (int, pixels)
              resize_mode: "proportional" | "box" | "crop"
              zoom: 0-100 (%)
              densities, sizes (text)
              active (bool, default true)
              sorting (int, default = max+1)
        DESC,
    )]
    /**
     * @param object|null $fields Optional tl_image_size_item columns as a JSON object: media (CSS media query), width, height (int), resize_mode, zoom, densities, sizes, active (bool), sorting (int).
     */
    public function createItem(int $image_size_id, #[Schema(type: 'object')] mixed $fields = null): array
    {
        $this->framework->initialize();

        if (ImageSizeModel::findByPk($image_size_id) === null) {
            return ['error' => 'invalid_input', 'message' => sprintf('No image_size with id %d', $image_size_id)];
        }

        try {
            $payload = self::normaliseFields($fields);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }
        // Default sorting = highest existing + 128 (Contao's drag-sort convention).
        if (!\array_key_exists('sorting', $payload)) {
            $max = (int) $this->connection->fetchOne(
                'SELECT MAX(sorting) FROM tl_image_size_item WHERE pid = ?',
                [$image_size_id],
            );
            $payload['sorting'] = $max + 128;
        }

        $item = new ImageSizeItemModel();
        $item->pid = $image_size_id;
        $item->tstamp = time();
        $item->invisible = 0;

        $result = $this->mapper->applyToItem($item, $payload);
        if ($result['errors'] !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $result['errors']];
        }
        $item->save();

        $this->log(sprintf('Created image_size_item (id=%d, image_size=%d, media=%s)', (int) $item->id, $image_size_id, $item->media), __METHOD__);

        return ['created' => true, 'id' => (int) $item->id] + Serializer::itemFull($item);
    }

    // ──────────────────── image_size_item_update ────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'image_size_item_update',
        description: 'Updates a tl_image_size_item row. Pass id, then `fields` as a JSON OBJECT (not a list!).',
    )]
    /**
     * @param object|null $fields tl_image_size_item columns to change as a JSON object.
     */
    public function updateItem(int $id, #[Schema(type: 'object')] mixed $fields = null): array
    {
        $this->framework->initialize();

        $item = ImageSizeItemModel::findByPk($id);
        if ($item === null) {
            return ['error' => 'not_found', 'message' => sprintf('No image_size_item with id %d', $id)];
        }

        try {
            $input = self::normaliseFields($fields);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }
        if ($input === []) {
            return ['error' => 'no_fields', 'message' => 'fields must be a non-empty JSON object {column: value}'];
        }

        $before = UpdateDiff::snapshot($item);

        $result = $this->mapper->applyToItem($item, $input);
        if ($result['errors'] !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $result['errors']];
        }
        if ($result['applied'] === 0) {
            return [
                'error' => 'no_mappable_fields',
                'message' => 'No mappable fields were applied — every submitted key is unknown for tl_image_size_item.',
                'submitted_keys' => array_keys($input),
            ];
        }

        // `active` inverts onto `invisible` (DCA boolean-inversion).
        // `resize_mode` → `resizeMode`. Other keys map 1:1.
        $publicToColumn = [
            'active' => 'invisible',
            'resize_mode' => 'resizeMode',
        ];
        $changedFields = UpdateDiff::diff($item, $before, $publicToColumn, array_keys($input));
        if ($changedFields === []) {
            return [
                'updated' => false,
                'id' => $id,
                'changed_fields' => [],
                'applied' => 0,
            ] + Serializer::itemFull($item);
        }

        $versions = $this->bootItemVersions($id);
        $item->tstamp = time();
        $item->save();
        $versions->create();

        $this->log(sprintf('Updated image_size_item (id=%d, fields=%s)', $id, implode(',', $changedFields)), __METHOD__);

        return [
            'updated' => true,
            'id' => $id,
            'changed_fields' => $changedFields,
            'applied' => \count($changedFields),
        ] + Serializer::itemFull($item);
    }

    // ──────────────────── image_size_item_delete ────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'image_size_item_delete',
        description: 'Deletes a tl_image_size_item row. Requires confirm_destructive=true to proceed.',
    )]
    public function deleteItem(int $id, bool $confirm_destructive = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'image_size_item_delete is irreversible. Pass confirm_destructive=true to proceed.',
            ];
        }

        $item = ImageSizeItemModel::findByPk($id);
        if ($item === null) {
            return ['error' => 'not_found', 'message' => sprintf('No image_size_item with id %d', $id)];
        }

        $this->bootItemVersions($id);
        $pid = (int) $item->pid;
        $media = (string) $item->media;
        $item->delete();

        $this->log(sprintf('Deleted image_size_item (id=%d, image_size=%d, media=%s)', $id, $pid, $media), __METHOD__);

        return ['deleted' => true, 'id' => $id];
    }

    // ──────────────────── image_size_options_list ───────────────────

    /**
     * @return array{builtin: list<string>, custom: list<array<string, mixed>>, total: int}
     */
    #[McpTool(
        name: 'image_size_options_list',
        description: 'Returns the full set of image-size identifiers Contao currently knows: built-in aliases (image_thumbnail, …) + every custom tl_image_size row. Use the resulting strings as `size` for content elements and layouts.',
    )]
    public function options(): array
    {
        $this->framework->initialize();

        // Built-in aliases declared by Contao core + bundles via $GLOBALS['TL_DCA']['tl_image_size'].
        // We hardcode the common ones because pulling them from the picreator is expensive.
        $builtin = ['', 'crop', 'proportional', 'box'];

        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.name, s.pid AS theme_id, t.name AS theme_name
             FROM tl_image_size s LEFT JOIN tl_theme t ON t.id = s.pid
             ORDER BY t.name, s.name',
        );
        $custom = [];
        foreach ($rows as $row) {
            $custom[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'theme_id' => (int) $row['theme_id'],
                'theme_name' => (string) $row['theme_name'],
            ];
        }

        return [
            'builtin' => $builtin,
            'custom' => $custom,
            'total' => \count($builtin) + \count($custom),
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
                    .'Example: {"width": 800, "height": 600}.'
                );
            }
            return $fields;
        }

        throw new \InvalidArgumentException('`fields` must be a JSON object {column: value, …}.');
    }

    private function countItems(int $sizeId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_image_size_item WHERE pid = ?',
            [$sizeId],
        );
    }

    private function bootSizeVersions(int $id): Versions
    {
        $v = new Versions('tl_image_size', $id);
        $v->setUsername($this->authorResolver->getLogUsername());
        $v->setUserId($this->authorResolver->resolve());
        $v->initialize();

        return $v;
    }

    private function bootItemVersions(int $id): Versions
    {
        $v = new Versions('tl_image_size_item', $id);
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
