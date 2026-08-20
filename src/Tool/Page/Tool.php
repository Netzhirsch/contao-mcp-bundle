<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Page;

use Contao\ArticleModel;
use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\CoreBundle\Slug\Slug;
use Contao\PageModel;
use Contao\Versions;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\DbalRetry;
use Netzhirsch\ContaoMcpBundle\Service\QueryFilterResolver;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;

/**
 * MCP facade for tl_page. Six operations:
 *   pages_list, pages_tree, page_get, page_create, page_update, page_delete.
 *
 * Strict per-type validation: every input field on create/update must appear in the
 * palette of the resolved page-type (see Page\FieldMapper). Unknown fields → error
 * "invalid_input" with the allowed list.
 *
 * Delete is conservative: refuses if the page has sub-pages (cascading the tree silently
 * would be too dangerous). Articles + their content elements can be cascaded with
 * force=true.
 */
final class Tool
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly LoggerInterface $logger,
        private readonly Slug $slug,
        private readonly AuthorResolver $authorResolver,
        private readonly DbalRetry $dbalRetry,
        private readonly FieldMapper $mapper,
        private readonly Serializer $serializer,
        private readonly Connection $connection,
        private readonly QueryFilterResolver $filterResolver,
        private readonly McpPermissionGuard $guard,
    ) {
    }

    /**
     * Tables that reference a tl_page row through a `jumpTo` int column.
     * Used by page_delete to surface dangling references and (with force=true)
     * reset them to 0 inside the same delete operation.
     */
    private const JUMPTO_REFERRER_TABLES = [
        'tl_news_archive',
        'tl_calendar',
        'tl_faq_category',
        'tl_member_group',
        'tl_form',
        'tl_module',
    ];

    /**
     * @return array<string, int>  table → reference count
     */
    private function findJumpToReferrers(int $pageId): array
    {
        $result = [];
        foreach (self::JUMPTO_REFERRER_TABLES as $table) {
            $count = (int) $this->connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE jumpTo = ?', $this->connection->quoteIdentifier($table)),
                [$pageId],
            );
            $result[$table] = $count;
        }

        return $result;
    }

    // ───────────────────────────── list ─────────────────────────────

    /**
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}
     */
    /**
     * @param object|null $filters Optional structured filter map, e.g.
     *                             `{"published": true, "noSearch": false}`. Only
     *                             columns marked `'filter' => true` in the DCA
     *                             are allowed — call `entity_query_options("tl_page")`
     *                             to discover the full set.
     */
    #[McpTool(
        name: 'pages_list',
        description: <<<'DESC'
            Lists Contao pages.

            Filters:
              - parent_id           — direct children of that page (0 = root level)
              - type                — single page type (legacy convenience param)
              - q                   — LIKE-search across DCA-searchable fields (title,
                                      alias, pageTitle, description — varies per install)
              - filters             — structured equality map. Keys MUST be DCA-filterable
                                      columns. Scalar = `col = val`, list = `col IN (...)`,
                                      null = `col IS NULL`. Use `entity_query_options("tl_page")`
                                      to discover allowed keys + value types.
              - updated_after       — Unix timestamp or ISO-8601 (e.g. "2024-12-31"). Limits
                                      to pages whose `tstamp` is at or after this point.
              - updated_before      — analogous upper bound
              - include_unpublished — DEFAULT true (MCP also returns unpublished/disabled);
                                      pass false for published-only + within start/stop window

            Default order: tree (pid, sorting).
        DESC,
    )]
    public function list(
        ?int $parent_id = null,
        ?string $type = null,
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

        if ($parent_id !== null) {
            $columns[] = 'tl_page.pid = ?';
            $values[] = $parent_id;
        }
        if ($type !== null && $type !== '') {
            $columns[] = 'tl_page.type = ?';
            $values[] = $type;
        }
        if (!$include_unpublished) {
            $time = time();
            $columns[] = 'tl_page.published = ?';
            $values[] = '1';
            $columns[] = "(tl_page.start = '' OR tl_page.start <= ?)";
            $values[] = (string) $time;
            $columns[] = "(tl_page.stop = '' OR tl_page.stop > ?)";
            $values[] = (string) $time;
        }

        $search = $this->filterResolver->buildSearchClause('tl_page', $q);
        if ($search !== null) {
            $columns[] = $search['clause'];
            $values = array_merge($values, $search['params']);
        }

        try {
            $filtersArr = $this->filterResolver->normaliseFilters($filters);
            $filterRes = $this->filterResolver->buildFilterClauses('tl_page', $filtersArr);
            $columns = array_merge($columns, $filterRes['clauses']);
            $values = array_merge($values, $filterRes['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_filter', 'message' => $e->getMessage()];
        }

        try {
            $tstampRes = $this->filterResolver->buildTstampRange('tl_page', $updated_after, $updated_before);
            $columns = array_merge($columns, $tstampRes['clauses']);
            $values = array_merge($values, $tstampRes['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $items = PageModel::findBy(
            $columns ?: null,
            $values ?: null,
            ['order' => 'tl_page.pid, tl_page.sorting', 'limit' => $limit, 'offset' => $offset],
        );

        $accessiblePages = $this->guard->accessiblePageIds();
        $serialized = [];
        foreach ($items ?? [] as $page) {
            if ($accessiblePages !== null && !\in_array((int) $page->id, $accessiblePages, true)) {
                continue; // only pages within the caller's pagemounts
            }
            $serialized[] = $this->serializer->summary($page);
        }

        return [
            'items' => $serialized,
            'count' => \count($serialized),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    // ───────────────────────────── tree ─────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'pages_tree',
        description: 'Returns the Contao page tree starting at root_id (default 0 = all root pages), nested as children arrays. max_depth limits recursion. Each node has the minimal fields needed for navigation: id, pid, sorting, title, type, alias, published. Use page_get for full details on a specific id.',
    )]
    public function tree(int $root_id = 0, int $max_depth = 5): array
    {
        $this->framework->initialize();
        $max_depth = max(1, min($max_depth, 12));

        $rootChildren = $this->collectChildren($root_id, $max_depth);

        return [
            'root_id' => $root_id,
            'max_depth' => $max_depth,
            'pages' => $rootChildren,
        ];
    }

    // ───────────────────────────── get ──────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'page_get',
        description: 'Returns a single Contao page by id, all DCA-editable fields. Use pages_list / pages_tree to discover ids.',
    )]
    public function get(int $id): array
    {
        $this->framework->initialize();

        $page = PageModel::findById($id);
        if ($page === null) {
            return ['error' => 'not_found', 'message' => "Page $id not found."];
        }

        return $this->serializer->summary($page);
    }

    // ──────────────────────────── create ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'page_create',
        description: <<<'DESC'
            Creates a new Contao page. Required: pid (parent page id, 0 for new root),
            title, type, sorting (Contao uses 128-increments; use pages_list to find
            sibling sorting values and pick a free slot like prevSibling+64).

            Strict per-type validation: optional fields must match the page type's
            palette. Allowed types: regular, forward, redirect, root, logout,
            error_401/403/404/503, news_feed, calendar_feed.

            Conventions:
              - alias: auto-generated from title via Contao's Slug service if omitted.
              - start, stop: full ISO 8601 datetime, or empty to clear.
              - favicon: 32-char hex UUID or UUID with dashes.
              - groups, newsArchives, eventCalendars: list of integer ids.
              - chmod: list of permission flags (u1..u6, g1..g6, w1..w6).
              - useSSL stores as 1/0 — pass true for HTTPS.

            On success the new page is wrapped in a Versions snapshot and written to
            tl_log so it appears in the backend history.
        DESC,
    )]
    public function create(
        int $pid,
        string $title,
        string $type,
        int $sorting,
        // Routing
        ?string $alias = null,
        ?bool $requireItem = null,
        ?int $routePriority = null,
        // SEO/meta
        ?string $pageTitle = null,
        ?string $language = null,
        ?string $robots = null,
        ?string $description = null,
        ?string $canonicalLink = null,
        ?string $canonicalKeepParams = null,
        // Redirect/forward
        ?string $redirect = null,
        ?int $jumpTo = null,
        ?bool $redirectBack = null,
        ?bool $alwaysForward = null,
        ?bool $autoforward = null,
        ?string $url = null,
        ?bool $target = null,
        // Root / DNS
        ?string $dns = null,
        ?bool $useSSL = null,
        ?string $urlPrefix = null,
        ?string $urlSuffix = null,
        ?string $validAliasCharacters = null,
        ?bool $useFolderUrl = null,
        ?bool $fallback = null,
        ?bool $disableLanguageRedirect = null,
        ?string $staticFiles = null,
        ?string $staticPlugins = null,
        ?string $favicon = null,
        ?string $robotsTxt = null,
        ?bool $maintenanceMode = null,
        ?string $mailerTransport = null,
        ?bool $enableCanonical = null,
        ?string $adminEmail = null,
        ?string $dateFormat = null,
        ?string $timeFormat = null,
        ?string $datimFormat = null,
        // Protected
        ?bool $protected = null,
        ?array $groups = null,
        // Layout
        ?bool $includeLayout = null,
        ?int $layout = null,
        ?int $subpageLayout = null,
        // Cache
        ?bool $includeCache = null,
        ?int $cache = null,
        ?int $clientCache = null,
        ?bool $alwaysLoadFromCache = null,
        // Chmod
        ?bool $includeChmod = null,
        ?int $cuser = null,
        ?int $cgroup = null,
        ?array $chmod = null,
        // Expert
        ?string $cssClass = null,
        ?string $sitemap = null,
        ?string $searchIndexer = null,
        ?bool $hide = null,
        ?bool $guests = null,
        ?string $accesskey = null,
        // 2FA
        ?bool $enforceTwoFactor = null,
        ?int $twoFactorJumpTo = null,
        // CSP
        ?bool $enableCsp = null,
        ?string $csp = null,
        ?bool $cspReportOnly = null,
        ?bool $cspReportLog = null,
        // Publish
        ?bool $published = null,
        ?string $start = null,
        ?string $stop = null,
        // News-feed
        ?array $newsArchives = null,
        // Calendar-feed
        ?array $eventCalendars = null,
        // Feed common
        ?string $feedFormat = null,
        ?string $feedSource = null,
        ?int $maxFeedItems = null,
        ?string $feedFeatured = null,
        ?string $feedDescription = null,
        ?int $feedRecurrenceLimit = null,
        ?string $imgSize = null,
        // ─── Extension: terminal42/contao-changelanguage ────────────────────
        // Only accepted when that bundle is installed; otherwise the tool returns
        // error "extension_not_available". Read installed_bundles to verify.
        ?int $languageMain = null,
        ?int $languageRoot = null,
        ?string $languageQuery = null,
        // ─── Generic extras for any other field-provider extension ──────────
        // Pass a JSON object whose keys are extension fields (e.g. {"someField": 42}).
        // Each key is routed through the matching provider; unknown keys → error.
        #[Schema(type: 'object')] mixed $extras = null,
    ): array {
        $this->framework->initialize();

        if (!\in_array($type, FieldMapper::ALLOWED_TYPES, true)) {
            return [
                'error' => 'invalid_type',
                'message' => sprintf('Unknown page type "%s". Allowed: %s.', $type, implode(', ', FieldMapper::ALLOWED_TYPES)),
            ];
        }

        if ($pid !== 0 && PageModel::findById($pid) === null) {
            return ['error' => 'parent_not_found', 'message' => "Parent page $pid does not exist."];
        }

        $page = new PageModel();
        $page->tstamp = time();

        $coreInput = array_filter(
            compact(
                'pid', 'title', 'type', 'sorting',
                'alias', 'requireItem', 'routePriority',
                'pageTitle', 'language', 'robots', 'description', 'canonicalLink', 'canonicalKeepParams',
                'redirect', 'jumpTo', 'redirectBack', 'alwaysForward', 'autoforward', 'url', 'target',
                'dns', 'useSSL', 'urlPrefix', 'urlSuffix', 'validAliasCharacters', 'useFolderUrl',
                'fallback', 'disableLanguageRedirect', 'staticFiles', 'staticPlugins',
                'favicon', 'robotsTxt', 'maintenanceMode', 'mailerTransport', 'enableCanonical',
                'adminEmail', 'dateFormat', 'timeFormat', 'datimFormat',
                'protected', 'groups',
                'includeLayout', 'layout', 'subpageLayout',
                'includeCache', 'cache', 'clientCache', 'alwaysLoadFromCache',
                'includeChmod', 'cuser', 'cgroup', 'chmod',
                'cssClass', 'sitemap', 'searchIndexer', 'hide', 'guests', 'accesskey',
                'enforceTwoFactor', 'twoFactorJumpTo',
                'enableCsp', 'csp', 'cspReportOnly', 'cspReportLog',
                'published', 'start', 'stop',
                'newsArchives', 'eventCalendars',
                'feedFormat', 'feedSource', 'maxFeedItems', 'feedFeatured', 'feedDescription',
                'feedRecurrenceLimit', 'imgSize',
            ),
            static fn ($v): bool => $v !== null,
        );

        $input = self::mergeExtensionInput($coreInput, $languageMain, $languageRoot, $languageQuery, $extras);

        try {
            $this->mapper->apply($page, $input, detectChanges: false);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        if ((string) $page->alias === '') {
            $page->alias = $this->generateAlias((string) $title);
        }

        try {
            $page->save();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->bootVersions((int) $page->id)->create();

        $this->logGeneral(
            sprintf('Created page ID %d ("%s", type=%s, pid=%d) via MCP', (int) $page->id, $title, $type, $pid),
            __METHOD__,
        );

        return $this->serializer->summary($page) + ['created' => true];
    }

    // ──────────────────────────── update ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'page_update',
        description: 'Updates fields of an existing Contao page. Strict per-type validation: every field must match the resolved page type. Pass type= to switch the page to another type (validation uses the new type). Only fields you supply are changed (null = leave as is). Returns updated page + changed_fields list, wrapped in a Versions snapshot + tl_log entry.',
    )]
    public function update(
        int $id,
        ?int $pid = null,
        ?string $title = null,
        ?string $type = null,
        ?int $sorting = null,
        ?string $alias = null,
        ?bool $requireItem = null,
        ?int $routePriority = null,
        ?string $pageTitle = null,
        ?string $language = null,
        ?string $robots = null,
        ?string $description = null,
        ?string $canonicalLink = null,
        ?string $canonicalKeepParams = null,
        ?string $redirect = null,
        ?int $jumpTo = null,
        ?bool $redirectBack = null,
        ?bool $alwaysForward = null,
        ?bool $autoforward = null,
        ?string $url = null,
        ?bool $target = null,
        ?string $dns = null,
        ?bool $useSSL = null,
        ?string $urlPrefix = null,
        ?string $urlSuffix = null,
        ?string $validAliasCharacters = null,
        ?bool $useFolderUrl = null,
        ?bool $fallback = null,
        ?bool $disableLanguageRedirect = null,
        ?string $staticFiles = null,
        ?string $staticPlugins = null,
        ?string $favicon = null,
        ?string $robotsTxt = null,
        ?bool $maintenanceMode = null,
        ?string $mailerTransport = null,
        ?bool $enableCanonical = null,
        ?string $adminEmail = null,
        ?string $dateFormat = null,
        ?string $timeFormat = null,
        ?string $datimFormat = null,
        ?bool $protected = null,
        ?array $groups = null,
        ?bool $includeLayout = null,
        ?int $layout = null,
        ?int $subpageLayout = null,
        ?bool $includeCache = null,
        ?int $cache = null,
        ?int $clientCache = null,
        ?bool $alwaysLoadFromCache = null,
        ?bool $includeChmod = null,
        ?int $cuser = null,
        ?int $cgroup = null,
        ?array $chmod = null,
        ?string $cssClass = null,
        ?string $sitemap = null,
        ?string $searchIndexer = null,
        ?bool $hide = null,
        ?bool $guests = null,
        ?string $accesskey = null,
        ?bool $enforceTwoFactor = null,
        ?int $twoFactorJumpTo = null,
        ?bool $enableCsp = null,
        ?string $csp = null,
        ?bool $cspReportOnly = null,
        ?bool $cspReportLog = null,
        ?bool $published = null,
        ?string $start = null,
        ?string $stop = null,
        ?array $newsArchives = null,
        ?array $eventCalendars = null,
        ?string $feedFormat = null,
        ?string $feedSource = null,
        ?int $maxFeedItems = null,
        ?string $feedFeatured = null,
        ?string $feedDescription = null,
        ?int $feedRecurrenceLimit = null,
        ?string $imgSize = null,
        // ─── Extension: terminal42/contao-changelanguage ────────────────────
        ?int $languageMain = null,
        ?int $languageRoot = null,
        ?string $languageQuery = null,
        // ─── Generic extras ─────────────────────────────────────────────────
        #[Schema(type: 'object')] mixed $extras = null,
    ): array {
        $this->framework->initialize();

        $page = PageModel::findById($id);
        if ($page === null) {
            return ['error' => 'not_found', 'message' => "Page $id not found."];
        }

        if ($pid !== null && $pid !== 0 && PageModel::findById($pid) === null) {
            return ['error' => 'parent_not_found', 'message' => "Parent page $pid does not exist."];
        }

        $versions = $this->bootVersions($id);

        // alias === '' means "regenerate from current/new title"
        if ($alias === '') {
            $alias = $this->generateAlias((string) ($title ?? $page->title), $id);
        }

        $coreInput = array_filter(
            compact(
                'pid', 'title', 'type', 'sorting',
                'alias', 'requireItem', 'routePriority',
                'pageTitle', 'language', 'robots', 'description', 'canonicalLink', 'canonicalKeepParams',
                'redirect', 'jumpTo', 'redirectBack', 'alwaysForward', 'autoforward', 'url', 'target',
                'dns', 'useSSL', 'urlPrefix', 'urlSuffix', 'validAliasCharacters', 'useFolderUrl',
                'fallback', 'disableLanguageRedirect', 'staticFiles', 'staticPlugins',
                'favicon', 'robotsTxt', 'maintenanceMode', 'mailerTransport', 'enableCanonical',
                'adminEmail', 'dateFormat', 'timeFormat', 'datimFormat',
                'protected', 'groups',
                'includeLayout', 'layout', 'subpageLayout',
                'includeCache', 'cache', 'clientCache', 'alwaysLoadFromCache',
                'includeChmod', 'cuser', 'cgroup', 'chmod',
                'cssClass', 'sitemap', 'searchIndexer', 'hide', 'guests', 'accesskey',
                'enforceTwoFactor', 'twoFactorJumpTo',
                'enableCsp', 'csp', 'cspReportOnly', 'cspReportLog',
                'published', 'start', 'stop',
                'newsArchives', 'eventCalendars',
                'feedFormat', 'feedSource', 'maxFeedItems', 'feedFeatured', 'feedDescription',
                'feedRecurrenceLimit', 'imgSize',
            ),
            static fn ($v): bool => $v !== null,
        );

        $input = self::mergeExtensionInput($coreInput, $languageMain, $languageRoot, $languageQuery, $extras);

        try {
            $changed = $this->mapper->apply($page, $input, detectChanges: true);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        if ($changed === []) {
            return $this->serializer->summary($page) + [
                'updated' => false,
                'id' => (int) $page->id,
                'changed_fields' => [],
                'applied' => 0,
            ];
        }

        $page->tstamp = time();

        try {
            $page->save();
            $versions->create();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->logGeneral(
            sprintf('Updated page ID %d via MCP (fields: %s)', $id, implode(', ', $changed)),
            __METHOD__,
        );

        return $this->serializer->summary($page) + [
            'updated' => true,
            'id' => (int) $page->id,
            'changed_fields' => $changed,
            'applied' => \count($changed),
        ];
    }

    // ──────────────────────────── delete ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'page_delete',
        description: <<<'DESC'
            Deletes a Contao page. SAFE BY DEFAULT:
              - If the page has sub-pages, returns error "has_children" — delete or move
                them first. We do NOT cascade through the tree even with cascade=true, because
                that would silently nuke entire site sections.
              - If the page has articles, returns error "has_articles" unless cascade=true.
                With cascade=true, articles + their tl_content rows are cascaded.
              - If other entities (news archives, calendars, FAQ categories, member
                groups, forms, modules) reference this page as their jumpTo / redirect
                target, the tool returns "has_referrers" unless cascade=true. With
                cascade=true, those jumpTo columns are reset to 0 in the same transaction.
            Writes a Versions snapshot and tl_log entry.

            Requires confirm_destructive=true to proceed.
        DESC,
    )]
    public function delete(int $id, bool $confirm_destructive = false, bool $cascade = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'page_delete is irreversible. Pass confirm_destructive=true to proceed. cascade=true covers articles and jumpTo referrers, NOT sub-pages — use pages_delete_tree for a whole branch.',
            ];
        }

        $page = PageModel::findById($id);
        if ($page === null) {
            return ['error' => 'not_found', 'message' => "Page $id not found."];
        }

        $title = (string) $page->title;
        $childCount = (int) PageModel::countBy('pid', $id);

        if ($childCount > 0) {
            return [
                'error' => 'has_children',
                'message' => "Page $id has $childCount sub-pages. Delete or move them before removing this page.",
                'children_count' => $childCount,
            ];
        }

        $articleCount = (int) ArticleModel::countBy('pid', $id);

        if ($articleCount > 0 && !$cascade) {
            return [
                'error' => 'has_articles',
                'message' => "Page $id has $articleCount articles. Pass cascade=true to cascade them and their content elements.",
                'article_count' => $articleCount,
            ];
        }

        // Find all reverse jumpTo / redirect references. Leaving these dangling
        // breaks frontend URL generation (newsreader → "/news/0", calendar
        // reader → broken event links, etc.).
        $referrers = $this->findJumpToReferrers($id);
        $totalReferrers = array_sum($referrers);

        if ($totalReferrers > 0 && !$cascade) {
            return [
                'error' => 'has_referrers',
                'message' => sprintf(
                    'Page %d is referenced as jumpTo target by %d row(s) across %s. '
                    .'Pass cascade=true to reset those references to 0 (frontend links to '
                    .'this page will then break, but the rows survive).',
                    $id,
                    $totalReferrers,
                    implode(', ', array_keys(array_filter($referrers))),
                ),
                'referrers' => $referrers,
            ];
        }

        $cascadedArticles = 0;
        $cascadedContent = 0;
        $referrersReset = 0;

        $this->bootVersions($id);
        // Wrap the full cascade (articles + their tl_content + jumpTo
        // reference resets + the page itself) in a single DBAL transaction.
        // Without it, a mid-cascade failure leaves orphan rows — e.g. articles
        // gone but their content still referencing the dead article id, or
        // some referrer tables reset to jumpTo=0 while others still point at
        // a page that is or isn't there.
        $this->dbalRetry->transactional($this->connection, function () use ($id, $articleCount, $referrers, $page, &$cascadedArticles, &$cascadedContent, &$referrersReset): void {
            if ($articleCount > 0) {
                $articles = ArticleModel::findBy('pid', $id);
                foreach ($articles ?? [] as $article) {
                    $contentItems = ContentModel::findBy(['pid = ?', 'ptable = ?'], [(int) $article->id, 'tl_article']);
                    foreach ($contentItems ?? [] as $content) {
                        $content->delete();
                        ++$cascadedContent;
                    }
                    $article->delete();
                    ++$cascadedArticles;
                }
            }

            // Reset jumpTo references in the same logical operation as the delete.
            // We don't snapshot tl_version for these (Backend doesn't either when
            // a page is deleted via the page tree).
            foreach ($referrers as $table => $count) {
                if ($count === 0) {
                    continue;
                }
                $referrersReset += (int) $this->connection->executeStatement(
                    sprintf('UPDATE %s SET jumpTo = 0 WHERE jumpTo = ?', $this->connection->quoteIdentifier($table)),
                    [$id],
                );
            }

            $page->delete();
        });

        $this->logGeneral(
            sprintf(
                'Deleted page ID %d ("%s") via MCP — cascaded %d articles, %d content elements, reset %d jumpTo references',
                $id,
                $title,
                $cascadedArticles,
                $cascadedContent,
                $referrersReset,
            ),
            __METHOD__,
        );

        return [
            'deleted' => true,
            'id' => $id,
            'cascaded_articles' => $cascadedArticles,
            'cascaded_content_elements' => $cascadedContent,
            'reset_jumpto_references' => $referrersReset,
        ];
    }

    // ──────────────────────── multilingual ──────────────────────────

    /**
     * @param object|array<string, mixed> $translations Mapping {language_code: page_id},
     *                                                  e.g. {"de": 4, "fr": 7, "en": 9}.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'language_link_pages',
        description: <<<'DESC'
            Wires up Contao's multilingual page-linking in one call. Given the
            "default" page (typically the language=fallback root or a leaf
            page) and a `translations` object mapping language codes to page
            ids, every translation page gets its `languageMain` field set to
            `default_id`. This is what Contao uses to surface the language
            switcher on the frontend.

            Saves N-1 round-trips compared to calling `page_update` per
            translation, and validates upfront that all referenced pages
            exist before any write (atomic-ish — partial writes only on
            mid-loop SQL errors, which is rare).

            Parameters:
              - default_id:   page id that all translations point AT (their `languageMain`)
              - translations: object {"de": 4, "fr": 7, "en": 9}. The language
                              code is informational only — it must match the
                              page's `language` field, but we don't enforce
                              that strictly (warn instead).
              - reset_first:  if true (default false), each translation's
                              `languageMain` is reset to 0 before being set;
                              useful when re-linking after a tree restructure.

            Returns {linked: int, default_id, translations: {<lang>: <id>}, warnings: list<string>}.
        DESC,
    )]
    public function languageLinkPages(int $default_id, mixed $translations, bool $reset_first = false): array
    {
        $this->framework->initialize();

        // Normalise the translations parameter (stdClass vs assoc array).
        $map = match (true) {
            \is_object($translations) => get_object_vars($translations),
            \is_array($translations) && !array_is_list($translations) => $translations,
            default => null,
        };

        if ($map === null || $map === []) {
            return ['error' => 'invalid_input', 'message' => 'translations must be a non-empty JSON object {lang: page_id}.'];
        }

        $defaultPage = PageModel::findById($default_id);
        if ($defaultPage === null) {
            return ['error' => 'not_found', 'message' => "Default page $default_id not found."];
        }

        // Backend parity: a caller may only link pages they could edit in the
        // backend. The tl_page voter checks only the page TYPE, not pagemounts,
        // so we scope explicitly here — otherwise a non-admin could write
        // languageMain onto pages outside their mounts.
        if (!$this->guard->mayAccessRecord('tl_page', $defaultPage->row())) {
            return ['error' => 'permission_denied', 'message' => sprintf('You are not allowed to access default page %d (outside your page mounts).', $default_id)];
        }

        // Validate every translation up-front so we don't half-write.
        $resolved = [];
        $warnings = [];
        foreach ($map as $lang => $pageId) {
            if (!\is_string($lang) || trim($lang) === '') {
                return ['error' => 'invalid_input', 'message' => 'translations keys must be non-empty language strings.'];
            }
            if (!\is_int($pageId) && !(\is_string($pageId) && ctype_digit($pageId))) {
                return ['error' => 'invalid_input', 'message' => sprintf('translations["%s"] must be an integer page id.', $lang)];
            }
            $pageId = (int) $pageId;
            if ($pageId === $default_id) {
                $warnings[] = sprintf('Skipped "%s" → %d: cannot link a page to itself.', $lang, $pageId);
                continue;
            }
            $page = PageModel::findById($pageId);
            if ($page === null) {
                return ['error' => 'not_found', 'message' => sprintf('Translation page %d (language "%s") not found.', $pageId, $lang)];
            }
            if (!$this->guard->mayAccessRecord('tl_page', $page->row())) {
                return ['error' => 'permission_denied', 'message' => sprintf('You are not allowed to access translation page %d (language "%s") — it is outside your page mounts.', $pageId, $lang)];
            }
            if ((string) $page->language !== '' && (string) $page->language !== $lang) {
                $warnings[] = sprintf('Page %d has language="%s", expected "%s" — linked anyway.', $pageId, $page->language, $lang);
            }
            $resolved[$lang] = [$pageId, $page];
        }

        if ($resolved === []) {
            return [
                'linked' => 0,
                'default_id' => $default_id,
                'translations' => [],
                'warnings' => $warnings,
                'message' => 'All translations were skipped (self-references).',
            ];
        }

        $linked = 0;
        foreach ($resolved as $lang => [$pageId, $page]) {
            if ($reset_first) {
                $page->languageMain = 0;
                $page->tstamp = time();
                $this->bootVersions($pageId);
                $page->save();
                // refetch fresh state
                $page = PageModel::findById($pageId);
            }
            $page->languageMain = $default_id;
            $page->tstamp = time();
            $this->bootVersions($pageId);
            $page->save();
            ++$linked;
        }

        $this->logGeneral(sprintf(
            'language_link_pages: linked %d page(s) to default_id=%d (%s)',
            $linked,
            $default_id,
            implode(', ', array_map(static fn ($lang, $tuple) => sprintf('%s→%d', $lang, $tuple[0]), array_keys($resolved), array_values($resolved))),
        ), __METHOD__);

        return [
            'linked' => $linked,
            'default_id' => $default_id,
            'translations' => array_map(static fn ($t) => $t[0], $resolved),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'page_translations_tree',
        description: <<<'DESC'
            Returns the multilingual structure rooted at `root_id` (or ALL
            pages if root_id is omitted / 0 / null). Pages are grouped by
            their `languageMain` target so you can see translations at a
            glance:

              [
                { default: {id, language, title, alias},
                  translations: [{id, language, title, alias}, ...] },
                ...
              ]

            Pages whose `languageMain` is 0 OR whose target doesn't exist are
            treated as defaults. Useful before calling `language_link_pages`
            so you know the current binding state.

            Note: `root_id` is accepted as integer, null, or omitted entirely
            — all three mean "every page". Pass a positive id to limit the
            scan to direct children of that page.
        DESC,
    )]
    public function pageTranslationsTree(#[Schema(type: 'integer')] mixed $root_id = null): array
    {
        $this->framework->initialize();

        // Defensive: accept int, "1" string, null, 0, false, "" — anything
        // truthy-positive means "filter to that pid"; anything else means
        // "all pages". Sidesteps a php-mcp dispatcher edge case where the
        // bare-empty-args call `{}` ends up not matching `?int $x = null`
        // cleanly (observed in external audit 2026-05-22 nachmittags).
        $rootIdInt = (\is_int($root_id) || (\is_string($root_id) && ctype_digit(ltrim((string) $root_id, '-'))))
            ? (int) $root_id
            : 0;

        $criteria = [];
        $params = [];

        if ($rootIdInt > 0) {
            // Restrict to pages descendant from $root_id by limiting to the
            // root's own subtree: same `pid`-chain. We use a flat SQL fetch
            // for performance; trees can be large.
            $criteria[] = 'tl_page.pid = ?';
            $params[] = $rootIdInt;
        }

        $pages = PageModel::findBy(
            $criteria === [] ? null : $criteria,
            $params === [] ? null : $params,
            ['order' => 'tl_page.sorting'],
        );

        $byId = [];
        $defaults = [];
        $translations = [];

        // Same pagemount scoping as pages_list / pages_tree: never surface
        // translation links for pages the caller can't reach in the backend.
        $accessiblePages = $this->guard->accessiblePageIds();

        foreach ($pages ?? [] as $page) {
            $id = (int) $page->id;
            if ($accessiblePages !== null && !\in_array($id, $accessiblePages, true)) {
                continue; // outside the caller's pagemounts
            }
            $byId[$id] = $page;
            $langMain = (int) $page->languageMain;
            if ($langMain === 0 || $langMain === $id) {
                $defaults[$id] = $page;
            } else {
                $translations[$langMain][] = $page;
            }
        }

        // Defaults that have no translations are still listed (single-language pages).
        $groups = [];
        foreach ($defaults as $id => $default) {
            $kids = $translations[$id] ?? [];
            $groups[] = [
                'default' => $this->translationNode($default),
                'translations' => array_map(fn ($t) => $this->translationNode($t), $kids),
            ];
        }

        // Translations whose default isn't in the result set (e.g. user passed
        // root_id and the default lives elsewhere) → expose them as orphans.
        $orphans = [];
        foreach ($translations as $defaultId => $kids) {
            if (!isset($defaults[$defaultId])) {
                foreach ($kids as $t) {
                    $orphans[] = $this->translationNode($t) + ['orphaned_languageMain' => $defaultId];
                }
            }
        }

        return [
            'groups' => $groups,
            'orphans' => $orphans,
            'root_id' => $rootIdInt > 0 ? $rootIdInt : null,
            'count' => \count($groups),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function translationNode(PageModel $page): array
    {
        return [
            'id' => (int) $page->id,
            'pid' => (int) $page->pid,
            'language' => (string) $page->language,
            'title' => (string) $page->title,
            'alias' => (string) $page->alias,
            'type' => (string) $page->type,
            'published' => (bool) $page->published,
        ];
    }

    // ───────────────────────── private helpers ──────────────────────

    private function bootVersions(int $id): Versions
    {
        $versions = new Versions('tl_page', $id);
        $versions->setUsername($this->authorResolver->getLogUsername());
        $versions->setUserId($this->authorResolver->resolve());
        $versions->initialize();

        return $versions;
    }

    private function logGeneral(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::GENERAL, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }

    /**
     * Folds the explicit changelanguage parameters and the generic extras dict into the
     * core input. Resolution order (highest wins):
     *   1. core fields ($coreInput)
     *   2. explicit changelanguage parameters (languageMain/Root/Query)
     *   3. generic extras bag
     *
     * So the explicit params override extras for the same key — typed wins over typeless —
     * and core fields are untouchable through the extras channel.
     *
     * @param array<string, mixed> $coreInput
     *
     * @return array<string, mixed>
     */
    private static function mergeExtensionInput(
        array $coreInput,
        ?int $languageMain,
        ?int $languageRoot,
        ?string $languageQuery,
        mixed $extras,
    ): array {
        $explicit = array_filter(
            compact('languageMain', 'languageRoot', 'languageQuery'),
            static fn ($v): bool => $v !== null,
        );

        // php-mcp passes JSON objects through as PHP arrays (assoc) and JSON arrays
        // as PHP arrays (list). Both decode equivalently with (array) cast.
        $extrasArray = match (true) {
            \is_array($extras) => $extras,
            \is_object($extras) => (array) $extras,
            default => [],
        };

        // array_merge: right wins → core takes precedence over the others by being last.
        return array_merge($extrasArray, $explicit, $coreInput);
    }

    private function generateAlias(string $title, ?int $excludeId = null): string
    {
        $check = $excludeId === null
            ? static fn (string $value): bool => PageModel::findOneBy('alias', $value) !== null
            : static fn (string $value): bool => PageModel::findOneBy(['alias = ?', 'id != ?'], [$value, $excludeId]) !== null;

        return $this->slug->generate($title, ['validChars' => '0-9a-z'], $check);
    }

    /**
     * Recursively builds slim tree nodes for the children of $pid up to $remainingDepth.
     *
     * @return list<array<string, mixed>>
     */
    private function collectChildren(int $pid, int $remainingDepth): array
    {
        if ($remainingDepth <= 0) {
            return [];
        }

        $children = PageModel::findBy('pid', $pid, ['order' => 'tl_page.sorting']);

        $accessiblePages = $this->guard->accessiblePageIds();
        $nodes = [];
        foreach ($children ?? [] as $child) {
            if ($accessiblePages !== null && !\in_array((int) $child->id, $accessiblePages, true)) {
                continue; // prune branches outside the caller's pagemounts
            }
            $nodes[] = [
                'id' => (int) $child->id,
                'pid' => (int) $child->pid,
                'sorting' => (int) $child->sorting,
                'title' => (string) $child->title,
                'type' => (string) $child->type,
                'alias' => (string) $child->alias,
                'published' => (bool) $child->published,
                'children' => $this->collectChildren((int) $child->id, $remainingDepth - 1),
            ];
        }

        return $nodes;
    }

    /**
     * Hard cap on one tree. Sized against the measured ~30 ms per page: 200
     * pages is roughly 6 s of work, which still returns inside a caller's
     * patience. There are no progress notifications on this transport (POST
     * only, no SSE channel), so a long call is a black box until it answers —
     * the cap is what keeps that window short.
     */
    private const MAX_TREE_NODES = 200;

    /**
     * @param array<int, mixed> $pages
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'pages_create_tree',
        description: <<<'DESC'
            Creates a whole page tree in ONE call. Pass `pid` (0 for a new root) and
            `pages` as a LIST of nodes; a node may carry `children` with the same shape.

            Every field page_create accepts works per node (type, alias, language, dns,
            robots, …) — except `pid`, which comes from the node's position in the tree.
            `sorting` is filled in automatically per level in Contao's 128 increments;
            pass it explicitly only to override.

            Example:
              {"pid": 0, "pages": [
                {"title": "Leistungen", "type": "regular", "children": [
                  {"title": "Beratung", "type": "regular"}
                ]},
                {"title": "Kontakt", "type": "regular"}
              ]}

            Why: every separate tool call costs a full framework boot (~160 ms) on top of
            ~30 ms of actual work, plus a round-trip. A 25-page tree takes about a fifth
            of the server time this way.

            Shape errors (missing title/type, unknown field) are reported for the WHOLE
            tree before anything is written — nothing is created in that case. Runtime
            failures (alias collision, invalid value) can still happen mid-run: that node
            is reported with its error and its children are skipped, siblings continue.
            There is no transaction and no bulk undo — every page carries its own version
            entry, so a partial tree stays as it is. The per-node result lists what was
            created, so a retry can pick up instead of duplicating.

            Use `dry_run=true` to get the plan (paths, titles, computed sorting) without
            writing anything.
        DESC,
    )]
    public function createTree(int $pid, #[Schema(type: 'array')] mixed $pages, bool $dry_run = false): array
    {
        $this->framework->initialize();

        if (!\is_array($pages) || !array_is_list($pages) || $pages === []) {
            return ['error' => 'invalid_input', 'message' => '`pages` must be a non-empty JSON list of node objects.'];
        }

        // The create() signature IS the list of valid keys — deriving them from
        // it means the two cannot drift apart when a field is added there.
        $allowed = [];

        foreach ((new \ReflectionMethod($this, 'create'))->getParameters() as $p) {
            $allowed[] = $p->getName();
        }
        $allowed = array_values(array_diff($allowed, ['pid']));

        $problems = [];
        $count = 0;
        self::validateNodes($pages, '', $allowed, $problems, $count);

        if ($count > self::MAX_TREE_NODES) {
            return [
                'error' => 'too_many_pages',
                'message' => sprintf('%d pages exceed the limit of %d per call — split the tree.', $count, self::MAX_TREE_NODES),
            ];
        }

        if ($problems !== []) {
            // Reported before the first write: a typo in one node must not leave
            // half a tree behind.
            return [
                'error' => 'invalid_input',
                'message' => 'The tree was rejected before anything was created.',
                'problems' => $problems,
                'allowed_fields' => $allowed,
            ];
        }

        if ($dry_run) {
            $plan = [];
            self::planNodes($pages, '', $plan);

            return ['dry_run' => true, 'pages' => $count, 'plan' => $plan];
        }

        $result = ['created' => 0, 'failed' => 0, 'skipped' => 0, 'pages' => []];
        $this->createNodes($pages, $pid, '', $result);

        return $result;
    }

    /**
     * @param array<int, mixed>          $nodes
     * @param list<string>               $allowed
     * @param list<array<string, mixed>> $problems
     */
    private static function validateNodes(array $nodes, string $path, array $allowed, array &$problems, int &$count): void
    {
        foreach ($nodes as $i => $node) {
            $here = $path === '' ? (string) ($i + 1) : $path.'.'.($i + 1);
            ++$count;

            if (!\is_array($node)) {
                $problems[] = ['path' => $here, 'error' => 'node must be an object'];
                continue;
            }

            foreach (['title', 'type'] as $required) {
                if (!isset($node[$required]) || !\is_string($node[$required]) || trim($node[$required]) === '') {
                    $problems[] = ['path' => $here, 'error' => sprintf('%s is required and must be a non-empty string', $required)];
                }
            }

            foreach (array_keys($node) as $key) {
                if ($key === 'children' || \in_array($key, $allowed, true)) {
                    continue;
                }
                $problems[] = ['path' => $here, 'error' => sprintf('unknown field "%s"', $key)];
            }

            $children = $node['children'] ?? [];
            if ($children !== [] && (!\is_array($children) || !array_is_list($children))) {
                $problems[] = ['path' => $here, 'error' => 'children must be a list of node objects'];
                continue;
            }
            if (\is_array($children) && $children !== []) {
                self::validateNodes($children, $here, $allowed, $problems, $count);
            }
        }
    }

    /**
     * @param array<int, mixed>          $nodes
     * @param list<array<string, mixed>> $plan
     */
    private static function planNodes(array $nodes, string $path, array &$plan): void
    {
        $sorting = 128;

        foreach ($nodes as $i => $node) {
            $here = $path === '' ? (string) ($i + 1) : $path.'.'.($i + 1);
            $plan[] = [
                'path' => $here,
                'title' => (string) $node['title'],
                'type' => (string) $node['type'],
                'sorting' => $node['sorting'] ?? $sorting,
            ];
            $sorting += 128;

            if (!empty($node['children'])) {
                self::planNodes($node['children'], $here, $plan);
            }
        }
    }

    /**
     * @param array<int, mixed>    $nodes
     * @param array<string, mixed> $result
     */
    private function createNodes(array $nodes, int $parentId, string $path, array &$result): void
    {
        $sorting = 128;

        foreach ($nodes as $i => $node) {
            $here = $path === '' ? (string) ($i + 1) : $path.'.'.($i + 1);
            $children = $node['children'] ?? [];
            unset($node['children']);

            $args = $node + ['pid' => $parentId, 'sorting' => $sorting];
            $sorting += 128;

            try {
                /** @var array<string, mixed> $created */
                $created = $this->create(...$args);
            } catch (\Throwable $e) {
                $created = ['error' => 'exception', 'message' => $e->getMessage()];
            }

            if (!isset($created['id'])) {
                ++$result['failed'];
                $skipped = self::countNodes($children);
                $result['skipped'] += $skipped;
                $result['pages'][] = [
                    'path' => $here,
                    'title' => (string) $node['title'],
                    'error' => $created['error'] ?? 'unknown',
                    'message' => $created['message'] ?? '',
                    // Children are skipped rather than reparented: a page whose
                    // parent does not exist would silently land somewhere else.
                    'skipped_children' => $skipped,
                ];
                continue;
            }

            ++$result['created'];
            $id = (int) $created['id'];
            $result['pages'][] = [
                'path' => $here,
                'id' => $id,
                'title' => (string) $created['title'],
                'pid' => $parentId,
                'alias' => $created['alias'] ?? null,
            ];

            if (\is_array($children) && $children !== []) {
                $this->createNodes($children, $id, $here, $result);
            }
        }
    }

    /**
     * @param array<int, mixed>|mixed $nodes
     */
    private static function countNodes(mixed $nodes): int
    {
        if (!\is_array($nodes)) {
            return 0;
        }

        $n = 0;
        foreach ($nodes as $node) {
            ++$n;
            if (\is_array($node) && !empty($node['children'])) {
                $n += self::countNodes($node['children']);
            }
        }

        return $n;
    }


    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'pages_delete_tree',
        description: <<<'DESC'
            Deletes a page and everything below it, deepest first. The counterpart to
            pages_create_tree: page_delete refuses to cascade through the tree on purpose,
            so undoing a 25-page structure meant 25 confirmed calls in the right order.

            Two locks, because this can remove a whole site section:
              1. confirm_destructive=true, as with every destructive tool.
              2. expect_pages must match the number of pages actually below (and
                 including) `id`. Call without it — or with dry_run=true — and the tool
                 answers with the count and the list it WOULD delete, deleting nothing.
                 The second call then carries that number. A structure that grew since
                 you looked no longer matches, and the call fails instead of taking more
                 than you meant.

            `cascade` is handed to each page: with it, articles and their content are
            removed and jumpTo references pointing at those pages are reset. Without it a
            page holding articles or referrers stops the run — its ancestors then stay too,
            since Contao will not delete a page that still has children.

            Every page keeps its own version snapshot and undo entry: there is no bulk
            undo, but nothing is lost silently either. Capped at 200 pages per call.
        DESC,
    )]
    public function deleteTree(
        int $id,
        bool $confirm_destructive = false,
        int $expect_pages = 0,
        bool $cascade = false,
        bool $dry_run = false,
    ): array {
        $this->framework->initialize();

        $root = PageModel::findByPk($id);
        if ($root === null) {
            return ['error' => 'not_found', 'message' => sprintf('No page with id %d', $id)];
        }

        // Deepest first: Contao refuses to delete a page that still has
        // children, so the order is not a preference but a requirement.
        $ordered = $this->collectSubtreeDeepestFirst($id);
        $count = \count($ordered);

        if ($count > self::MAX_TREE_NODES) {
            return [
                'error' => 'too_many_pages',
                'message' => sprintf('%d pages exceed the limit of %d per call — delete sub-sections first.', $count, self::MAX_TREE_NODES),
            ];
        }

        $preview = array_map(
            static fn (array $p): array => ['id' => $p['id'], 'title' => $p['title'], 'depth' => $p['depth']],
            $ordered,
        );

        if ($dry_run || $expect_pages === 0) {
            return [
                'dry_run' => true,
                'pages' => $count,
                'expect_pages' => $count,
                'would_delete' => $preview,
                'message' => sprintf(
                    'Nothing was deleted. Re-call with expect_pages=%d and confirm_destructive=true to proceed.',
                    $count,
                ),
            ];
        }

        if ($expect_pages !== $count) {
            // The tree changed between looking and acting, or the caller
            // guessed. Either way it now means something different than it did.
            return [
                'error' => 'count_mismatch',
                'message' => sprintf(
                    'expect_pages=%d, but %d pages are below and including id %d. Re-check with dry_run=true.',
                    $expect_pages,
                    $count,
                    $id,
                ),
                'pages' => $count,
            ];
        }

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => sprintf('This would delete %d pages irreversibly. Pass confirm_destructive=true to proceed.', $count),
                'pages' => $count,
            ];
        }

        $result = ['deleted' => 0, 'failed' => 0, 'pages' => []];

        foreach ($ordered as $page) {
            $single = $this->delete((int) $page['id'], confirm_destructive: true, cascade: $cascade);

            if (($single['deleted'] ?? false) === true) {
                ++$result['deleted'];
                $result['pages'][] = ['id' => $page['id'], 'title' => $page['title'], 'deleted' => true];
                continue;
            }

            ++$result['failed'];
            $result['pages'][] = [
                'id' => $page['id'],
                'title' => $page['title'],
                'error' => $single['error'] ?? 'unknown',
                'message' => $single['message'] ?? '',
            ];
        }

        // A parent surviving because a child refused is the expected shape of a
        // partial run, not a second failure — say so rather than leaving the
        // caller to work it out from the list.
        if ($result['failed'] > 0) {
            $result['note'] = 'Pages above a failed one were kept: Contao does not delete a page that still has children. Resolve the reported errors and repeat.';
        }

        $this->logGeneral(sprintf('Deleted page tree below id=%d (%d pages, %d failed)', $id, $result['deleted'], $result['failed']), __METHOD__);

        return $result;
    }

    /**
     * Every page below and including $id, children before their parents.
     *
     * Walks level by level rather than with a recursive CTE: MySQL 5.7 has no
     * CTEs and the bundle supports what Contao supports.
     *
     * @return list<array{id: int, title: string, depth: int}>
     */
    private function collectSubtreeDeepestFirst(int $id): array
    {
        $levels = [];
        $currentIds = [$id];
        $depth = 0;

        while ($currentIds !== [] && $depth <= 50) {
            $rows = $this->connection->fetchAllAssociative(
                sprintf('SELECT id, title FROM tl_page WHERE %s ORDER BY sorting', 0 === $depth ? 'id = ?' : 'pid IN (?)'),
                0 === $depth ? [$id] : [$currentIds],
                0 === $depth ? [] : [ArrayParameterType::INTEGER],
            );

            if ($rows === []) {
                break;
            }

            $levels[$depth] = array_map(
                static fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title']],
                $rows,
            );

            $currentIds = array_map(static fn (array $r): int => $r['id'], $levels[$depth]);
            ++$depth;
        }

        $ordered = [];
        foreach (array_reverse($levels, true) as $level => $rows) {
            foreach ($rows as $row) {
                $ordered[] = $row + ['depth' => $level];
            }
        }

        return $ordered;
    }

}
