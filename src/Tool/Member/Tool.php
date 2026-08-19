<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Member;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\MemberModel;
use Contao\Versions;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\QueryFilterResolver;
use Netzhirsch\ContaoMcpBundle\Service\UpdateDiff;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;

/**
 * MCP facade for tl_member (front-end users / member accounts).
 *
 * Five tools:
 *   members_list, member_get, member_create, member_update, member_delete.
 *
 * Password handling: callers pass plain-text via the `password` field; the
 * tool hashes it with password_hash(PASSWORD_DEFAULT) before storage. Hashes
 * never travel back over the wire — member_get omits `password` and `secret`.
 */
final class Tool
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly FieldMapper $mapper,
        private readonly QueryFilterResolver $filterResolver,
    ) {
    }

    // ───────────────────────────── list ─────────────────────────────

    /**
     * @param object|null $filters DCA-validated filter map. See entity_query_options("tl_member").
     *
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'members_list',
        description: 'Lists tl_member rows (front-end members). Default: ALL incl. inactive/disabled (pass include_inactive=false for active-only). Legacy `search` param does a hardcoded LIKE across username/email/firstname/lastname; prefer `q` which honours the DCA-searchable field set (covers extension columns too). filters is a DCA-validated equality map; updated_after/before take Unix-ts or ISO-8601. See entity_query_options("tl_member").',
    )]
    public function list(
        bool $include_inactive = true,
        ?string $search = null,
        ?int $group_id = null,
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
        if (!$include_inactive) {
            $columns[] = "tl_member.disable = ''";
        }
        if ($search !== null && trim($search) !== '') {
            $like = '%'.trim($search).'%';
            $columns[] = '(tl_member.username LIKE ? OR tl_member.email LIKE ? OR tl_member.firstname LIKE ? OR tl_member.lastname LIKE ?)';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }
        if ($group_id !== null) {
            // groups blob is a serialized list<int>. Substring-match the marker.
            // `groups` is a MySQL reserved word — backtick it.
            $columns[] = 'tl_member.`groups` LIKE ?';
            $values[] = sprintf('%%i:%d;%%', $group_id);
        }

        $newSearch = $this->filterResolver->buildSearchClause('tl_member', $q);
        if ($newSearch !== null) {
            $columns[] = $newSearch['clause'];
            $values = array_merge($values, $newSearch['params']);
        }
        try {
            $filtersArr = $this->filterResolver->normaliseFilters($filters);
            $fr = $this->filterResolver->buildFilterClauses('tl_member', $filtersArr);
            $columns = array_merge($columns, $fr['clauses']);
            $values = array_merge($values, $fr['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_filter', 'message' => $e->getMessage()];
        }
        try {
            $ts = $this->filterResolver->buildTstampRange('tl_member', $updated_after, $updated_before);
            $columns = array_merge($columns, $ts['clauses']);
            $values = array_merge($values, $ts['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $items = MemberModel::findBy(
            $columns === [] ? null : $columns,
            $values === [] ? null : $values,
            ['order' => 'tl_member.username', 'limit' => $limit, 'offset' => $offset],
        );

        $out = [];
        foreach ($items ?? [] as $m) {
            $out[] = Serializer::summary($m);
        }

        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    // ───────────────────────────── get ──────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'member_get',
        description: 'Returns a single tl_member row by id, with every safe column (password, secret, session, 2FA-state etc. are deliberately omitted from the response).',
    )]
    public function get(int $id): array
    {
        $this->framework->initialize();

        $member = MemberModel::findByPk($id);
        if ($member === null) {
            return ['error' => 'not_found', 'message' => sprintf('No member with id %d', $id)];
        }

        return Serializer::full($member);
    }

    // ──────────────────────────── create ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'member_create',
        description: <<<'DESC'
            Creates a new tl_member row.

            Required fields (all in `fields` object):
              - username, email, firstname, lastname, password (plain-text, hashed internally)

            Optional fields:
              - login (bool, default false — false = cannot log in)
              - active (bool, default true — false sets `disable=1`)
              - gender ("male"|"female"|"other"|""), date_of_birth (YYYY-MM-DD), language (locale)
              - company, street, postal, city, state, country (ISO code)
              - phone, mobile, fax, website
              - groups (list<int> of tl_member_group.id — call member_groups_list first)
              - assign_dir (bool), home_dir (path to files/ subfolder)
              - start, stop (account valid timeframe, ISO date)
        DESC,
    )]
    /**
     * @param object|null $fields Member columns as a JSON object. Required: username, email, firstname, lastname, password.
     */
    public function create(#[Schema(type: 'object')] mixed $fields = null): array
    {
        $this->framework->initialize();

        $input = self::normaliseFields($fields);
        if ($input === []) {
            return ['error' => 'invalid_input', 'message' => 'fields must be a non-empty JSON object'];
        }

        $member = new MemberModel();
        $member->tstamp = time();
        $member->dateAdded = time();
        $member->disable = 0;
        $member->login = 0;

        $result = $this->mapper->apply($member, $input, isCreate: true);
        if ($result['errors'] !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $result['errors']];
        }
        if ($result['applied'] === 0) {
            return [
                'error' => 'no_mappable_fields',
                'message' => 'No mappable fields were applied — submitted keys were unknown or rejected.',
                'submitted_keys' => array_keys($input),
            ];
        }

        try {
            $member->save();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage()];
        }

        $this->bootVersions((int) $member->id)->create();
        $this->log(sprintf('Created member "%s" (id=%d)', $member->username, (int) $member->id), __METHOD__);

        return ['created' => true, 'id' => (int) $member->id] + Serializer::full($member);
    }

    // ──────────────────────────── update ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'member_update',
        description: <<<'DESC'
            Updates a tl_member row. Pass id, then `fields` as a JSON OBJECT.

            Any key from member_create can be updated. Omitting `password` keeps the
            existing hash. Setting `active=false` blocks login; `login=false` blocks it
            even more thoroughly (entire account is non-loginable).
        DESC,
    )]
    /**
     * @param object|null $fields Member columns to change as a JSON object. Example: {"active": false}.
     */
    public function update(int $id, #[Schema(type: 'object')] mixed $fields = null): array
    {
        $this->framework->initialize();

        $member = MemberModel::findByPk($id);
        if ($member === null) {
            return ['error' => 'not_found', 'message' => sprintf('No member with id %d', $id)];
        }

        $input = self::normaliseFields($fields);
        if ($input === []) {
            return ['error' => 'no_fields', 'message' => 'fields must be a non-empty JSON object'];
        }

        $before = UpdateDiff::snapshot($member);

        $result = $this->mapper->apply($member, $input, isCreate: false);
        if ($result['errors'] !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $result['errors']];
        }
        if ($result['applied'] === 0) {
            return [
                'error' => 'no_mappable_fields',
                'message' => 'No mappable fields were applied — submitted keys were unknown or rejected.',
                'submitted_keys' => array_keys($input),
            ];
        }

        // `active` inverts onto `disable`; `login` inverts onto `login` (no
        // rename, just bool); password fields are write-only — comparing
        // snapshot vs new value lets us catch true no-ops without leaking
        // password material.
        $publicToColumn = [
            'active' => 'disable',
            'home_dir' => 'homeDir',
            'date_of_birth' => 'dateOfBirth',
            'add_details' => 'addDetails',
        ];
        $changedFields = UpdateDiff::diff($member, $before, $publicToColumn, array_keys($input));
        if ($changedFields === []) {
            return [
                'updated' => false,
                'id' => $id,
                'changed_fields' => [],
                'applied' => 0,
            ] + Serializer::full($member);
        }

        $versions = $this->bootVersions($id);
        $member->tstamp = time();
        $member->save();
        $versions->create();

        $this->log(sprintf('Updated member "%s" (id=%d, fields=%s)', $member->username, $id, implode(',', $changedFields)), __METHOD__);

        return [
            'updated' => true,
            'id' => $id,
            'changed_fields' => $changedFields,
            'applied' => \count($changedFields),
        ] + Serializer::full($member);
    }

    // ──────────────────────────── delete ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'member_delete',
        description: 'Deletes a tl_member row. Hard-delete — there is no undo. For a soft-disable use member_update with {"active": false}. Requires confirm_destructive=true to proceed.',
    )]
    public function delete(int $id, bool $confirm_destructive = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'member_delete is irreversible. Pass confirm_destructive=true to proceed.',
            ];
        }

        $member = MemberModel::findByPk($id);
        if ($member === null) {
            return ['error' => 'not_found', 'message' => sprintf('No member with id %d', $id)];
        }

        $this->bootVersions($id);
        $username = (string) $member->username;
        $member->delete();

        $this->log(sprintf('Deleted member "%s" (id=%d)', $username, $id), __METHOD__);

        return ['deleted' => true, 'id' => $id];
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
                throw new \InvalidArgumentException('`fields` must be a JSON object, not a list.');
            }
            return $fields;
        }

        throw new \InvalidArgumentException('`fields` must be a JSON object.');
    }

    private function bootVersions(int $id): Versions
    {
        $v = new Versions('tl_member', $id);
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
