<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Content;

use Contao\ArticleModel;
use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\NewsModel;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;

/**
 * MCP facade for tl_content. Five CRUD tools plus two discovery tools:
 *   - content_list, content_get, content_create, content_update, content_delete
 *   - content_types_list (what types can I create?)
 *   - content_palette_get (what fields does this type expect?)
 *
 * Content elements are parent-keyed via (ptable, pid). Common ptables: 'tl_article'
 * (the typical case), 'tl_news', 'tl_calendar_events', 'tl_faq'. Use article_get /
 * news_get to see existing content streams; use this facade to write to them.
 *
 * Because tl_content has ~100 fields and a new content-element bundle can extend it,
 * write tools accept the four required top-level fields explicitly and route every
 * type-specific field through a `fields` object. The mapper resolves the allowed
 * keys at runtime from the live DCA palette of the chosen type.
 */
final class Tool
{
    /**
     * Elements per content_create_tree call. A page-sized block is a dozen or
     * two; beyond this a failure stops being attributable to a node.
     */
    private const MAX_TREE_ELEMENTS = 200;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly FieldMapper $mapper,
        private readonly Serializer $serializer,
        private readonly Connection $connection,
        private readonly McpPermissionGuard $guard,
    ) {
    }

    private function parentExistsViaDbal(string $table, int $pid): bool
    {
        // Hard-coded table list (allowlist) — never interpolate caller-supplied
        // table names. quoteIdentifier here is belt-and-braces.
        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE id = ?', $this->connection->quoteIdentifier($table)),
            [$pid],
        ) > 0;
    }

    // ───────────────────────────── list ─────────────────────────────

    /**
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}
     */
    #[McpTool(
        name: 'content_list',
        description: <<<'DESC'
            Lists tl_content rows belonging to a single parent. Exactly one of article_id,
            news_id, page_id (legacy fallback ptable=tl_page), or a custom (ptable, pid) pair
            must be supplied. Returns lookup-sized rows (id, type, sorting, headline_text,
            invisible). Use content_get for the full record.
        DESC,
    )]
    public function list(
        ?int $article_id = null,
        ?int $news_id = null,
        ?int $page_id = null,
        ?string $ptable = null,
        ?int $pid = null,
        bool $include_invisible = true,
        int $limit = 100,
        int $offset = 0,
    ): array {
        $this->framework->initialize();

        [$resolvedPtable, $resolvedPid, $err] = self::resolveParent($article_id, $news_id, $page_id, $ptable, $pid);
        if ($err !== null) {
            return $err;
        }

        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);

        $columns = ['tl_content.pid = ?', 'tl_content.ptable = ?'];
        $values = [$resolvedPid, $resolvedPtable];

        if (!$include_invisible) {
            $time = time();
            $columns[] = "tl_content.invisible = ''";
            $columns[] = "(tl_content.start = '' OR tl_content.start <= ?)";
            $values[] = (string) $time;
            $columns[] = "(tl_content.stop = '' OR tl_content.stop > ?)";
            $values[] = (string) $time;
        }

        $items = ContentModel::findBy(
            $columns,
            $values,
            ['order' => 'tl_content.sorting', 'limit' => $limit, 'offset' => $offset],
        );

        $out = [];
        foreach ($items ?? [] as $el) {
            if (!$this->guard->mayRead('tl_content', $el->row())) {
                continue;
            }
            $headline = $this->headlineTuple($el->headline);
            $out[] = [
                'id' => (int) $el->id,
                'pid' => (int) $el->pid,
                'ptable' => (string) $el->ptable,
                'type' => (string) $el->type,
                'sorting' => (int) $el->sorting,
                'headline_text' => $headline['value'] ?? null,
                'invisible' => (bool) $el->invisible,
            ];
        }

        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    // ───────────────────────────── get ──────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'content_get',
        description: 'Returns a single tl_content row by id with every column. Binary UUID columns return as hex strings; serialised blobs (groups, multiSRC, headline) are decoded to JSON-friendly shapes.',
    )]
    public function get(int $id): array
    {
        $this->framework->initialize();

        $content = ContentModel::findById($id);
        if ($content === null) {
            return ['error' => 'not_found', 'message' => "Content element $id not found."];
        }

        return $this->serializer->summary($content);
    }

    // ──────────────────────────── create ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'content_create',
        description: <<<'DESC'
            Creates a new content element. Required: ptable (parent table, typically
            "tl_article" or "tl_news"), pid (id of the parent row), type (one of the values
            content_types_list returns), sorting (Contao 128-step convention — use
            content_list to find sibling sortings).

            Nested elements (Contao 5): to put a child INTO a container CE
            (accordion / swiper / element_group), pass ptable="tl_content" and
            pid=<container element id>. Cycles (nesting an element under itself
            or a descendant) are rejected.

            All type-specific values go in `fields` as a JSON object whose keys must appear
            in content_palette_get(type). Common conventions:
              - headline:   either a plain string (defaults to <h2>) or {value, unit:"h1..h6"}
              - text/html:  HTML/plaintext as a string
              - singleSRC:  32-char hex UUID of a tl_files row (see filesystem APIs)
              - multiSRC / orderSRC: list of hex UUIDs
              - groups:     list of tl_member_group.id (when protected=true)
              - cssID:  object {id, class} (or a plain string for the raw value)
              - space:  object {top, bottom}
              - invisible / start / stop: the standard publish gate
              - customTpl:  template name from templates_list("ce_")

            Wraps in a Versions snapshot and writes to tl_log.
        DESC,
    )]
    /**
     * @param object|null $fields Type-specific tl_content columns as a JSON object. Use content_palette_get(type) to discover allowed keys. Example: {"text": "<p>Hello</p>", "headline": {"value": "Title", "unit": "h2"}}.
     */
    public function create(
        string $ptable,
        int $pid,
        string $type,
        int $sorting,
        #[Schema(type: 'object')] mixed $fields = null,
    ): array {
        $this->framework->initialize();

        if (($err = $this->validateParent($ptable, $pid)) !== null) {
            return $err;
        }

        $content = new ContentModel();
        $content->tstamp = time();
        $content->ptable = $ptable;
        $content->pid = $pid;
        $content->type = $type;
        $content->sorting = $sorting;

        $input = array_merge(
            ['ptable' => $ptable, 'pid' => $pid, 'type' => $type, 'sorting' => $sorting],
            self::normaliseFields($fields),
        );

        try {
            $this->mapper->apply($content, $input, detectChanges: false);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        try {
            $content->save();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->bootVersions((int) $content->id)->create();
        $this->logGeneral(
            sprintf('Created content ID %d (type=%s, %s:%d) via MCP', (int) $content->id, $type, $ptable, $pid),
            __METHOD__,
        );

        return $this->serializer->summary($content) + ['created' => true];
    }

    // ──────────────────────────── update ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'content_update',
        description: 'Updates fields of a content element. Pass id; everything else goes in `fields` (JSON object validated against the type\'s palette). Pass type= inside fields to switch the element to another type (the palette of the new type is used for validation). Versions snapshot + tl_log.',
    )]
    /**
     * @param object|null $fields tl_content columns to change as a JSON object. Pass type= here to switch element type. Use content_palette_get(type) for allowed keys.
     */
    public function update(int $id, #[Schema(type: 'object')] mixed $fields = null): array
    {
        $this->framework->initialize();

        $content = ContentModel::findById($id);
        if ($content === null) {
            return ['error' => 'not_found', 'message' => "Content element $id not found."];
        }

        $versions = $this->bootVersions($id);

        $input = self::normaliseFields($fields);
        if ($input === []) {
            return ['error' => 'no_fields', 'message' => 'Pass a non-empty `fields` object.'];
        }

        // If the caller is moving to a new parent, validate it first — pass
        // the element's own id so the cycle guard can reject self-nesting.
        if (isset($input['ptable']) || isset($input['pid'])) {
            $newPtable = (string) ($input['ptable'] ?? $content->ptable);
            $newPid = (int) ($input['pid'] ?? $content->pid);
            if (($err = $this->validateParent($newPtable, $newPid, (int) $content->id)) !== null) {
                return $err;
            }
        }

        try {
            $changed = $this->mapper->apply($content, $input, detectChanges: true);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        if ($changed === []) {
            return $this->serializer->summary($content) + [
                'updated' => false,
                'id' => (int) $content->id,
                'changed_fields' => [],
                'applied' => 0,
            ];
        }

        $content->tstamp = time();

        try {
            $content->save();
            $versions->create();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->logGeneral(
            sprintf('Updated content ID %d via MCP (fields: %s)', $id, implode(', ', $changed)),
            __METHOD__,
        );

        return $this->serializer->summary($content) + [
            'updated' => true,
            'id' => (int) $content->id,
            'changed_fields' => $changed,
            'applied' => \count($changed),
        ];
    }

    // ──────────────────────────── delete ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'content_delete',
        description: 'Deletes a tl_content row. Versions snapshot + tl_log entry. Returns {deleted: true, id}. Requires confirm_destructive=true to proceed.',
    )]
    public function delete(int $id, bool $confirm_destructive = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'content_delete is irreversible. Pass confirm_destructive=true to proceed.',
            ];
        }

        $content = ContentModel::findById($id);
        if ($content === null) {
            return ['error' => 'not_found', 'message' => "Content element $id not found."];
        }

        $this->bootVersions($id);
        $type = (string) $content->type;
        $ptable = (string) $content->ptable;
        $pid = (int) $content->pid;
        $content->delete();

        $this->logGeneral(
            sprintf('Deleted content ID %d (type=%s, %s:%d) via MCP', $id, $type, $ptable, $pid),
            __METHOD__,
        );

        return ['deleted' => true, 'id' => $id];
    }

    // ──────────────────── content_types_list ────────────────────────

    /**
     * @return array{categories: array<string, list<string>>, total: int}
     */
    #[McpTool(
        name: 'content_types_list',
        description: 'Lists every tl_content type that is currently registered (built-in + bundle-contributed). Output is grouped by Contao category (texts, images, files, media, includes, …). Pass any returned type string to content_palette_get for its allowed fields.',
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

    // ──────────────────── content_palette_get ───────────────────────

    // ────────────────────── content_create_tree ──────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'content_create_tree',
        description: <<<'DESC'
            Creates a whole block of content elements in ONE call. Pass `ptable` + `pid`
            (the article, news entry, event or FAQ the elements belong to) and `elements`
            as a LIST of nodes.

            A node is {type, fields?, children?}. `fields` is the same object
            content_create takes, validated against content_palette_get(type). `children`
            nests elements INTO the node — which only renders for container types
            (accordion, element_group, swiper); on any other type the children are created
            but never shown.

            Example:
              {"ptable": "tl_article", "pid": 12, "elements": [
                {"type": "headline", "fields": {"headline": {"value": "Leistungen", "unit": "h1"}}},
                {"type": "text", "fields": {"text": "<p>Was wir tun.</p>"}},
                {"type": "element_group", "children": [
                  {"type": "text", "fields": {"text": "<p>Spalte eins</p>"}},
                  {"type": "text", "fields": {"text": "<p>Spalte zwei</p>"}}
                ]}
              ]}

            `sorting` is filled in automatically per level in Contao's 128 increments; pass
            it in a node only to override.

            Why: every separate tool call costs a full framework boot on top of the actual
            work, plus a round-trip. A twelve-block page is one call instead of twelve.

            Everything checkable is checked BEFORE the first write — unknown types, unknown
            field keys per type, malformed nodes — and reported for the whole tree with
            nothing created. Runtime failures can still happen mid-run: that node is
            reported with its error and its children are skipped, siblings continue. There
            is no transaction and no bulk undo; every element carries its own version entry,
            so a partial result stays as it is and the per-node ids let a retry pick up
            instead of duplicating.

            Use `dry_run=true` for the plan (paths, types, computed sorting) without writing.
        DESC,
    )]
    public function createTree(
        string $ptable,
        int $pid,
        #[Schema(type: 'array')] mixed $elements,
        bool $dry_run = false,
    ): array {
        $this->framework->initialize();

        if (!\is_array($elements) || !array_is_list($elements) || $elements === []) {
            return ['error' => 'invalid_input', 'message' => '`elements` must be a non-empty JSON list of node objects.'];
        }

        $problems = [];
        $count = 0;
        $this->validateElementNodes($elements, '', $problems, $count);

        if ($count > self::MAX_TREE_ELEMENTS) {
            return [
                'error' => 'too_many_elements',
                'message' => sprintf('%d elements exceed the limit of %d per call — split the block.', $count, self::MAX_TREE_ELEMENTS),
            ];
        }

        if ($problems !== []) {
            // Reported before the first write: a typo in one node must not leave
            // half a page behind.
            return [
                'error' => 'invalid_input',
                'message' => 'The tree was rejected before anything was created.',
                'problems' => $problems,
            ];
        }

        if ($dry_run) {
            $plan = [];
            self::planElementNodes($elements, '', $plan);

            return ['dry_run' => true, 'elements' => $count, 'plan' => $plan];
        }

        $result = ['created' => 0, 'failed' => 0, 'skipped' => 0, 'elements' => []];
        $this->createElementNodes($elements, $ptable, $pid, '', $result);

        return $result;
    }

    /**
     * @param array<int, mixed>          $nodes
     * @param list<array<string, mixed>> $problems
     */
    private function validateElementNodes(array $nodes, string $path, array &$problems, int &$count): void
    {
        foreach ($nodes as $i => $node) {
            $here = $path === '' ? (string) ($i + 1) : $path.'.'.($i + 1);
            ++$count;

            if (!\is_array($node)) {
                $problems[] = ['path' => $here, 'error' => 'node must be an object'];
                continue;
            }

            foreach (array_keys($node) as $key) {
                if (!\in_array($key, ['type', 'fields', 'sorting', 'children'], true)) {
                    $problems[] = ['path' => $here, 'error' => sprintf('unknown key "%s" — a node is {type, fields?, sorting?, children?}', $key)];
                }
            }

            $type = $node['type'] ?? null;
            if (!\is_string($type) || trim($type) === '') {
                $problems[] = ['path' => $here, 'error' => 'type is required and must be a non-empty string'];
                $type = null;
            } elseif (!\in_array($type, $this->mapper->allKnownTypes(), true)) {
                $problems[] = ['path' => $here, 'error' => sprintf('unknown content type "%s" — see content_types_list', $type)];
                $type = null;
            }

            // Field keys are checked per type up front. They would be rejected
            // at write time anyway, but by then the earlier siblings exist and
            // the caller has half a page to clean up.
            $fields = $node['fields'] ?? null;
            if ($fields !== null && (!\is_array($fields) || array_is_list($fields))) {
                $problems[] = ['path' => $here, 'error' => '`fields` must be a JSON object'];
            } elseif (\is_array($fields) && $type !== null) {
                $allowed = $this->mapper->allowedFieldsFor($type);
                foreach (array_keys($fields) as $field) {
                    if (!\in_array($field, $allowed, true)) {
                        $problems[] = [
                            'path' => $here,
                            'error' => sprintf('"%s" is not a field of content type "%s" — see content_palette_get("%s")', $field, $type, $type),
                        ];
                    }
                }
            }

            $children = $node['children'] ?? [];
            if ($children !== [] && (!\is_array($children) || !array_is_list($children))) {
                $problems[] = ['path' => $here, 'error' => 'children must be a list of node objects'];
                continue;
            }
            if (\is_array($children) && $children !== []) {
                $this->validateElementNodes($children, $here, $problems, $count);
            }
        }
    }

    /**
     * @param array<int, mixed>          $nodes
     * @param list<array<string, mixed>> $plan
     */
    private static function planElementNodes(array $nodes, string $path, array &$plan): void
    {
        $sorting = 128;

        foreach ($nodes as $i => $node) {
            $here = $path === '' ? (string) ($i + 1) : $path.'.'.($i + 1);
            $plan[] = [
                'path' => $here,
                'type' => (string) $node['type'],
                'sorting' => $node['sorting'] ?? $sorting,
                'fields' => array_keys(\is_array($node['fields'] ?? null) ? $node['fields'] : []),
            ];
            $sorting += 128;

            if (!empty($node['children'])) {
                self::planElementNodes($node['children'], $here, $plan);
            }
        }
    }

    /**
     * @param array<int, mixed>    $nodes
     * @param array<string, mixed> $result
     */
    private function createElementNodes(array $nodes, string $ptable, int $pid, string $path, array &$result): void
    {
        $sorting = 128;

        foreach ($nodes as $i => $node) {
            $here = $path === '' ? (string) ($i + 1) : $path.'.'.($i + 1);
            $children = $node['children'] ?? [];
            $type = (string) $node['type'];
            $ownSorting = (int) ($node['sorting'] ?? $sorting);
            $sorting += 128;

            try {
                $created = $this->create($ptable, $pid, $type, $ownSorting, $node['fields'] ?? null);
            } catch (\Throwable $e) {
                $created = ['error' => 'exception', 'message' => $e->getMessage()];
            }

            if (!isset($created['id'])) {
                ++$result['failed'];
                $skipped = self::countElementNodes($children);
                $result['skipped'] += $skipped;
                $result['elements'][] = [
                    'path' => $here,
                    'type' => $type,
                    'error' => $created['error'] ?? 'unknown',
                    'message' => $created['message'] ?? '',
                    // Children are skipped rather than reparented: an element
                    // whose container does not exist would land loose in the
                    // article, which looks like it worked.
                    'skipped_children' => $skipped,
                ];
                continue;
            }

            ++$result['created'];
            $id = (int) $created['id'];
            $result['elements'][] = [
                'path' => $here,
                'id' => $id,
                'type' => $type,
                'ptable' => $ptable,
                'pid' => $pid,
                'sorting' => $ownSorting,
            ];

            if (\is_array($children) && $children !== []) {
                $this->createElementNodes($children, 'tl_content', $id, $here, $result);
            }
        }
    }

    /**
     * @param array<int, mixed>|mixed $nodes
     */
    private static function countElementNodes(mixed $nodes): int
    {
        if (!\is_array($nodes)) {
            return 0;
        }

        $n = 0;
        foreach ($nodes as $node) {
            ++$n;
            if (\is_array($node) && !empty($node['children'])) {
                $n += self::countElementNodes($node['children']);
            }
        }

        return $n;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'content_palette_get',
        description: 'Returns the list of fields valid for a given content type (built from the live tl_content DCA palette + always-allowed core fields).'
            .' Sub-palette children are listed only when their toggle is part of the palette of THIS type — Contao keeps one wide table per DCA, so a column existing on the row does not mean the type has it.'
            .' `subpalettes` maps each toggle to the fields it opens; a toggle and its children may be set in the same call.',
    )]
    public function paletteGet(string $type): array
    {
        try {
            $fields = $this->mapper->allowedFieldsFor($type);
        } catch (\Throwable $e) {
            return ['error' => 'load_failed', 'message' => $e->getMessage()];
        }

        if ($fields === FieldMapper::COMMON_FIELDS) {
            // Two very different situations produce the same short field list,
            // and answering both with "call content_types_list" sends a caller
            // in a circle when the type is listed there: unknown type, versus a
            // registered type whose palette is assembled at edit time.
            $known = \in_array($type, $this->mapper->allKnownTypes(), true);

            if (!$known) {
                return [
                    'type' => $type,
                    'known' => false,
                    'message' => sprintf('Type "%s" is not registered in this installation — call content_types_list for the types that exist.', $type),
                    'fields' => $fields,
                ];
            }

            return [
                'type' => $type,
                'known' => true,
                'dynamic_palette' => true,
                'message' => sprintf(
                    'Type "%s" exists but has no STATIC palette: it is assembled at edit time (an onload callback, or virtual fields backed by a serialised column), so it cannot be read here. '
                    .'Through content_create / content_update only the fields below plus any extension-declared fields are writable. '
                    .'The extension providing this type usually ships its own tools for it — check installed_bundles (mcp_entity_extensions) and the tool panel under MCP-Server → Tools, where extension tools have to be enabled before they appear.',
                    $type,
                ),
                'fields' => $fields,
                'count' => \count($fields),
            ];
        }

        return [
            'type' => $type,
            'known' => true,
            'fields' => $fields,
            'count' => \count($fields),
            'subpalettes' => $this->mapper->subpalettesFor($type),
        ];
    }

    // ───────────────────────── private helpers ──────────────────────

    /**
     * @return array{0: string, 1: int, 2: array<string,mixed>|null}
     */
    private static function resolveParent(?int $article_id, ?int $news_id, ?int $page_id, ?string $ptable, ?int $pid): array
    {
        $count = (int) ($article_id !== null) + (int) ($news_id !== null) + (int) ($page_id !== null) + (int) ($ptable !== null && $pid !== null);
        if ($count !== 1) {
            return ['', 0, ['error' => 'parent_required', 'message' => 'Pass exactly one of: article_id, news_id, page_id, or both ptable+pid.']];
        }

        if ($article_id !== null) {
            return ['tl_article', $article_id, null];
        }
        if ($news_id !== null) {
            return ['tl_news', $news_id, null];
        }
        if ($page_id !== null) {
            return ['tl_page', $page_id, null];
        }

        return [(string) $ptable, (int) $pid, null];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function validateParent(string $ptable, int $pid, int $selfId = 0): ?array
    {
        // Verify the row exists in the parent table. Unknown ptables are
        // checked via a generic DBAL lookup — using the table only after
        // confirming it's one of the supported parents (deny-list keeps an
        // attacker from feeding us arbitrary table names via SQL).
        //
        // tl_content is a valid parent in Contao 5: nestable container CEs
        // (accordion / swiper / element_group) own child rows with
        // ptable='tl_content', pid=<container-id>. We allow it but guard
        // against cycles below.
        $exists = match ($ptable) {
            'tl_article' => ArticleModel::findById($pid) !== null,
            'tl_news' => NewsModel::findById($pid) !== null,
            'tl_calendar_events' => $this->parentExistsViaDbal('tl_calendar_events', $pid),
            'tl_faq' => $this->parentExistsViaDbal('tl_faq', $pid),
            'tl_page' => $this->parentExistsViaDbal('tl_page', $pid),  // legacy article-less pages
            'tl_content' => $this->parentExistsViaDbal('tl_content', $pid), // nested CE container
            default => null,                                            // unknown ptable
        };

        if ($exists === null) {
            return [
                'error' => 'invalid_input',
                'message' => sprintf(
                    'Unsupported parent table "%s". Allowed: tl_article, tl_news, tl_calendar_events, tl_faq, tl_page, tl_content (nested elements).',
                    $ptable,
                ),
            ];
        }
        if (!$exists) {
            return ['error' => 'parent_not_found', 'message' => "Parent $ptable:$pid does not exist."];
        }

        // Cycle guard for nested content: a container must never become a
        // descendant of itself. Walk the tl_content parent chain up from the
        // requested pid; reject if we reach the element being moved ($selfId)
        // or detect a pre-existing loop. Contao does not enforce this and a
        // cycle would spin the renderer forever.
        if ($ptable === 'tl_content' && $this->wouldNestingCycle($pid, $selfId)) {
            return [
                'error' => 'invalid_parent',
                'message' => 'Cannot nest a content element under itself or one of its own descendants.',
            ];
        }

        return null;
    }

    /**
     * True when making $selfId a child of tl_content:$pid would create a cycle
     * (i.e. $pid is $selfId itself or sits below it). Walks the parent chain
     * up to a sane depth cap; a chain longer than that is treated as a loop.
     */
    private function wouldNestingCycle(int $pid, int $selfId): bool
    {
        $current = $pid;
        $seen = [];
        for ($depth = 0; $current > 0 && $depth < 100; ++$depth) {
            if ($current === $selfId || isset($seen[$current])) {
                return true;
            }
            $seen[$current] = true;

            $row = $this->connection->fetchAssociative(
                'SELECT ptable, pid FROM tl_content WHERE id = ?',
                [$current],
            );
            if ($row === false || (string) $row['ptable'] !== 'tl_content') {
                return false; // reached a non-content root → no cycle
            }
            $current = (int) $row['pid'];
        }

        return $current > 0; // hit the depth cap with more chain to go → bail
    }

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
            return $fields;
        }

        throw new \InvalidArgumentException('`fields` must be a JSON object.');
    }

    private function bootVersions(int $id): Versions
    {
        $v = new Versions('tl_content', $id);
        $v->setUsername($this->authorResolver->getLogUsername());
        $v->setUserId($this->authorResolver->resolve());
        $v->initialize();

        return $v;
    }

    private function logGeneral(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::GENERAL, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }

    /**
     * @return array{value: ?string, unit: ?string}
     */
    private function headlineTuple(mixed $value): array
    {
        if (!$value) {
            return ['value' => null, 'unit' => null];
        }
        $arr = @unserialize((string) $value, ['allowed_classes' => false]);
        if (!\is_array($arr)) {
            return ['value' => null, 'unit' => null];
        }

        return [
            'value' => isset($arr['value']) ? (string) $arr['value'] : null,
            'unit' => isset($arr['unit']) ? (string) $arr['unit'] : null,
        ];
    }
}
