<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\NewsArchive;

use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\NewsArchiveModel;
use Contao\NewsModel;
use Contao\PageModel;
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
 * MCP facade for tl_news_archive. Five operations exposed via #[McpTool]:
 *   news_archives_list, news_archive_get, news_archive_create,
 *   news_archive_update, news_archive_delete.
 *
 * Delete is safe-by-default: refuses to remove a non-empty archive unless force=true,
 * in which case it cascades into tl_news (and each news's tl_content rows).
 */
final class Tool
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly DbalRetry $dbalRetry,
        private readonly FieldMapper $mapper,
        private readonly QueryFilterResolver $filterResolver,
        private readonly Connection $connection,
        private readonly McpPermissionGuard $guard,
    ) {
    }

    // ───────────────────────────── list ─────────────────────────────

    /**
     * @param object|null $filters DCA-validated filter map. See entity_query_options("tl_news_archive").
     *
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'news_archives_list',
        description: 'Lists Contao news archives (tl_news_archive), alphabetised by title. Use this to discover which archive_id values can be passed to news_create. q does LIKE-search across DCA-searchable fields; filters is a DCA-validated equality map; updated_after/before take Unix-ts or ISO-8601.',
    )]
    public function list(
        int $limit = 50,
        int $offset = 0,
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

        $search = $this->filterResolver->buildSearchClause('tl_news_archive', $q);
        if ($search !== null) {
            $columns[] = $search['clause'];
            $values = array_merge($values, $search['params']);
        }
        try {
            $filtersArr = $this->filterResolver->normaliseFilters($filters);
            $fr = $this->filterResolver->buildFilterClauses('tl_news_archive', $filtersArr);
            $columns = array_merge($columns, $fr['clauses']);
            $values = array_merge($values, $fr['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_filter', 'message' => $e->getMessage()];
        }
        try {
            $ts = $this->filterResolver->buildTstampRange('tl_news_archive', $updated_after, $updated_before);
            $columns = array_merge($columns, $ts['clauses']);
            $values = array_merge($values, $ts['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $items = NewsArchiveModel::findBy(
            $columns === [] ? null : $columns,
            $values === [] ? null : $values,
            ['order' => 'tl_news_archive.title', 'limit' => $limit, 'offset' => $offset],
        );

        $serialized = [];
        foreach ($items ?? [] as $archive) {
            if (!$this->guard->mayRead('tl_news_archive', $archive->row())) {
                continue;
            }
            $serialized[] = Serializer::summary($archive);
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
        name: 'news_archive_get',
        description: 'Returns a single Contao news archive by id, plus the count of news entries it currently holds. Useful before delete to know what would cascade.',
    )]
    public function get(int $id): array
    {
        $this->framework->initialize();

        $archive = NewsArchiveModel::findById($id);
        if ($archive === null) {
            return ['error' => 'not_found', 'message' => "News archive $id not found."];
        }

        return Serializer::summary($archive) + ['news_count' => (int) NewsModel::countBy('pid', $id)];
    }

    // ──────────────────────────── create ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'news_archive_create',
        description: <<<'DESC'
            Creates a new Contao news archive (tl_news_archive). Required: title (display name)
            and jumpTo (tl_page.id of the reader/detail page). Optional: protected (true to
            restrict to specific member groups) and groups (list of tl_member_group.id when
            protected is true).

            On success, writes a Versions snapshot and a tl_log entry. The new archive can
            immediately be referenced via its returned id as archive_id in news_create.
        DESC,
    )]
    public function create(
        string $title,
        int $jumpTo,
        ?bool $protected = null,
        ?array $groups = null,
    ): array {
        $this->framework->initialize();

        if (PageModel::findById($jumpTo) === null) {
            return ['error' => 'page_not_found', 'message' => "jumpTo page $jumpTo does not exist."];
        }

        $archive = new NewsArchiveModel();
        $archive->tstamp = time();
        $archive->title = '';
        $archive->jumpTo = 0;
        $archive->protected = 0;
        $archive->groups = '';

        $input = array_filter(
            [
                'title' => $title,
                'jumpTo' => $jumpTo,
                'protected' => $protected,
                'groups' => $groups,
            ],
            static fn ($v): bool => $v !== null,
        );

        try {
            $this->mapper->apply($archive, $input, detectChanges: false);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        try {
            $archive->save();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->bootVersions((int) $archive->id)->create();

        $this->logGeneral(
            sprintf('Created news archive ID %d ("%s") via MCP', (int) $archive->id, $title),
            __METHOD__,
        );

        return Serializer::summary($archive) + ['created' => true];
    }

    // ──────────────────────────── update ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'news_archive_update',
        description: 'Updates a Contao news archive (tl_news_archive). Only fields you pass are changed (null = leave as is). Returns the updated archive plus a changed_fields list, wrapped in a Versions snapshot + tl_log entry.',
    )]
    public function update(
        int $id,
        ?string $title = null,
        ?int $jumpTo = null,
        ?bool $protected = null,
        ?array $groups = null,
    ): array {
        $this->framework->initialize();

        $archive = NewsArchiveModel::findById($id);
        if ($archive === null) {
            return ['error' => 'not_found', 'message' => "News archive $id not found."];
        }

        if ($jumpTo !== null && PageModel::findById($jumpTo) === null) {
            return ['error' => 'page_not_found', 'message' => "jumpTo page $jumpTo does not exist."];
        }

        $versions = $this->bootVersions($id);

        $input = array_filter(
            [
                'title' => $title,
                'jumpTo' => $jumpTo,
                'protected' => $protected,
                'groups' => $groups,
            ],
            static fn ($v): bool => $v !== null,
        );

        try {
            $changed = $this->mapper->apply($archive, $input, detectChanges: true);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        if ($changed === []) {
            return Serializer::summary($archive) + [
                'updated' => false,
                'id' => (int) $archive->id,
                'changed_fields' => [],
                'applied' => 0,
            ];
        }

        $archive->tstamp = time();

        try {
            $archive->save();
            $versions->create();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->logGeneral(
            sprintf('Updated news archive ID %d via MCP (fields: %s)', $id, implode(', ', $changed)),
            __METHOD__,
        );

        return Serializer::summary($archive) + [
            'updated' => true,
            'id' => (int) $archive->id,
            'changed_fields' => $changed,
            'applied' => \count($changed),
        ];
    }

    // ──────────────────────────── delete ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'news_archive_delete',
        description: <<<'DESC'
            Deletes a Contao news archive. SAFE BY DEFAULT: if the archive still contains news
            entries, the call returns error "archive_not_empty" without touching anything, and
            tells the caller how many entries are inside. Pass cascade=true to cascade — that
            deletes every news entry in the archive AND its associated content elements
            (tl_content rows with ptable=tl_news), then the archive itself. Each delete writes
            to tl_log; the archive deletion gets a Versions snapshot.

            Requires confirm_destructive=true to proceed.
        DESC,
    )]
    public function delete(int $id, bool $confirm_destructive = false, bool $cascade = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'news_archive_delete is irreversible. Pass confirm_destructive=true to proceed. Use cascade=true to also drop children.',
            ];
        }

        $archive = NewsArchiveModel::findById($id);
        if ($archive === null) {
            return ['error' => 'not_found', 'message' => "News archive $id not found."];
        }

        $title = (string) $archive->title;
        $newsCount = (int) NewsModel::countBy('pid', $id);

        if ($newsCount > 0 && !$cascade) {
            return [
                'error' => 'archive_not_empty',
                'message' => "Archive $id contains $newsCount news entries. Delete them first or pass cascade=true to cascade.",
                'news_count' => $newsCount,
            ];
        }

        $cascadedNews = 0;
        $cascadedContent = 0;

        $this->bootVersions($id);
        // Wrap the full cascade (news + each news's tl_content + the archive
        // itself) in a single DBAL transaction. Without it, a mid-cascade failure
        // (deadlock, dropped connection, constraint conflict) leaves orphan rows
        // — e.g. news entries gone but their tl_content still referencing the
        // now-dead news id, or content gone while the archive still exists.
        $this->dbalRetry->transactional($this->connection, function () use ($id, $newsCount, $archive, &$cascadedNews, &$cascadedContent): void {
            if ($newsCount > 0) {
                $newsItems = NewsModel::findBy('pid', $id);
                foreach ($newsItems ?? [] as $news) {
                    $contentItems = ContentModel::findBy(['pid = ?', 'ptable = ?'], [(int) $news->id, 'tl_news']);
                    foreach ($contentItems ?? [] as $content) {
                        $content->delete();
                        ++$cascadedContent;
                    }
                    $news->delete();
                    ++$cascadedNews;
                }
            }

            $archive->delete();
        });

        $this->logGeneral(
            sprintf(
                'Deleted news archive ID %d ("%s") via MCP — cascaded %d news, %d content elements',
                $id,
                $title,
                $cascadedNews,
                $cascadedContent,
            ),
            __METHOD__,
        );

        return [
            'deleted' => true,
            'id' => $id,
            'cascaded_news' => $cascadedNews,
            'cascaded_content_elements' => $cascadedContent,
        ];
    }

    // ───────────────────────── private helpers ──────────────────────

    private function bootVersions(int $id): Versions
    {
        $versions = new Versions('tl_news_archive', $id);
        $versions->setUsername($this->authorResolver->getLogUsername());
        $versions->setUserId($this->authorResolver->resolve());
        $versions->initialize();

        return $versions;
    }

    private function logGeneral(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::GENERAL, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }
}
