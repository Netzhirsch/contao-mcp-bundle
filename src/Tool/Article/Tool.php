<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Article;

use Contao\ArticleModel;
use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\CoreBundle\Slug\Slug;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\DbalRetry;
use Netzhirsch\ContaoMcpBundle\Service\QueryFilterResolver;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;

/**
 * MCP facade for tl_article. Five tools: list/get/create/update/delete.
 *
 * Articles sit on a page (pid → tl_page.id), get sorted by tl_article.sorting, and
 * own a stream of tl_content elements (via content.pid + content.ptable='tl_article').
 *
 * Special-case fields:
 *   - cssID / space:   serialised [id, class] / [top, bottom] tuples
 *   - printable:       serialised list of share-button keys (print, pdf, …)
 *   - groups:          serialised list<int> of tl_member_group.id (when protected=true)
 *
 * Plus the changelanguage extension contributes languageMain when installed.
 */
final class Tool
{
    /**
     * Property maps for the cssID / space object params. Passed to param-level
     * #[Schema(type:'object', properties: …)] so each emits a CONCRETE
     * top-level `type: object` (not `mixed`) — fragile MCP clients drop
     * typeless properties (CWA-26 family). `definition` is NOT honoured
     * per-parameter, so the explicit fields are required.
     *
     * @var array<string, array<string, string>>
     */
    private const CSS_ID_PROPS = [
        'id' => ['type' => 'string'],
        'class' => ['type' => 'string'],
    ];

    /** @var array<string, array<string, string>> */
    private const SPACE_PROPS = [
        'top' => ['type' => 'string'],
        'bottom' => ['type' => 'string'],
    ];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly LoggerInterface $logger,
        private readonly Slug $slug,
        private readonly AuthorResolver $authorResolver,
        private readonly DbalRetry $dbalRetry,
        private readonly FieldMapper $mapper,
        private readonly Serializer $serializer,
        private readonly QueryFilterResolver $filterResolver,
        private readonly Connection $connection,
        private readonly McpPermissionGuard $guard,
    ) {
    }

    // ───────────────────────────── list ─────────────────────────────

    /**
     * @param object|null $filters Optional DCA-validated filter map. Discover the
     *                             allowed keys via `entity_query_options("tl_article")`.
     *
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'articles_list',
        description: 'Lists Contao articles (tl_article). Optional page_id filters to articles on a specific page. q does LIKE-search across DCA-searchable fields; filters is a DCA-validated equality map; updated_after/before take Unix-ts or ISO-8601. Use entity_query_options("tl_article") for the queryable shape. Default: ALL incl. unpublished/disabled (pass include_unpublished=false for published-only).',
    )]
    public function list(
        ?int $page_id = null,
        bool $include_unpublished = true,
        int $limit = 50,
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
        if ($page_id !== null) {
            $columns[] = 'tl_article.pid = ?';
            $values[] = $page_id;
        }
        if (!$include_unpublished) {
            $time = time();
            $columns[] = 'tl_article.published = ?';
            $values[] = '1';
            $columns[] = "(tl_article.start = '' OR tl_article.start <= ?)";
            $values[] = (string) $time;
            $columns[] = "(tl_article.stop = '' OR tl_article.stop > ?)";
            $values[] = (string) $time;
        }

        $search = $this->filterResolver->buildSearchClause('tl_article', $q);
        if ($search !== null) {
            $columns[] = $search['clause'];
            $values = array_merge($values, $search['params']);
        }
        try {
            $filtersArr = $this->filterResolver->normaliseFilters($filters);
            $fr = $this->filterResolver->buildFilterClauses('tl_article', $filtersArr);
            $columns = array_merge($columns, $fr['clauses']);
            $values = array_merge($values, $fr['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_filter', 'message' => $e->getMessage()];
        }
        try {
            $ts = $this->filterResolver->buildTstampRange('tl_article', $updated_after, $updated_before);
            $columns = array_merge($columns, $ts['clauses']);
            $values = array_merge($values, $ts['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $items = ArticleModel::findBy(
            $columns ?: null,
            $values ?: null,
            ['order' => 'tl_article.pid, tl_article.sorting', 'limit' => $limit, 'offset' => $offset],
        );

        $accessiblePages = $this->guard->accessiblePageIds();
        $out = [];
        foreach ($items ?? [] as $a) {
            if ($accessiblePages !== null && !\in_array((int) $a->pid, $accessiblePages, true)) {
                continue; // only articles on pages within the caller's pagemounts
            }
            $out[] = [
                'id' => (int) $a->id,
                'page_id' => (int) $a->pid,
                'sorting' => (int) $a->sorting,
                'title' => (string) $a->title,
                'alias' => (string) $a->alias,
                'inColumn' => (string) $a->inColumn,
                'protected' => (bool) $a->protected,
                'published' => (bool) $a->published,
            ];
        }

        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    // ───────────────────────────── get ──────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'article_get',
        description: 'Returns a single Contao article by id with all DCA-editable fields plus its content elements (read-only listing of tl_content rows with ptable=tl_article).',
    )]
    public function get(int $id, bool $include_unpublished = true): array
    {
        $this->framework->initialize();

        $article = ArticleModel::findById($id);
        if ($article === null) {
            return ['error' => 'not_found', 'message' => "Article $id not found."];
        }

        return $this->serializer->summary($article) + [
            'content_elements' => $this->collectContentElements((int) $article->id, $include_unpublished),
        ];
    }

    // ──────────────────────────── create ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'article_create',
        description: <<<'DESC'
            Creates a new article on the given page. Required: page_id, title, sorting
            (Contao uses 128-increments; use articles_list to find sibling sortings).

            Conventions:
              - alias: auto-generated from title via Contao's Slug service when omitted.
              - inColumn: one of "main", "header", "left", "right", "footer". Defaults
                to "main".
              - cssID:  object {id, class} → stored as serialised [id, class]
              - space:  object {top, bottom} → stored as serialised [top, bottom]
              - printable: list of strings ["print", "pdf", "facebook", "twitter"]
              - customTpl: a template name from templates_list("mod_article_") or "" for default
              - languageMain: requires terminal42/contao-changelanguage

            Wraps the new article in a Versions snapshot and writes to tl_log.
        DESC,
    )]
    public function create(
        int $page_id,
        string $title,
        int $sorting,
        ?string $alias = null,
        ?int $author_id = null,
        ?string $inColumn = null,
        ?bool $showTeaser = null,
        ?string $teaserCssID = null,
        ?string $teaser = null,
        ?array $printable = null,
        ?string $customTpl = null,
        ?bool $protected = null,
        ?array $groups = null,
        ?bool $guests = null,
        #[Schema(type: 'object', properties: self::CSS_ID_PROPS, description: 'CSS ID + class, stored as serialised [id, class]. Example: {"id": "intro", "class": "highlight"}.')] mixed $cssID = null,
        #[Schema(type: 'object', properties: self::SPACE_PROPS, description: 'Top/bottom margin keys, stored as serialised [top, bottom]. Example: {"top": "6", "bottom": "3"}.')] mixed $space = null,
        ?bool $published = null,
        ?string $start = null,
        ?string $stop = null,
        // ─── Extension: terminal42/contao-changelanguage ────────────────────
        ?int $languageMain = null,
        // ─── Generic extras ─────────────────────────────────────────────────
        #[Schema(type: 'object')] mixed $extras = null,
    ): array {
        $this->framework->initialize();

        if (PageModel::findById($page_id) === null) {
            return ['error' => 'page_not_found', 'message' => "Page $page_id does not exist."];
        }

        $article = new ArticleModel();
        $article->tstamp = time();
        $article->pid = $page_id;
        $article->sorting = $sorting;
        $article->title = $title;
        $article->alias = '';
        $article->author = 0;
        $article->inColumn = 'main';
        $article->printable = '';
        $article->groups = '';
        $article->cssID = '';
        $article->space = '';
        foreach (['showTeaser', 'protected', 'guests', 'published'] as $boolField) {
            $article->$boolField = 0;
        }

        $coreInput = array_filter(
            compact(
                'title', 'alias', 'author_id', 'inColumn',
                'showTeaser', 'teaserCssID', 'teaser', 'printable',
                'customTpl', 'protected', 'groups', 'guests',
                'cssID', 'space', 'published', 'start', 'stop',
            ) + ['page_id' => $page_id, 'sorting' => $sorting],
            static fn ($v): bool => $v !== null,
        );

        $input = self::mergeExtensionInput($coreInput, $languageMain, $extras);

        try {
            $this->mapper->apply($article, $input, detectChanges: false);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        if ((int) $article->author === 0) {
            $article->author = $this->authorResolver->resolve();
        }

        if ((string) $article->alias === '') {
            $article->alias = $this->generateAlias($title);
        }

        try {
            $article->save();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->bootVersions((int) $article->id)->create();
        $this->logGeneral(
            sprintf('Created article ID %d ("%s") on page %d via MCP', (int) $article->id, $title, $page_id),
            __METHOD__,
        );

        return $this->serializer->summary($article) + ['created' => true];
    }

    // ──────────────────────────── update ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'article_update',
        description: 'Updates fields of an existing article. Only fields you pass are changed. Returns the updated article + changed_fields, wrapped in a Versions snapshot and tl_log entry.',
    )]
    public function update(
        int $id,
        ?int $page_id = null,
        ?int $sorting = null,
        ?string $title = null,
        ?string $alias = null,
        ?int $author_id = null,
        ?string $inColumn = null,
        ?bool $showTeaser = null,
        ?string $teaserCssID = null,
        ?string $teaser = null,
        ?array $printable = null,
        ?string $customTpl = null,
        ?bool $protected = null,
        ?array $groups = null,
        ?bool $guests = null,
        #[Schema(type: 'object', properties: self::CSS_ID_PROPS, description: 'CSS ID + class, stored as serialised [id, class]. Example: {"id": "intro", "class": "highlight"}.')] mixed $cssID = null,
        #[Schema(type: 'object', properties: self::SPACE_PROPS, description: 'Top/bottom margin keys, stored as serialised [top, bottom]. Example: {"top": "6", "bottom": "3"}.')] mixed $space = null,
        ?bool $published = null,
        ?string $start = null,
        ?string $stop = null,
        ?int $languageMain = null,
        #[Schema(type: 'object')] mixed $extras = null,
    ): array {
        $this->framework->initialize();

        $article = ArticleModel::findById($id);
        if ($article === null) {
            return ['error' => 'not_found', 'message' => "Article $id not found."];
        }

        if ($page_id !== null && PageModel::findById($page_id) === null) {
            return ['error' => 'page_not_found', 'message' => "Page $page_id does not exist."];
        }

        $versions = $this->bootVersions($id);

        if ($alias === '') {
            $alias = $this->generateAlias((string) ($title ?? $article->title), $id);
        }

        $coreInput = array_filter(
            compact(
                'page_id', 'sorting', 'title', 'alias', 'author_id', 'inColumn',
                'showTeaser', 'teaserCssID', 'teaser', 'printable',
                'customTpl', 'protected', 'groups', 'guests',
                'cssID', 'space', 'published', 'start', 'stop',
            ),
            static fn ($v): bool => $v !== null,
        );

        $input = self::mergeExtensionInput($coreInput, $languageMain, $extras);

        try {
            $changed = $this->mapper->apply($article, $input, detectChanges: true);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        if ($changed === []) {
            return $this->serializer->summary($article) + [
                'updated' => false,
                'id' => (int) $article->id,
                'changed_fields' => [],
                'applied' => 0,
            ];
        }

        $article->tstamp = time();

        try {
            $article->save();
            $versions->create();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->logGeneral(
            sprintf('Updated article ID %d via MCP (fields: %s)', $id, implode(', ', $changed)),
            __METHOD__,
        );

        return $this->serializer->summary($article) + [
            'updated' => true,
            'id' => (int) $article->id,
            'changed_fields' => $changed,
            'applied' => \count($changed),
        ];
    }

    // ──────────────────────────── delete ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'article_delete',
        description: 'Deletes an article and cascades to its content elements (tl_content rows with ptable=tl_article). Versions snapshot + tl_log entry. Returns the deleted id and the count of cascaded content elements. Requires confirm_destructive=true to proceed.',
    )]
    public function delete(int $id, bool $confirm_destructive = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'article_delete is irreversible. Pass confirm_destructive=true to proceed.',
            ];
        }

        $article = ArticleModel::findById($id);
        if ($article === null) {
            return ['error' => 'not_found', 'message' => "Article $id not found."];
        }

        $title = (string) $article->title;
        $this->bootVersions($id);

        $contentCount = 0;
        // Wrap content-element cascade + article delete in one transaction so a
        // mid-cascade failure can't leave tl_content rows orphaned (article gone
        // but its content still referencing the dead id) or vice versa.
        $this->dbalRetry->transactional($this->connection, function () use ($id, $article, &$contentCount): void {
            $contentItems = ContentModel::findBy(['pid = ?', 'ptable = ?'], [$id, 'tl_article']);
            foreach ($contentItems ?? [] as $content) {
                $content->delete();
                ++$contentCount;
            }

            $article->delete();
        });

        $this->logGeneral(
            sprintf('Deleted article ID %d ("%s") and %d content element(s) via MCP', $id, $title, $contentCount),
            __METHOD__,
        );

        return [
            'deleted' => true,
            'id' => $id,
            'deleted_content_elements' => $contentCount,
        ];
    }

    // ───────────────────────── private helpers ──────────────────────

    /**
     * @param array<string, mixed> $coreInput
     *
     * @return array<string, mixed>
     */
    private static function mergeExtensionInput(array $coreInput, ?int $languageMain, mixed $extras): array
    {
        $explicit = array_filter(['languageMain' => $languageMain], static fn ($v): bool => $v !== null);
        $extrasArray = match (true) {
            \is_array($extras) => $extras,
            \is_object($extras) => (array) $extras,
            default => [],
        };

        return array_merge($extrasArray, $explicit, $coreInput);
    }

    private function bootVersions(int $id): Versions
    {
        $v = new Versions('tl_article', $id);
        $v->setUsername($this->authorResolver->getLogUsername());
        $v->setUserId($this->authorResolver->resolve());
        $v->initialize();

        return $v;
    }

    private function logGeneral(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::GENERAL, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }

    private function generateAlias(string $title, ?int $excludeId = null): string
    {
        $check = $excludeId === null
            ? static fn (string $value): bool => ArticleModel::findOneBy('alias', $value) !== null
            : static fn (string $value): bool => ArticleModel::findOneBy(['alias = ?', 'id != ?'], [$value, $excludeId]) !== null;

        return $this->slug->generate($title, ['validChars' => '0-9a-z'], $check);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectContentElements(int $articleId, bool $includeUnpublished): array
    {
        $columns = ['tl_content.pid = ?', "tl_content.ptable = 'tl_article'"];
        $values = [$articleId];

        if (!$includeUnpublished) {
            $time = time();
            $columns[] = "tl_content.invisible = ''";
            $columns[] = "(tl_content.start = '' OR tl_content.start <= ?)";
            $values[] = (string) $time;
            $columns[] = "(tl_content.stop = '' OR tl_content.stop > ?)";
            $values[] = (string) $time;
        }

        $elements = ContentModel::findBy($columns, $values, ['order' => 'tl_content.sorting']);

        $out = [];
        foreach ($elements ?? [] as $el) {
            $headline = StringUtil::deserialize($el->headline, true);
            $out[] = [
                'id' => (int) $el->id,
                'type' => (string) $el->type,
                'sorting' => (int) $el->sorting,
                'headline_text' => isset($headline['value']) ? (string) $headline['value'] : null,
                'headline_level' => isset($headline['unit']) ? (string) $headline['unit'] : null,
            ];
        }

        return $out;
    }
}
