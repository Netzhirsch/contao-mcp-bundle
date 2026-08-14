<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\MemberGroup;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\MemberGroupModel;
use Contao\PageModel;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\QueryFilterResolver;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;

/**
 * MCP facade for tl_member_group. Five CRUD tools:
 *   member_groups_list, member_group_get, member_group_create,
 *   member_group_update, member_group_delete.
 *
 * Used as the `groups` list on members (tl_member.groups) and on protected
 * pages (tl_page.groups when `protected=1`). Deleting a group does NOT
 * auto-remove the id from those serialised lists — the delete tool reports
 * how many members + pages still reference the group so the caller can
 * decide whether to clean them up.
 */
final class Tool
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly QueryFilterResolver $filterResolver,
    ) {
    }

    // ───────────────────────────── list ─────────────────────────────

    /**
     * @param object|null $filters DCA-validated filter map. See entity_query_options("tl_member_group").
     *
     * @return array{items: list<array<string, mixed>>, count: int}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'member_groups_list',
        description: 'Lists Contao front-end member groups (tl_member_group). Use the returned ids in the `groups` list on members or protected pages/news_archives. q does LIKE-search across DCA-searchable fields; filters is a DCA-validated equality map; updated_after/before take Unix-ts or ISO-8601. Default: ALL incl. disabled groups (pass include_inactive=false for active-only).',
    )]
    public function list(
        bool $include_inactive = true,
        ?string $q = null,
        #[Schema(type: 'object')] mixed $filters = null,
        ?string $updated_after = null,
        ?string $updated_before = null,
    ): array {
        $this->framework->initialize();

        $columns = $include_inactive ? [] : ["tl_member_group.disable = ''"];
        $values = [];

        $search = $this->filterResolver->buildSearchClause('tl_member_group', $q);
        if ($search !== null) {
            $columns[] = $search['clause'];
            $values = array_merge($values, $search['params']);
        }
        try {
            $filtersArr = $this->filterResolver->normaliseFilters($filters);
            $fr = $this->filterResolver->buildFilterClauses('tl_member_group', $filtersArr);
            $columns = array_merge($columns, $fr['clauses']);
            $values = array_merge($values, $fr['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_filter', 'message' => $e->getMessage()];
        }
        try {
            $ts = $this->filterResolver->buildTstampRange('tl_member_group', $updated_after, $updated_before);
            $columns = array_merge($columns, $ts['clauses']);
            $values = array_merge($values, $ts['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $items = MemberGroupModel::findBy(
            $columns === [] ? null : $columns,
            $values === [] ? null : $values,
            ['order' => 'tl_member_group.name'],
        );

        $out = [];
        foreach ($items ?? [] as $g) {
            $out[] = self::summary($g);
        }

        return ['items' => $out, 'count' => \count($out)];
    }

    // ───────────────────────────── get ──────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'member_group_get',
        description: 'Returns a single tl_member_group by id, plus counts of members and pages that reference it (useful before delete).',
    )]
    public function get(int $id): array
    {
        $this->framework->initialize();

        $group = MemberGroupModel::findByPk($id);
        if ($group === null) {
            return ['error' => 'not_found', 'message' => sprintf('No member_group with id %d', $id)];
        }

        return self::summary($group) + [
            'member_count' => $this->countMembers($id),
            'page_reference_count' => $this->countPageReferences($id),
        ];
    }

    // ──────────────────────────── create ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'member_group_create',
        description: <<<'DESC'
            Creates a new tl_member_group.

            Required: name. Optional via `fields`:
              - redirect (bool) — redirect after login?
              - jump_to (page id) — redirect target when redirect=true
              - active (bool, default true)
              - start, stop (ISO datetime, validity window)
        DESC,
    )]
    /**
     * @param object|null $fields Optional member_group columns as a JSON object.
     */
    public function create(string $name, #[Schema(type: 'object')] mixed $fields = null): array
    {
        $this->framework->initialize();

        if (trim($name) === '') {
            return ['error' => 'invalid_input', 'message' => 'name is required'];
        }

        $group = new MemberGroupModel();
        $group->tstamp = time();
        $group->name = mb_substr(trim($name), 0, 255);
        $group->disable = 0;

        $errors = $this->applyFields($group, self::normaliseFields($fields));
        if ($errors !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $errors];
        }
        $group->save();

        $this->log(sprintf('Created member_group "%s" (id=%d)', $group->name, (int) $group->id), __METHOD__);

        return ['created' => true, 'id' => (int) $group->id] + self::summary($group);
    }

    // ──────────────────────────── update ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'member_group_update',
        description: 'Updates a tl_member_group row. Pass id, then `fields` as a JSON OBJECT. Accepts name, redirect, jump_to, active, start, stop.',
    )]
    /**
     * @param object|null $fields Member group columns to change as a JSON object.
     */
    public function update(int $id, #[Schema(type: 'object')] mixed $fields = null): array
    {
        $this->framework->initialize();

        $group = MemberGroupModel::findByPk($id);
        if ($group === null) {
            return ['error' => 'not_found', 'message' => sprintf('No member_group with id %d', $id)];
        }

        $input = self::normaliseFields($fields);
        if ($input === []) {
            return ['error' => 'no_fields', 'message' => 'fields must be a non-empty JSON object'];
        }

        // Snapshot the columns the caller might change BEFORE applyFields
        // writes new values to the model. Used to detect true no-ops below
        // (call with identical values → don't save, don't create a Versions
        // snapshot, don't bump tstamp — saves audit-trail noise).
        $before = self::snapshotForInput($group, array_keys($input));

        $errors = $this->applyFields($group, $input);
        if ($errors !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $errors];
        }

        // Diff the snapshot against the model's new state. Only count fields
        // that actually carry a different value — same string-cast on both
        // sides flattens null/0/'' edge cases (Contao stores booleans as
        // '1' / '' strings via DCA).
        $changedFields = self::diffChanged($group, $before);

        if ($changedFields === []) {
            // True no-op: input contained known fields, but every value
            // matched what was already stored. Skip save() + Versions, keep
            // tstamp untouched, return updated=false so the caller can
            // distinguish "applied" from "nothing-to-do".
            return [
                'updated' => false,
                'id' => $id,
                'changed_fields' => [],
                'applied' => 0,
                'message' => 'no changes',
            ] + self::summary($group);
        }

        $versions = $this->bootVersions($id);
        $group->tstamp = time();
        $group->save();
        $versions->create();

        $this->log(sprintf('Updated member_group "%s" (id=%d, fields=%s)', $group->name, $id, implode(',', $changedFields)), __METHOD__);

        return [
            'updated' => true,
            'id' => $id,
            'changed_fields' => $changedFields,
            'applied' => \count($changedFields),
        ] + self::summary($group);
    }

    // ──────────────────────────── delete ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'member_group_delete',
        description: 'Deletes a tl_member_group. Reports how many members + protected pages still reference the group (those references are NOT auto-pruned — cleaning up is your job via member_update / page_update). Requires confirm_destructive=true to proceed.',
    )]
    public function delete(int $id, bool $confirm_destructive = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'member_group_delete is irreversible. Pass confirm_destructive=true to proceed.',
            ];
        }

        $group = MemberGroupModel::findByPk($id);
        if ($group === null) {
            return ['error' => 'not_found', 'message' => sprintf('No member_group with id %d', $id)];
        }

        $memberRefs = $this->countMembers($id);
        $pageRefs = $this->countPageReferences($id);
        $name = (string) $group->name;
        $group->delete();

        $this->log(sprintf(
            'Deleted member_group "%s" (id=%d, stale member refs=%d, page refs=%d)',
            $name, $id, $memberRefs, $pageRefs,
        ), __METHOD__);

        return [
            'deleted' => true,
            'id' => $id,
            'stale_member_references' => $memberRefs,
            'stale_page_references' => $pageRefs,
        ];
    }

    // ─────────────────────────── helpers ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function summary(MemberGroupModel $g): array
    {
        return [
            'id' => (int) $g->id,
            'name' => (string) $g->name,
            'redirect' => (bool) $g->redirect,
            'jump_to' => (int) $g->jumpTo,
            'active' => !(bool) $g->disable,
            'start' => (string) $g->start,
            'stop' => (string) $g->stop,
            'tstamp' => (int) $g->tstamp,
        ];
    }

    /**
     * @return list<string>
     */
    private function applyFields(MemberGroupModel $g, array $input): array
    {
        $errors = [];

        if (\array_key_exists('name', $input)) {
            $value = trim((string) $input['name']);
            if ($value === '') {
                $errors[] = 'name must not be empty';
            } else {
                $g->name = mb_substr($value, 0, 255);
            }
        }
        if (\array_key_exists('redirect', $input)) {
            $g->redirect = (bool) $input['redirect'] ? 1 : 0;
        }
        if (\array_key_exists('jump_to', $input)) {
            $value = (int) $input['jump_to'];
            if ($value > 0 && PageModel::findByPk($value) === null) {
                $errors[] = sprintf('jump_to: page id %d does not exist', $value);
            } else {
                $g->jumpTo = $value;
            }
        }
        if (\array_key_exists('active', $input)) {
            $g->disable = (bool) $input['active'] ? 0 : 1;
        }
        foreach (['start', 'stop'] as $key) {
            if (\array_key_exists($key, $input)) {
                $g->{$key} = (string) ($input[$key] ?? '');
            }
        }

        return $errors;
    }

    /**
     * Map of public field name (the keys the caller passes in `fields`) to
     * the underlying MemberGroupModel column name. Some keys are renamed
     * (jump_to → jumpTo) and `active` inverts onto `disable`.
     */
    private const PUBLIC_TO_COLUMN = [
        'name' => 'name',
        'redirect' => 'redirect',
        'jump_to' => 'jumpTo',
        'active' => 'disable',
        'start' => 'start',
        'stop' => 'stop',
    ];

    /**
     * Reads the model's current values for exactly the public-name keys the
     * caller submitted, BEFORE applyFields() mutates the model. The output is
     * keyed by the public name so {@see diffChanged} can produce a
     * caller-friendly `changed_fields` list (no internal column leakage).
     *
     * @param list<string> $publicKeys
     *
     * @return array<string, mixed>
     */
    private static function snapshotForInput(MemberGroupModel $group, array $publicKeys): array
    {
        $snapshot = [];
        foreach ($publicKeys as $key) {
            if (!isset(self::PUBLIC_TO_COLUMN[$key])) {
                continue;
            }
            $snapshot[$key] = $group->{self::PUBLIC_TO_COLUMN[$key]};
        }
        return $snapshot;
    }

    /**
     * Diff a pre-applyFields snapshot against the model's current state.
     * String-cast on both sides so DCA-typical null/0/'' coercions don't
     * register as fake changes (Contao stores booleans as '1' / '' strings
     * via the eval->isBoolean DCA option — a fresh model carries an int 0
     * for the same column).
     *
     * @param array<string, mixed> $before
     *
     * @return list<string>
     */
    private static function diffChanged(MemberGroupModel $group, array $before): array
    {
        $changed = [];
        foreach ($before as $publicKey => $oldValue) {
            $column = self::PUBLIC_TO_COLUMN[$publicKey] ?? null;
            if ($column === null) {
                continue;
            }
            $newValue = $group->{$column};
            if ((string) $newValue !== (string) $oldValue) {
                $changed[] = $publicKey;
            }
        }
        return $changed;
    }

    private function countMembers(int $groupId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_member WHERE `groups` LIKE ?',
            [sprintf('%%i:%d;%%', $groupId)],
        );
    }

    private function countPageReferences(int $groupId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_page WHERE protected = ? AND `groups` LIKE ?',
            ['1', sprintf('%%i:%d;%%', $groupId)],
        );
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
            if ($fields !== [] && array_is_list($fields)) {
                throw new \InvalidArgumentException('`fields` must be a JSON object, not a list.');
            }
            return $fields;
        }

        throw new \InvalidArgumentException('`fields` must be a JSON object.');
    }

    private function bootVersions(int $id): Versions
    {
        $v = new Versions('tl_member_group', $id);
        $v->setUsername($this->authorResolver->getLogUsername());
        $v->setUserId($this->authorResolver->resolve());
        $v->initialize();

        return $v;
    }

    private function log(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::ACCESS, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }
}
