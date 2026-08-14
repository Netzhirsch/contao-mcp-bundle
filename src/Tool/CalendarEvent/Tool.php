<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\CalendarEvent;

use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\CoreBundle\Slug\Slug;
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
 * MCP facade for tl_calendar_events. Five CRUD tools.
 *
 * Date/time conventions:
 *   - startDate / endDate / repeatEnd: ISO 8601 date strings (YYYY-MM-DD)
 *   - startTime / endTime:             HH:MM:SS, combined with startDate at save
 *   - addTime:                         bool, controls FE display of start/end time
 *   - recurring + repeatEach (e.g. "1m", "2w") + repeatEnd + recurrences: rrule-lite
 *   - start / stop:                    publish-window ISO datetime
 *
 * languageMain comes through the changelanguage provider when installed.
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

    /**
     * @param object|null $filters DCA-validated filter map. See entity_query_options("tl_calendar_events").
     *
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'calendar_events_list',
        description: 'Lists Contao calendar events (tl_calendar_events). Filter by calendar_id. q does LIKE-search across DCA-searchable fields; filters is a DCA-validated equality map; updated_after/before take Unix-ts or ISO-8601. Use entity_query_options("tl_calendar_events") for the queryable shape. Default: ALL incl. unpublished/disabled (pass include_unpublished=false for published-only).',
    )]
    public function list(
        ?int $calendar_id = null,
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
        if ($calendar_id !== null) {
            $columns[] = 'tl_calendar_events.pid = ?';
            $values[] = $calendar_id;
        }
        if (!$include_unpublished) {
            $time = time();
            $columns[] = 'tl_calendar_events.published = ?';
            $values[] = '1';
            $columns[] = "(tl_calendar_events.start = '' OR tl_calendar_events.start <= ?)";
            $values[] = (string) $time;
            $columns[] = "(tl_calendar_events.stop = '' OR tl_calendar_events.stop > ?)";
            $values[] = (string) $time;
        }

        $search = $this->filterResolver->buildSearchClause('tl_calendar_events', $q);
        if ($search !== null) {
            $columns[] = $search['clause'];
            $values = array_merge($values, $search['params']);
        }
        try {
            $filtersArr = $this->filterResolver->normaliseFilters($filters);
            $fr = $this->filterResolver->buildFilterClauses('tl_calendar_events', $filtersArr);
            $columns = array_merge($columns, $fr['clauses']);
            $values = array_merge($values, $fr['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_filter', 'message' => $e->getMessage()];
        }
        try {
            $ts = $this->filterResolver->buildTstampRange('tl_calendar_events', $updated_after, $updated_before);
            $columns = array_merge($columns, $ts['clauses']);
            $values = array_merge($values, $ts['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $items = CalendarEventsModel::findBy(
            $columns ?: null,
            $values ?: null,
            ['order' => 'tl_calendar_events.startDate DESC', 'limit' => $limit, 'offset' => $offset],
        );

        $out = [];
        foreach ($items ?? [] as $ev) {
            if (!$this->guard->mayRead('tl_calendar_events', $ev->row())) {
                continue;
            }
            $out[] = $this->serializer->summary($ev);
        }

        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'calendar_event_get',
        description: 'Returns a single calendar event with all DCA-editable fields plus its content elements (tl_content with ptable=tl_calendar_events).',
    )]
    public function get(int $id, bool $include_unpublished = true): array
    {
        $this->framework->initialize();
        $ev = CalendarEventsModel::findById($id);
        if ($ev === null) {
            return ['error' => 'not_found', 'message' => "Event $id not found."];
        }
        return $this->serializer->summary($ev) + [
            'content_elements' => $this->collectContentElements((int) $ev->id, $include_unpublished),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'calendar_event_create',
        description: <<<'DESC'
            Creates a new calendar event. Required: calendar_id (tl_calendar.id), title,
            startDate (YYYY-MM-DD). Optional: endDate, addTime (bool, then startTime/endTime
            as HH:MM:SS), recurring (bool, then repeatEach like "1m"/"2w", repeatEnd,
            recurrences=0 for indefinite), location, address, image fields, source
            (default/internal/article/external), languageMain (changelanguage). Alias
            auto-generated.
        DESC,
    )]
    public function create(
        int $calendar_id,
        string $title,
        string $startDate,
        ?string $endDate = null,
        ?bool $addTime = null,
        ?string $startTime = null,
        ?string $endTime = null,
        ?bool $featured = null,
        ?string $alias = null,
        ?int $author_id = null,
        ?bool $recurring = null,
        ?string $repeatEach = null,
        ?string $repeatEnd = null,
        ?int $recurrences = null,
        ?string $location = null,
        ?string $address = null,
        ?string $pageTitle = null,
        ?string $robots = null,
        ?string $description = null,
        ?string $canonicalLink = null,
        ?string $teaser = null,
        ?bool $addImage = null,
        ?bool $overwriteMeta = null,
        ?string $singleSRC = null,
        ?string $alt = null,
        ?string $imageTitle = null,
        ?string $imageUrl = null,
        ?string $size = null,
        ?bool $fullsize = null,
        ?string $caption = null,
        ?string $floating = null,
        ?bool $addEnclosure = null,
        ?string $source = null,
        ?int $jumpTo = null,
        ?int $articleId = null,
        ?string $url = null,
        ?bool $target = null,
        ?string $linkText = null,
        ?string $cssClass = null,
        ?bool $noComments = null,
        ?string $searchIndexer = null,
        ?bool $published = null,
        ?string $start = null,
        ?string $stop = null,
        ?int $languageMain = null,
        #[Schema(type: 'object')] mixed $extras = null,
    ): array {
        $this->framework->initialize();

        if (CalendarModel::findById($calendar_id) === null) {
            return ['error' => 'calendar_not_found', 'message' => "Calendar $calendar_id does not exist."];
        }

        $ev = new CalendarEventsModel();
        $ev->tstamp = time();
        $ev->pid = $calendar_id;
        $ev->title = $title;
        $ev->alias = '';
        $ev->author = 0;
        $ev->source = 'default';
        $ev->floating = 'above';
        foreach (['featured', 'addTime', 'recurring', 'addImage', 'overwriteMeta', 'fullsize', 'addEnclosure', 'target', 'noComments', 'published'] as $bf) {
            $ev->$bf = 0;
        }

        $coreInput = array_filter(
            compact(
                'calendar_id', 'title', 'startDate', 'endDate', 'addTime',
                'startTime', 'endTime', 'featured', 'alias', 'author_id',
                'recurring', 'repeatEach', 'repeatEnd', 'recurrences',
                'location', 'address', 'pageTitle', 'robots', 'description',
                'canonicalLink', 'teaser', 'addImage', 'overwriteMeta',
                'singleSRC', 'alt', 'imageTitle', 'imageUrl', 'size',
                'fullsize', 'caption', 'floating', 'addEnclosure',
                'source', 'jumpTo', 'articleId', 'url', 'target', 'linkText',
                'cssClass', 'noComments', 'searchIndexer', 'published',
                'start', 'stop',
            ),
            static fn ($v): bool => $v !== null,
        );

        $input = self::mergeExtensionInput($coreInput, $languageMain, $extras);

        try {
            $this->mapper->apply($ev, $input, detectChanges: false);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        if ((int) $ev->author === 0) {
            $ev->author = $this->authorResolver->resolve();
        }
        if ((string) $ev->alias === '') {
            $ev->alias = $this->generateAlias($title);
        }

        try {
            $ev->save();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->bootVersions((int) $ev->id)->create();
        $this->log(sprintf('Created event ID %d ("%s") in calendar %d via MCP', (int) $ev->id, $title, $calendar_id), __METHOD__);

        return $this->serializer->summary($ev) + ['created' => true];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'calendar_event_update',
        description: 'Updates an existing event. Only fields you pass are changed. Versions snapshot + tl_log.',
    )]
    public function update(
        int $id,
        ?int $calendar_id = null,
        ?string $title = null,
        ?bool $featured = null,
        ?string $alias = null,
        ?int $author_id = null,
        ?bool $addTime = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $startTime = null,
        ?string $endTime = null,
        ?bool $recurring = null,
        ?string $repeatEach = null,
        ?string $repeatEnd = null,
        ?int $recurrences = null,
        ?string $location = null,
        ?string $address = null,
        ?string $pageTitle = null,
        ?string $robots = null,
        ?string $description = null,
        ?string $canonicalLink = null,
        ?string $teaser = null,
        ?bool $addImage = null,
        ?bool $overwriteMeta = null,
        ?string $singleSRC = null,
        ?string $alt = null,
        ?string $imageTitle = null,
        ?string $imageUrl = null,
        ?string $size = null,
        ?bool $fullsize = null,
        ?string $caption = null,
        ?string $floating = null,
        ?bool $addEnclosure = null,
        ?string $source = null,
        ?int $jumpTo = null,
        ?int $articleId = null,
        ?string $url = null,
        ?bool $target = null,
        ?string $linkText = null,
        ?string $cssClass = null,
        ?bool $noComments = null,
        ?string $searchIndexer = null,
        ?bool $published = null,
        ?string $start = null,
        ?string $stop = null,
        ?int $languageMain = null,
        #[Schema(type: 'object')] mixed $extras = null,
    ): array {
        $this->framework->initialize();
        $ev = CalendarEventsModel::findById($id);
        if ($ev === null) {
            return ['error' => 'not_found', 'message' => "Event $id not found."];
        }
        if ($calendar_id !== null && CalendarModel::findById($calendar_id) === null) {
            return ['error' => 'calendar_not_found', 'message' => "Calendar $calendar_id does not exist."];
        }

        $versions = $this->bootVersions($id);

        if ($alias === '') {
            $alias = $this->generateAlias((string) ($title ?? $ev->title), $id);
        }

        $coreInput = array_filter(
            compact(
                'calendar_id', 'title', 'featured', 'alias', 'author_id',
                'addTime', 'startDate', 'endDate', 'startTime', 'endTime',
                'recurring', 'repeatEach', 'repeatEnd', 'recurrences',
                'location', 'address', 'pageTitle', 'robots', 'description',
                'canonicalLink', 'teaser', 'addImage', 'overwriteMeta',
                'singleSRC', 'alt', 'imageTitle', 'imageUrl', 'size',
                'fullsize', 'caption', 'floating', 'addEnclosure',
                'source', 'jumpTo', 'articleId', 'url', 'target', 'linkText',
                'cssClass', 'noComments', 'searchIndexer', 'published',
                'start', 'stop',
            ),
            static fn ($v): bool => $v !== null,
        );

        $input = self::mergeExtensionInput($coreInput, $languageMain, $extras);

        try {
            $changed = $this->mapper->apply($ev, $input, detectChanges: true);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        if ($changed === []) {
            return $this->serializer->summary($ev) + [
                'updated' => false,
                'id' => (int) $ev->id,
                'changed_fields' => [],
                'applied' => 0,
            ];
        }

        $ev->tstamp = time();
        try {
            $ev->save();
            $versions->create();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->log(sprintf('Updated event ID %d via MCP (fields: %s)', $id, implode(', ', $changed)), __METHOD__);
        return $this->serializer->summary($ev) + [
            'updated' => true,
            'id' => (int) $ev->id,
            'changed_fields' => $changed,
            'applied' => \count($changed),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'calendar_event_delete',
        description: 'Deletes a calendar event and cascades to its content elements (tl_content with ptable=tl_calendar_events). Versions snapshot + tl_log. Requires confirm_destructive=true to proceed.',
    )]
    public function delete(int $id, bool $confirm_destructive = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'calendar_event_delete is irreversible. Pass confirm_destructive=true to proceed.',
            ];
        }

        $ev = CalendarEventsModel::findById($id);
        if ($ev === null) {
            return ['error' => 'not_found', 'message' => "Event $id not found."];
        }
        $title = (string) $ev->title;
        $this->bootVersions($id);

        $contentCount = 0;
        // Wrap content-element cascade + event delete in one transaction so a
        // mid-cascade failure can't leave content rows orphaned (or vice versa,
        // event gone with content elements still pointing at its id).
        $this->dbalRetry->transactional($this->connection, function () use ($id, $ev, &$contentCount): void {
            $contentItems = ContentModel::findBy(['pid = ?', 'ptable = ?'], [$id, 'tl_calendar_events']);
            foreach ($contentItems ?? [] as $content) {
                $content->delete();
                ++$contentCount;
            }

            $ev->delete();
        });

        $this->log(sprintf('Deleted event ID %d ("%s") and %d content element(s) via MCP', $id, $title, $contentCount), __METHOD__);

        return ['deleted' => true, 'id' => $id, 'deleted_content_elements' => $contentCount];
    }

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
        $v = new Versions('tl_calendar_events', $id);
        $v->setUsername($this->authorResolver->getLogUsername());
        $v->setUserId($this->authorResolver->resolve());
        $v->initialize();
        return $v;
    }

    private function log(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::GENERAL, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }

    private function generateAlias(string $title, ?int $excludeId = null): string
    {
        $check = $excludeId === null
            ? static fn (string $value): bool => CalendarEventsModel::findOneBy('alias', $value) !== null
            : static fn (string $value): bool => CalendarEventsModel::findOneBy(['alias = ?', 'id != ?'], [$value, $excludeId]) !== null;
        return $this->slug->generate($title, ['validChars' => '0-9a-z'], $check);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectContentElements(int $eventId, bool $includeUnpublished): array
    {
        $columns = ['tl_content.pid = ?', "tl_content.ptable = 'tl_calendar_events'"];
        $values = [$eventId];
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
            ];
        }
        return $out;
    }
}
