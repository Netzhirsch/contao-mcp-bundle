<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Calendar;

use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
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
 * MCP facade for tl_calendar — the parent of tl_calendar_events.
 *
 * Analogous to news_archive_* but with the calendar-bundle's comments block.
 * Delete is safe by default: refuses to remove a non-empty calendar unless
 * force=true, in which case events + their content elements cascade.
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

    /**
     * @param object|null $filters DCA-validated filter map. See entity_query_options("tl_calendar").
     *
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'calendars_list',
        description: 'Lists Contao calendars (tl_calendar). Use the returned id as calendar_id in calendar_event_create. q does LIKE-search across DCA-searchable fields; filters is a DCA-validated equality map; updated_after/before take Unix-ts or ISO-8601.',
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

        $search = $this->filterResolver->buildSearchClause('tl_calendar', $q);
        if ($search !== null) {
            $columns[] = $search['clause'];
            $values = array_merge($values, $search['params']);
        }
        try {
            $filtersArr = $this->filterResolver->normaliseFilters($filters);
            $fr = $this->filterResolver->buildFilterClauses('tl_calendar', $filtersArr);
            $columns = array_merge($columns, $fr['clauses']);
            $values = array_merge($values, $fr['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_filter', 'message' => $e->getMessage()];
        }
        try {
            $ts = $this->filterResolver->buildTstampRange('tl_calendar', $updated_after, $updated_before);
            $columns = array_merge($columns, $ts['clauses']);
            $values = array_merge($values, $ts['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $items = CalendarModel::findBy(
            $columns === [] ? null : $columns,
            $values === [] ? null : $values,
            ['order' => 'tl_calendar.title', 'limit' => $limit, 'offset' => $offset],
        );

        $out = [];
        foreach ($items ?? [] as $c) {
            if (!$this->guard->mayRead('tl_calendar', $c->row())) {
                continue;
            }
            $out[] = Serializer::summary($c);
        }

        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'calendar_get',
        description: 'Returns a single Contao calendar by id, plus the count of events it contains.',
    )]
    public function get(int $id): array
    {
        $this->framework->initialize();
        $cal = CalendarModel::findById($id);
        if ($cal === null) {
            return ['error' => 'not_found', 'message' => "Calendar $id not found."];
        }
        return Serializer::summary($cal) + ['event_count' => (int) CalendarEventsModel::countBy('pid', $id)];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'calendar_create',
        description: 'Creates a new Contao calendar. Required: title, jumpTo (tl_page.id of the event-detail reader page). Optional: protected (+ groups list when true), allowComments (+ moderate/notify/sortOrder/perPage/bbcode/requireLogin/disableCaptcha when true). Versions snapshot + tl_log.',
    )]
    public function create(
        string $title,
        int $jumpTo,
        ?bool $protected = null,
        ?array $groups = null,
        ?bool $allowComments = null,
        ?string $notify = null,
        ?string $sortOrder = null,
        ?int $perPage = null,
        ?bool $moderate = null,
        ?bool $bbcode = null,
        ?bool $requireLogin = null,
        ?bool $disableCaptcha = null,
    ): array {
        $this->framework->initialize();

        if (PageModel::findById($jumpTo) === null) {
            return ['error' => 'page_not_found', 'message' => "jumpTo page $jumpTo does not exist."];
        }

        $cal = new CalendarModel();
        $cal->tstamp = time();
        $cal->title = '';
        $cal->jumpTo = 0;
        $cal->groups = '';
        foreach (['protected', 'allowComments', 'moderate', 'bbcode', 'requireLogin', 'disableCaptcha'] as $bf) {
            $cal->$bf = 0;
        }
        $cal->notify = 'notify_admin';
        $cal->sortOrder = 'ascending';

        $input = array_filter(
            compact('title', 'jumpTo', 'protected', 'groups', 'allowComments', 'notify', 'sortOrder', 'perPage', 'moderate', 'bbcode', 'requireLogin', 'disableCaptcha'),
            static fn ($v): bool => $v !== null,
        );

        try {
            $this->mapper->apply($cal, $input, detectChanges: false);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        try {
            $cal->save();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->bootVersions((int) $cal->id)->create();
        $this->log(sprintf('Created calendar ID %d ("%s") via MCP', (int) $cal->id, $title), __METHOD__);

        return Serializer::summary($cal) + ['created' => true];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'calendar_update',
        description: 'Updates a Contao calendar. Only fields you pass are changed. Returns updated calendar + changed_fields. Versions snapshot + tl_log.',
    )]
    public function update(
        int $id,
        ?string $title = null,
        ?int $jumpTo = null,
        ?bool $protected = null,
        ?array $groups = null,
        ?bool $allowComments = null,
        ?string $notify = null,
        ?string $sortOrder = null,
        ?int $perPage = null,
        ?bool $moderate = null,
        ?bool $bbcode = null,
        ?bool $requireLogin = null,
        ?bool $disableCaptcha = null,
    ): array {
        $this->framework->initialize();
        $cal = CalendarModel::findById($id);
        if ($cal === null) {
            return ['error' => 'not_found', 'message' => "Calendar $id not found."];
        }
        if ($jumpTo !== null && PageModel::findById($jumpTo) === null) {
            return ['error' => 'page_not_found', 'message' => "jumpTo page $jumpTo does not exist."];
        }

        $versions = $this->bootVersions($id);

        $input = array_filter(
            compact('title', 'jumpTo', 'protected', 'groups', 'allowComments', 'notify', 'sortOrder', 'perPage', 'moderate', 'bbcode', 'requireLogin', 'disableCaptcha'),
            static fn ($v): bool => $v !== null,
        );

        try {
            $changed = $this->mapper->apply($cal, $input, detectChanges: true);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        if ($changed === []) {
            return Serializer::summary($cal) + [
                'updated' => false,
                'id' => (int) $cal->id,
                'changed_fields' => [],
                'applied' => 0,
            ];
        }

        $cal->tstamp = time();
        try {
            $cal->save();
            $versions->create();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->log(sprintf('Updated calendar ID %d via MCP (fields: %s)', $id, implode(', ', $changed)), __METHOD__);
        return Serializer::summary($cal) + [
            'updated' => true,
            'id' => (int) $cal->id,
            'changed_fields' => $changed,
            'applied' => \count($changed),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'calendar_delete',
        description: 'Deletes a Contao calendar. Safe by default: refuses if events exist. Pass cascade=true to cascade — deletes events + their content elements (tl_content with ptable=tl_calendar_events) + the calendar itself. Requires confirm_destructive=true to proceed.',
    )]
    public function delete(int $id, bool $confirm_destructive = false, bool $cascade = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'calendar_delete is irreversible. Pass confirm_destructive=true to proceed. Use cascade=true to also drop children.',
            ];
        }

        $cal = CalendarModel::findById($id);
        if ($cal === null) {
            return ['error' => 'not_found', 'message' => "Calendar $id not found."];
        }

        $eventCount = (int) CalendarEventsModel::countBy('pid', $id);
        if ($eventCount > 0 && !$cascade) {
            return [
                'error' => 'calendar_not_empty',
                'message' => "Calendar $id contains $eventCount events. Pass cascade=true to cascade.",
                'event_count' => $eventCount,
            ];
        }

        $title = (string) $cal->title;
        $cascadedEvents = 0;
        $cascadedContent = 0;

        $this->bootVersions($id);
        // Wrap the full cascade (events + each event's tl_content + the calendar
        // itself) in a single DBAL transaction. Without it, a mid-cascade failure
        // (deadlock, dropped connection, constraint conflict) leaves orphan rows
        // — e.g. events gone but their tl_content still referencing the now-
        // deleted event, or content gone while the calendar still exists.
        $this->dbalRetry->transactional($this->connection, function () use ($id, $eventCount, $cal, &$cascadedEvents, &$cascadedContent): void {
            if ($eventCount > 0) {
                $events = CalendarEventsModel::findBy('pid', $id);
                foreach ($events ?? [] as $ev) {
                    $contentItems = ContentModel::findBy(['pid = ?', 'ptable = ?'], [(int) $ev->id, 'tl_calendar_events']);
                    foreach ($contentItems ?? [] as $content) {
                        $content->delete();
                        ++$cascadedContent;
                    }
                    $ev->delete();
                    ++$cascadedEvents;
                }
            }

            $cal->delete();
        });

        $this->log(sprintf('Deleted calendar ID %d ("%s") via MCP — cascaded %d events, %d content elements', $id, $title, $cascadedEvents, $cascadedContent), __METHOD__);

        return ['deleted' => true, 'id' => $id, 'cascaded_events' => $cascadedEvents, 'cascaded_content_elements' => $cascadedContent];
    }

    private function bootVersions(int $id): Versions
    {
        $v = new Versions('tl_calendar', $id);
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
