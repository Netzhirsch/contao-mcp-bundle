<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\News;

use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\CoreBundle\Slug\Slug;
use Contao\NewsArchiveModel;
use Contao\NewsModel;
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
 * MCP facade for tl_news. Five operations exposed as separate tools via #[McpTool]:
 *   news_list, news_get, news_create, news_update, news_delete.
 *
 * All write operations wrap themselves in a Contao Versions snapshot and write to tl_log
 * via Monolog + ContaoContext so the backend history reflects MCP-driven changes.
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
        private readonly QueryFilterResolver $filterResolver,
        private readonly Connection $connection,
        private readonly McpPermissionGuard $guard,
    ) {
    }

    // ───────────────────────────── list ─────────────────────────────

    /**
     * @param object|null $filters Optional structured filter map, e.g.
     *                             `{"published": true, "pid": [5, 7]}`. Only
     *                             columns marked `'filter' => true` in the DCA
     *                             are allowed — call `entity_query_options("tl_news")`
     *                             to discover the full set.
     *
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'news_list',
        description: <<<'DESC'
            Lists Contao news entries (newest first).

            Filters:
              - archive_id          — restrict to one news archive (legacy convenience param)
              - q                   — LIKE-search across DCA-searchable fields (headline,
                                      alias, teaser, author — varies per Contao install)
              - filters             — structured equality map. Keys MUST be DCA-filterable
                                      columns. Scalar = `col = val`, list = `col IN (...)`,
                                      null = `col IS NULL`. Use `entity_query_options("tl_news")`
                                      to discover allowed keys + value types.
              - updated_after       — Unix timestamp or ISO-8601 (e.g. "2024-12-31"). Limits
                                      results to news whose `tstamp` is at or after this point.
              - updated_before      — analogous upper bound
              - include_unpublished — DEFAULT true (MCP also returns unpublished/disabled);
                                      pass false for published-only + within start/stop window
        DESC,
    )]
    public function list(
        ?int $archive_id = null,
        int $limit = 20,
        int $offset = 0,
        bool $include_unpublished = true,
        ?string $q = null,
        #[Schema(type: 'object')] mixed $filters = null,
        ?string $updated_after = null,
        ?string $updated_before = null,
    ): array {
        $this->framework->initialize();

        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $columns = [];
        $values = [];

        if ($archive_id !== null) {
            $columns[] = 'tl_news.pid = ?';
            $values[] = $archive_id;
        }

        if (!$include_unpublished) {
            $time = time();
            $columns[] = 'tl_news.published = ?';
            $values[] = '1';
            $columns[] = "(tl_news.start = '' OR tl_news.start <= ?)";
            $values[] = (string) $time;
            $columns[] = "(tl_news.stop = '' OR tl_news.stop > ?)";
            $values[] = (string) $time;
        }

        // Full-text search across DCA-searchable fields.
        $search = $this->filterResolver->buildSearchClause('tl_news', $q);
        if ($search !== null) {
            $columns[] = $search['clause'];
            $values = array_merge($values, $search['params']);
        }

        // Structured filters (DCA-validated).
        try {
            $filtersArr = $this->filterResolver->normaliseFilters($filters);
            $filterRes = $this->filterResolver->buildFilterClauses('tl_news', $filtersArr);
            $columns = array_merge($columns, $filterRes['clauses']);
            $values = array_merge($values, $filterRes['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_filter', 'message' => $e->getMessage()];
        }

        // tstamp range.
        try {
            $tstampRes = $this->filterResolver->buildTstampRange('tl_news', $updated_after, $updated_before);
            $columns = array_merge($columns, $tstampRes['clauses']);
            $values = array_merge($values, $tstampRes['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $items = NewsModel::findBy(
            $columns ?: null,
            $values ?: null,
            ['order' => 'tl_news.date DESC', 'limit' => $limit, 'offset' => $offset],
        );

        $serialized = [];
        foreach ($items ?? [] as $news) {
            if (!$this->guard->mayRead('tl_news', $news->row())) {
                continue; // only news the caller may read in the backend
            }
            $serialized[] = $this->serializer->summary($news);
        }

        return [
            'items' => $serialized,
            'count' => \count($serialized),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    // ───────────────────────────── get ──────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'news_get',
        description: 'Returns a single Contao news entry by id, including its content elements (text/headline/html/code/list/table). Returns an error object if the entry is missing or, without include_unpublished, not currently public.',
    )]
    public function get(int $id, bool $include_unpublished = true): array
    {
        $this->framework->initialize();

        $news = NewsModel::findById($id);
        if ($news === null) {
            return ['error' => 'not_found', 'message' => "News with id $id not found."];
        }

        if (!$include_unpublished && !self::isCurrentlyPublic($news)) {
            return ['error' => 'not_public', 'message' => "News $id is not currently published."];
        }

        return $this->serializer->summary($news) + [
            'source' => (string) $news->source,
            'content_elements' => $this->collectContentElements((int) $news->id, $include_unpublished),
        ];
    }

    // ──────────────────────────── create ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'news_create',
        description: <<<'DESC'
            Creates a new Contao news entry. The only required arguments are archive_id and
            headline; every other DCA-editable field is optional and matches the Contao
            backend by name. Conventions:

              - date: YYYY-MM-DD; defaults to today.
              - time: HH:MM:SS; defaults to current time. Internally Contao stores date and
                time as the same combined unix timestamp (see tl_news::adjustTime).
              - start, stop: full ISO 8601 datetime (e.g. 2026-06-01T00:00:00) or empty to clear.
              - alias: auto-generated from headline via Contao's Slug service when omitted.
              - author_id: defaults to the bundle config netzhirsch_contao_mcp.write.default_author_id,
                with a fallback to the lowest-id admin user.
              - source: one of "default", "internal", "article", "external". Pair with jumpTo /
                articleId / url respectively.
              - floating: one of "above", "left", "right", "below" (image floating).
              - robots: one of "index,follow", "index,nofollow", "noindex,follow", "noindex,nofollow".
              - searchIndexer: "always_index" or "never_index".
              - singleSRC: 32-char hex UUID or UUID-with-dashes referencing a tl_files entry.
              - All booleans (featured, addImage, overwriteMeta, fullsize, addEnclosure, target,
                published) take true/false.

            On success the new entry is wrapped in a Versions snapshot so it appears in the
            backend history, and a tl_log entry is written.
        DESC,
    )]
    public function create(
        int $archive_id,
        string $headline,
        ?bool $featured = null,
        ?string $alias = null,
        ?int $author_id = null,
        ?string $date = null,
        ?string $time = null,
        ?string $source = null,
        ?int $jumpTo = null,
        ?int $articleId = null,
        ?string $url = null,
        ?bool $target = null,
        ?string $linkText = null,
        ?string $canonicalLink = null,
        ?string $pageTitle = null,
        ?string $robots = null,
        ?string $description = null,
        ?string $subheadline = null,
        ?string $teaser = null,
        ?bool $addImage = null,
        ?bool $overwriteMeta = null,
        ?string $singleSRC = null,
        ?string $alt = null,
        ?string $imageTitle = null,
        ?string $imageUrl = null,
        ?bool $fullsize = null,
        ?string $caption = null,
        ?string $floating = null,
        ?bool $addEnclosure = null,
        ?string $cssClass = null,
        ?string $searchIndexer = null,
        ?bool $published = null,
        ?string $start = null,
        ?string $stop = null,
        // ─── Extension: terminal42/contao-changelanguage ────────────────────
        // Only accepted when that bundle is installed; otherwise the tool returns
        // error "extension_not_available". Call installed_bundles to verify.
        ?int $languageMain = null,
        // ─── Generic extras for any other field-provider extension ──────────
        // Pass a JSON object like {"someField": 42}; routed through the matching
        // provider, unknown keys → error.
        #[Schema(type: 'object')] mixed $extras = null,
    ): array {
        $this->framework->initialize();

        if (NewsArchiveModel::findById($archive_id) === null) {
            return ['error' => 'archive_not_found', 'message' => "News archive $archive_id does not exist."];
        }

        $news = new NewsModel();
        $news->pid = $archive_id;
        $news->tstamp = time();
        $news->headline = $headline;
        $news->alias = '';
        $news->author = 0;
        $news->date = strtotime('today midnight') ?: time();
        $news->time = time();
        $news->source = 'default';
        $news->floating = 'above';
        foreach (['published', 'featured', 'addImage', 'overwriteMeta', 'fullsize', 'addEnclosure', 'target'] as $boolField) {
            $news->$boolField = 0;
        }

        $coreInput = array_filter(
            [
                'archive_id' => $archive_id,
                'headline' => $headline,
                'featured' => $featured,
                'alias' => $alias,
                'author_id' => $author_id,
                'date' => $date ?? date('Y-m-d'),
                'time' => $time ?? date('H:i:s'),
                'source' => $source,
                'jumpTo' => $jumpTo,
                'articleId' => $articleId,
                'url' => $url,
                'target' => $target,
                'linkText' => $linkText,
                'canonicalLink' => $canonicalLink,
                'pageTitle' => $pageTitle,
                'robots' => $robots,
                'description' => $description,
                'subheadline' => $subheadline,
                'teaser' => $teaser,
                'addImage' => $addImage,
                'overwriteMeta' => $overwriteMeta,
                'singleSRC' => $singleSRC,
                'alt' => $alt,
                'imageTitle' => $imageTitle,
                'imageUrl' => $imageUrl,
                'fullsize' => $fullsize,
                'caption' => $caption,
                'floating' => $floating,
                'addEnclosure' => $addEnclosure,
                'cssClass' => $cssClass,
                'searchIndexer' => $searchIndexer,
                'published' => $published,
                'start' => $start,
                'stop' => $stop,
            ],
            static fn ($v): bool => $v !== null,
        );

        $input = self::mergeExtensionInput($coreInput, $languageMain, $extras);

        try {
            $this->mapper->apply($news, $input, detectChanges: false);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        if ((int) $news->author === 0) {
            $news->author = $this->authorResolver->resolve();
        }

        if ((string) $news->alias === '') {
            $news->alias = $this->generateAlias($headline);
        }

        try {
            $news->save();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->bootVersions((int) $news->id)->create();

        $this->logGeneral(
            sprintf('Created news ID %d ("%s") in archive %d via MCP', (int) $news->id, $headline, $archive_id),
            __METHOD__,
        );

        return $this->serializer->summary($news) + ['created' => true];
    }

    // ──────────────────────────── update ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'news_update',
        description: <<<'DESC'
            Updates fields of an existing Contao news entry by id. Every DCA-editable field
            from the backend is accepted (see news_create for naming, formats and allowed
            values). Only fields you pass are changed — null means "leave as is", empty
            string clears a string column or normalises an alias back to an auto-slug.

            Wraps the change in Versions::initialize()/create() so the backend shows it in
            history, writes a tl_log entry, and returns the updated news plus a
            changed_fields list. If no field actually changes value, returns the same
            payload with updated:false and applied:0 instead of touching the record.
        DESC,
    )]
    public function update(
        int $id,
        ?int $archive_id = null,
        ?string $headline = null,
        ?bool $featured = null,
        ?string $alias = null,
        ?int $author_id = null,
        ?string $date = null,
        ?string $time = null,
        ?string $source = null,
        ?int $jumpTo = null,
        ?int $articleId = null,
        ?string $url = null,
        ?bool $target = null,
        ?string $linkText = null,
        ?string $canonicalLink = null,
        ?string $pageTitle = null,
        ?string $robots = null,
        ?string $description = null,
        ?string $subheadline = null,
        ?string $teaser = null,
        ?bool $addImage = null,
        ?bool $overwriteMeta = null,
        ?string $singleSRC = null,
        ?string $alt = null,
        ?string $imageTitle = null,
        ?string $imageUrl = null,
        ?bool $fullsize = null,
        ?string $caption = null,
        ?string $floating = null,
        ?bool $addEnclosure = null,
        ?string $cssClass = null,
        ?string $searchIndexer = null,
        ?bool $published = null,
        ?string $start = null,
        ?string $stop = null,
        // ─── Extension: terminal42/contao-changelanguage ────────────────────
        ?int $languageMain = null,
        // ─── Generic extras ─────────────────────────────────────────────────
        #[Schema(type: 'object')] mixed $extras = null,
    ): array {
        $this->framework->initialize();

        $news = NewsModel::findById($id);
        if ($news === null) {
            return ['error' => 'not_found', 'message' => "News with id $id not found."];
        }

        if ($archive_id !== null && NewsArchiveModel::findById($archive_id) === null) {
            return ['error' => 'archive_not_found', 'message' => "Archive $archive_id does not exist."];
        }

        $versions = $this->bootVersions($id);

        // alias === '' means "regenerate from current headline"
        if ($alias === '') {
            $alias = $this->generateAlias((string) ($headline ?? $news->headline), $id);
        }

        $coreInput = array_filter(
            [
                'archive_id' => $archive_id,
                'headline' => $headline,
                'featured' => $featured,
                'alias' => $alias,
                'author_id' => $author_id,
                'date' => $date,
                'time' => $time,
                'source' => $source,
                'jumpTo' => $jumpTo,
                'articleId' => $articleId,
                'url' => $url,
                'target' => $target,
                'linkText' => $linkText,
                'canonicalLink' => $canonicalLink,
                'pageTitle' => $pageTitle,
                'robots' => $robots,
                'description' => $description,
                'subheadline' => $subheadline,
                'teaser' => $teaser,
                'addImage' => $addImage,
                'overwriteMeta' => $overwriteMeta,
                'singleSRC' => $singleSRC,
                'alt' => $alt,
                'imageTitle' => $imageTitle,
                'imageUrl' => $imageUrl,
                'fullsize' => $fullsize,
                'caption' => $caption,
                'floating' => $floating,
                'addEnclosure' => $addEnclosure,
                'cssClass' => $cssClass,
                'searchIndexer' => $searchIndexer,
                'published' => $published,
                'start' => $start,
                'stop' => $stop,
            ],
            static fn ($v): bool => $v !== null,
        );

        $input = self::mergeExtensionInput($coreInput, $languageMain, $extras);

        try {
            $changed = $this->mapper->apply($news, $input, detectChanges: true);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        if ($changed === []) {
            return $this->serializer->summary($news) + [
                'updated' => false,
                'id' => (int) $news->id,
                'changed_fields' => [],
                'applied' => 0,
            ];
        }

        $news->tstamp = time();

        try {
            $news->save();
            $versions->create();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->logGeneral(
            sprintf('Updated news ID %d via MCP (fields: %s)', $id, implode(', ', $changed)),
            __METHOD__,
        );

        return $this->serializer->summary($news) + [
            'updated' => true,
            'id' => (int) $news->id,
            'changed_fields' => $changed,
            'applied' => \count($changed),
        ];
    }

    // ──────────────────────────── delete ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'news_delete',
        description: 'Deletes a Contao news entry and cascades to its content elements (tl_content rows with ptable=tl_news). Wraps the delete in a Versions snapshot so the backend keeps a record, and writes to tl_log. Returns the deleted id and the number of removed content elements. Requires confirm_destructive=true to proceed.',
    )]
    public function delete(int $id, bool $confirm_destructive = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'news_delete is irreversible. Pass confirm_destructive=true to proceed.',
            ];
        }

        $news = NewsModel::findById($id);
        if ($news === null) {
            return ['error' => 'not_found', 'message' => "News with id $id not found."];
        }

        $headline = (string) $news->headline;

        $this->bootVersions($id);

        // Cascade content-elements + news in one DBAL transaction so a
        // mid-cascade failure can't leave orphan tl_content rows pointing at
        // a deleted news entry.
        $contentCount = 0;
        $this->dbalRetry->transactional($this->connection, function () use ($id, $news, &$contentCount) {
            $contentCount = $this->deleteContentElementsOfNews($id);
            $news->delete();
        });

        $this->logGeneral(
            sprintf('Deleted news ID %d ("%s") and %d content element(s) via MCP', $id, $headline, $contentCount),
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
     * Folds the explicit changelanguage parameter and the generic extras dict into the
     * core input. Resolution order (highest wins):
     *   1. core fields ($coreInput)
     *   2. explicit changelanguage parameter (languageMain)
     *   3. generic extras bag
     *
     * @param array<string, mixed> $coreInput
     *
     * @return array<string, mixed>
     */
    private static function mergeExtensionInput(
        array $coreInput,
        ?int $languageMain,
        mixed $extras,
    ): array {
        $explicit = array_filter(
            ['languageMain' => $languageMain],
            static fn ($v): bool => $v !== null,
        );

        // php-mcp passes JSON objects through as PHP assoc arrays.
        $extrasArray = match (true) {
            \is_array($extras) => $extras,
            \is_object($extras) => (array) $extras,
            default => [],
        };

        return array_merge($extrasArray, $explicit, $coreInput);
    }

    /**
     * Creates a Versions handle for tl_news, sets MCP attribution, and calls initialize().
     * Return value is the handle the caller can invoke ->create() on after a save().
     */
    private function bootVersions(int $id): Versions
    {
        $versions = new Versions('tl_news', $id);
        $versions->setUsername($this->authorResolver->getLogUsername());
        $versions->setUserId($this->authorResolver->resolve());
        $versions->initialize();

        return $versions;
    }

    private function logGeneral(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::GENERAL, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }

    private function generateAlias(string $headline, ?int $excludeId = null): string
    {
        $check = $excludeId === null
            ? static fn (string $value): bool => NewsModel::findOneBy('alias', $value) !== null
            : static fn (string $value): bool => NewsModel::findOneBy(['alias = ?', 'id != ?'], [$value, $excludeId]) !== null;

        return $this->slug->generate($headline, ['validChars' => '0-9a-z'], $check);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectContentElements(int $newsId, bool $includeUnpublished): array
    {
        $columns = ['tl_content.pid = ?', "tl_content.ptable = 'tl_news'"];
        $values = [$newsId];

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
                'text' => self::extractText($el),
            ];
        }

        return $out;
    }

    /**
     * Removes all tl_content rows belonging to the given news entry. Returns the count.
     */
    private function deleteContentElementsOfNews(int $newsId): int
    {
        $elements = ContentModel::findBy(['pid = ?', 'ptable = ?'], [$newsId, 'tl_news']);
        $count = 0;
        foreach ($elements ?? [] as $element) {
            $element->delete();
            ++$count;
        }

        return $count;
    }

    private static function extractText(ContentModel $el): ?string
    {
        return match ((string) $el->type) {
            'text', 'list', 'table' => (string) $el->text,
            'html' => (string) $el->html,
            'code' => (string) $el->code,
            default => null,
        };
    }

    private static function isCurrentlyPublic(NewsModel $n): bool
    {
        if ((int) $n->published !== 1) {
            return false;
        }
        $time = time();
        if ($n->start && (int) $n->start > $time) {
            return false;
        }
        if ($n->stop && (int) $n->stop <= $time) {
            return false;
        }

        return true;
    }
}
