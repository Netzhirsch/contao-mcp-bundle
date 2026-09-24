<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Security;

use Contao\Controller;
use Contao\DataContainer;
use Contao\Database;
use Contao\StringUtil;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Service\McpCallContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Enforces that an OAuth-authenticated MCP caller may only do through the MCP
 * server what their Contao backend account is allowed to do — by asking
 * Contao's OWN security voters (`contao_dc.<table>` with Create/Read/Update/
 * DeleteAction, plus `contao_user.alexf` for field-level edits).
 *
 * Two gates:
 *   1. {@see ensureMcpAccess()} — coarse on/off (netzhirschMcpAccess flag).
 *   2. {@see ensureCan()} — per-operation parity (table + row + field level).
 *
 * Trusted mode: when there is no authenticated user (auth_mode=none — the
 * deployment is a loopback/dev or otherwise trusted setup), every check is a
 * no-op. The per-user model only applies under auth_mode=oauth, where a real
 * tl_user id is in the call context.
 *
 * Denials are returned as structured error arrays (never thrown), so the tool
 * layer hands them straight back to the client as a normal JSON-RPC result.
 */
final class McpPermissionGuard
{
    private bool $dcaLoaded = false;

    /** @var array<string, true> tables whose DCA has been loaded */
    private array $loadedTables = [];

    private bool $accessiblePagesResolved = false;

    /** @var list<int>|null memoised accessible tl_page ids (null = unrestricted) */
    private ?array $accessiblePages = null;

    /**
     * File operation → the Contao fop right it needs. Reading has no fop right
     * of its own in Contao: seeing a folder you have mounted is the mount.
     *
     * @var array<string, string>
     */
    private const FOP_PERMISSIONS = [
        // 'read' is deliberately absent: it needs the mount, nothing more.
        'upload' => ContaoCorePermissions::USER_CAN_UPLOAD_FILES,
        'rename' => ContaoCorePermissions::USER_CAN_RENAME_FILE,
        'move' => ContaoCorePermissions::USER_CAN_RENAME_FILE,
        'delete' => ContaoCorePermissions::USER_CAN_DELETE_FILE,
        'delete_recursive' => ContaoCorePermissions::USER_CAN_DELETE_RECURSIVELY,
        'edit' => ContaoCorePermissions::USER_CAN_EDIT_FILE,
        'sync' => ContaoCorePermissions::USER_CAN_SYNC_DBAFS,
    ];

    /**
     * Columns the edit mask writes without asking for their field right,
     * although their DCA marks them excluded.
     *
     * rsce_data is the storage behind the virtual fields of an RSCE element,
     * and RSCE sets every one of those fields to `exclude => false`; its save
     * callbacks then write the column. A restricted editor therefore configures
     * an RSCE element without any field right, and no backend form ever asks
     * for one on rsce_data itself. Asking for it here refused exactly the edit
     * the backend allows. The regular columns an RSCE element shows (headline,
     * text, …) are written as themselves and checked as usual.
     *
     * @var array<string, list<string>>
     */
    private const FIELDS_WITHOUT_FIELD_RIGHT = [
        'tl_content' => ['rsce_data'],
        'tl_module' => ['rsce_data'],
        'tl_form_field' => ['rsce_data'],
    ];

    /**
     * Tables that have a Contao record-level READ voter which scopes list
     * access (archive/calendar/channel/parent membership). Only these are
     * filtered by {@see filterReadable()} — a table WITHOUT such a voter would
     * "abstain" and wrongly empty the whole list.
     *
     * Scoping models NOT covered here, by design:
     *   - tl_page / tl_article → pagemount membership ({@see accessiblePageIds()},
     *     {@see mayAccessRecord()}); their voter only checks the page TYPE.
     *   - tl_url_rewrite (and other flat, globally-managed tables) → NO
     *     record-level ACL in Contao at all. The only backend gate is the
     *     owning module ("url_rewrites"), already enforced at call time by
     *     {@see ensureCan()}. A user who can open the module sees every row —
     *     so there is nothing to scope per row, and filtering it would be wrong.
     *
     * @var array<string, true>
     */
    private const VOTER_FILTERED_TABLES = [
        'tl_news' => true,
        'tl_news_archive' => true,
        'tl_calendar' => true,
        'tl_calendar_events' => true,
        'tl_faq' => true,
        'tl_faq_category' => true,
        'tl_newsletter' => true,
        'tl_newsletter_channel' => true,
        'tl_newsletter_recipients' => true,
        'tl_form' => true,
        'tl_form_field' => true,
        'tl_content' => true,
        'tl_image_size' => true,
        'tl_image_size_item' => true,
        'tl_layout' => true,
        'tl_module' => true,
        'tl_user' => true,
    ];

    public function __construct(
        private readonly McpCallContext $callContext,
        private readonly BackendUserContext $userContext,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Coarse gate: may this caller use the MCP server at all?
     *
     * @return array{error: string, message: string}|null null = allowed
     */
    public function ensureMcpAccess(): ?array
    {
        $userId = $this->callContext->getUserId();
        if ($userId === null || $userId <= 0) {
            return null; // auth_mode=none → trusted, no per-user gate
        }
        if ($this->userContext->hasMcpAccess($userId)) {
            return null;
        }

        return $this->deny(
            'mcp_access_denied',
            'Your backend account is not permitted to use the MCP server. An administrator must enable MCP access for your user or one of your groups.',
            ['user_id' => $userId],
        );
    }

    /**
     * Per-operation parity check against Contao's voters.
     *
     * @param string                   $table     Contao table, e.g. "tl_news"
     * @param string                   $operation create|read|update|delete
     * @param int|null                 $id        record id for read/update/delete
     * @param array<string,mixed>|null $newData   fields being written (create/update) — keys are field names
     * @param array<string,mixed>|null $written   the fields the caller actually WRITES, when that is not
     *                                            $newData — a copy inserts the whole source row, and only
     *                                            its overrides are the caller's. Null: $newData.
     *
     * @return array{error: string, message: string}|null null = allowed
     */
    public function ensureCan(string $table, string $operation, ?int $id = null, ?array $newData = null, ?array $written = null): ?array
    {
        $userId = $this->callContext->getUserId();
        if ($userId === null || $userId <= 0) {
            return null; // trusted mode
        }
        if ($this->userContext->isAdmin($userId)) {
            return null; // admins may do everything
        }

        $token = $this->userContext->tokenFor($userId);
        if ($token === null) {
            return $this->deny('permission_denied', 'Your backend user could not be resolved.', ['user_id' => $userId]);
        }

        // Module gate first. The generic DataContainer voter does NOT enforce
        // backend-module access for tables without a dedicated voter, so a
        // user with no module rights would otherwise slip through. In the real
        // backend you can't manage a table whose module you can't open — mirror
        // that explicitly. Running it before the record lookup also avoids
        // disclosing a record's existence to a user who can't open the module.
        $module = $this->moduleForTable($table);
        if ($module !== null) {
            $moduleDenial = $this->ensureModule($module);
            if ($moduleDenial !== null) {
                return $moduleDenial;
            }
        }

        // For record-scoped operations with a concrete id, look the record up
        // first and return a clear "not_found" — otherwise the voter denies a
        // non-existent record with a misleading "insufficient permissions".
        $record = null;
        if ($id !== null && $id > 0 && \in_array($operation, ['read', 'update', 'delete'], true)) {
            $record = $this->loadRecord($table, $id);
            if ($record === null) {
                return $this->deny(
                    'not_found',
                    sprintf('No record with id %d found in %s.', $id, $table),
                    ['table' => $table, 'id' => $id],
                );
            }
        }

        // Pagemount, centrally.
        //
        // Contao's tl_page / tl_article voter checks the page TYPE, not whether
        // the record sits under one of the user's mounts — the bundle documents
        // that above and the list/tree tools compensate by hand. The
        // single-record tools did not, so page_get/_update/_delete and
        // article_get/_update/_delete asked a mount-blind voter and were told
        // "allow": a restricted editor could walk the whole instance by id, and
        // in an agency install that crosses customers. Found by audit.
        //
        // It belongs here rather than in each tool: six call sites were missing
        // it, and the next tool would have been the seventh.
        if (\in_array($table, ['tl_page', 'tl_article'], true)) {
            $mountRow = $record;

            // On create there is no record yet — the mount that matters is the
            // one the new row would land in. The enforcer hands over the
            // ARGUMENT names, so an article arrives with `page_id` where the
            // column is `pid`.
            if ($mountRow === null && $operation === 'create' && \is_array($newData)) {
                $mountRow = $table === 'tl_article'
                    ? ['pid' => (int) ($newData['pid'] ?? $newData['page_id'] ?? 0)]
                    : ['id' => (int) ($newData['id'] ?? 0), 'pid' => (int) ($newData['pid'] ?? $newData['parent_id'] ?? 0)];

                // A new PAGE is keyed by its own id, which does not exist yet;
                // the mount that governs it is the parent's.
                if ($table === 'tl_page') {
                    $mountRow = ['id' => $mountRow['pid']];
                    if ($mountRow['id'] === 0) {
                        $mountRow = null; // new root page — the voter decides
                    }
                }
            }

            if ($mountRow !== null && !$this->mayAccessRecord($table, $mountRow)) {
                return $this->deny(
                    'permission_denied',
                    sprintf('This record in %s is outside your page mounts.', $table),
                    ['table' => $table, 'operation' => $operation, 'id' => $id],
                );
            }
        }

        // A list / table-level read (no specific record) is governed by backend
        // MODULE access, not a per-record voter. In the Contao backend anyone who
        // can open the module sees the list; building a ReadAction without a record
        // makes parent-aware voters (e.g. NewsAccessVoter) check a phantom parent
        // (pid 0) and wrongly deny. Once the module gate above has passed, a
        // record-less read is allowed. (Tables with no owning module fall through
        // to the voter below — unchanged, safe.)
        if ($operation === 'read' && $record === null && $module !== null) {
            return null;
        }

        $action = $this->buildAction($table, $operation, $id, $record, $newData);
        if ($action === null) {
            // Unknown operation → no DC voter applies. Be safe: deny rather
            // than silently allow an unmapped write.
            return $this->deny('permission_denied', sprintf('Unsupported operation "%s" on %s.', $operation, $table));
        }

        if (!$this->accessDecisionManager->decide($token, [ContaoCorePermissions::DC_PREFIX.$table], $action)) {
            return $this->deny(
                'permission_denied',
                sprintf('You are not allowed to %s this record in %s (insufficient backend permissions).', $operation, $table),
                ['table' => $table, 'operation' => $operation, 'id' => $id],
            );
        }

        // Field-level: writing an "excluded" field requires the alexf right.
        $written ??= $newData;
        if (\in_array($operation, ['create', 'update'], true) && \is_array($written) && $written !== []) {
            $fieldDenial = $this->ensureFields($token, $table, $written, $operation, $record);
            if ($fieldDenial !== null) {
                return $fieldDenial;
            }
        }

        return null;
    }

    /**
     * Filter a list of records down to those the current OAuth user may READ,
     * using Contao's OWN ReadAction voter (same parity as single-record access).
     *
     * No-op (returns the rows unchanged) for: admins, trusted mode (no user),
     * empty input, and tables without a record-level read voter (see
     * {@see VOTER_FILTERED_TABLES} — the rest is Phase 2 / query-level). This is
     * how list tools (news_list, calendar_events_list, …) avoid leaking records
     * the caller can't access in the backend.
     *
     * @param list<array<string, mixed>> $rows raw records carrying the fields the
     *                                          table's voter inspects (id, pid, …)
     *
     * @return list<array<string, mixed>>
     */
    public function filterReadable(string $table, array $rows): array
    {
        if ($rows === [] || !isset(self::VOTER_FILTERED_TABLES[$table])) {
            return array_values($rows);
        }

        $userId = $this->callContext->getUserId();
        if ($userId === null || $userId <= 0 || $this->userContext->isAdmin($userId)) {
            return array_values($rows); // trusted mode / admin → no filtering
        }

        $token = $this->userContext->tokenFor($userId);
        if ($token === null) {
            return []; // can't resolve the user → show nothing (safe)
        }

        $out = [];
        foreach ($rows as $row) {
            if (\is_array($row)
                && $this->accessDecisionManager->decide($token, [ContaoCorePermissions::DC_PREFIX.$table], new ReadAction($table, $row))
            ) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Convenience single-row variant of {@see filterReadable()} for use inside
     * a list tool's serialisation loop: returns true if the caller may READ
     * this record (always true for admins/trusted mode and voter-less tables).
     *
     * @param array<string, mixed> $row
     */
    public function mayRead(string $table, array $row): bool
    {
        return $this->filterReadable($table, [$row]) !== [];
    }

    /**
     * The set of tl_page ids the current OAuth user may access: their
     * (group-merged) pagemounts plus all recursive descendants. Returns null
     * for admins / trusted mode (= no restriction), [] for a user without
     * pagemounts. Memoised per request.
     *
     * Pages/articles have no record-level read voter that honours pagemounts
     * (Contao's tl_page voter only checks the page TYPE), so list tools scope
     * `tl_page`/`tl_article` results with this instead of {@see filterReadable()}.
     *
     * @return list<int>|null null = unrestricted
     */
    public function accessiblePageIds(): ?array
    {
        if ($this->accessiblePagesResolved) {
            return $this->accessiblePages;
        }
        $this->accessiblePagesResolved = true;

        $userId = $this->callContext->getUserId();
        if ($userId === null || $userId <= 0 || $this->userContext->isAdmin($userId)) {
            return $this->accessiblePages = null; // trusted mode / admin → all pages
        }

        $token = $this->userContext->tokenFor($userId);
        $user = $token?->getUser();
        if ($user === null) {
            return $this->accessiblePages = [];
        }

        $raw = $user->pagemounts ?? null;
        $mounts = \is_array($raw) ? $raw : StringUtil::deserialize($raw, true);
        $mounts = array_values(array_filter(array_map('intval', (array) $mounts), static fn (int $i): bool => $i > 0));
        if ($mounts === []) {
            return $this->accessiblePages = [];
        }

        // Pagemounts grant the root pages PLUS their whole subtree.
        $this->framework->initialize();
        try {
            $children = Database::getInstance()->getChildRecords($mounts, 'tl_page');
        } catch (\Throwable) {
            $children = [];
        }

        return $this->accessiblePages = array_values(array_unique([...$mounts, ...array_map('intval', $children)]));
    }

    /**
     * Unified "may the current caller access (see/edit) this record?" check
     * for tools that touch records BY ID without running them through a list
     * voter — e.g. the changelanguage linkers ({@see language_link_pages},
     * {@see entity_language_link}), which write `languageMain` to entities the
     * caller picks by id. Combines the two scoping models the bundle uses:
     *
     *   - tl_page / tl_article → pagemount membership ({@see accessiblePageIds()}).
     *     The tl_page voter only checks the page TYPE, so the per-record voter
     *     alone would NOT stop a write to a page outside the caller's mounts.
     *   - voter-scoped tables ({@see VOTER_FILTERED_TABLES}) → Contao's own
     *     ReadAction voter (which, for these tables, also requires the
     *     edit-archive/-calendar/-channel right, so read ≈ edit authorisation).
     *   - everything else → no record-level ACL; the owning module gate is the
     *     only backend restriction and is enforced at call time → allowed.
     *
     * Always true for admins / trusted mode (no per-user restriction).
     *
     * @param array<string, mixed> $row record carrying id/pid (+ any fields the
     *                                  table's voter inspects)
     */
    public function mayAccessRecord(string $table, array $row): bool
    {
        if ($table === 'tl_page' || $table === 'tl_article') {
            $accessible = $this->accessiblePageIds();
            if ($accessible === null) {
                return true; // admin / trusted → unrestricted
            }
            // A page is keyed by its own id; an article by the page it sits on.
            $pageId = $table === 'tl_page' ? (int) ($row['id'] ?? 0) : (int) ($row['pid'] ?? 0);

            return \in_array($pageId, $accessible, true);
        }

        if (isset(self::VOTER_FILTERED_TABLES[$table])) {
            return $this->mayRead($table, $row);
        }

        // No record-level ACL in Contao (e.g. tl_url_rewrite): the module gate
        // is the only backend restriction and runs at call time.
        return true;
    }

    /**
     * The two backend gates on the file manager that `module: files` does not
     * cover: WHERE a user may work (filemounts) and WHAT they may do there
     * (the fop rights f1–f6).
     *
     * `tl_files` is not a DataContainer table in the voter sense, so no record
     * voter runs for it and the module gate was the whole check. A user
     * confined to `files/kundeA/` could read, overwrite and delete anything
     * under `files/` — in an agency install that is another customer's
     * contracts. Found by audit.
     *
     * Contao already answers both questions; this only asks them. Paths are
     * DBAFS-shaped (`files/…`), which is what USER_CAN_ACCESS_PATH compares
     * against.
     *
     * @param list<string> $paths every path the operation touches — for a move
     *                            or rename that is the source AND the target,
     *                            because landing a file somewhere you may not
     *                            write is the same problem in reverse
     *
     * @return array{error: string, message: string}|null
     */
    public function ensureFileAccess(array $paths, string $operation): ?array
    {
        $userId = $this->callContext->getUserId();
        if ($userId === null || $userId <= 0) {
            return null; // trusted mode
        }
        if ($this->userContext->isAdmin($userId)) {
            return null;
        }

        $moduleDenial = $this->ensureModule('files');
        if ($moduleDenial !== null) {
            return $moduleDenial;
        }

        $token = $this->userContext->tokenFor($userId);
        if ($token === null) {
            return $this->deny('permission_denied', 'Your backend user could not be resolved.', ['user_id' => $userId]);
        }

        foreach ($paths as $path) {
            $path = ltrim(str_replace('\\', '/', $path), '/');
            if ($path === '') {
                continue; // the upload root itself — the mount check below covers its children
            }

            if (!$this->accessDecisionManager->decide($token, [ContaoCorePermissions::USER_CAN_ACCESS_PATH], [$path])) {
                return $this->deny(
                    'permission_denied',
                    sprintf('"%s" is outside your file mounts.', $path),
                    ['path' => $path],
                );
            }
        }

        $required = self::FOP_PERMISSIONS[$operation] ?? null;

        if ($required !== null && !$this->accessDecisionManager->decide($token, [$required])) {
            return $this->deny(
                'permission_denied',
                sprintf('You do not have the "%s" file-operation right.', $operation),
                ['operation' => $operation],
            );
        }

        return null;
    }

    /**
     * Admin-only gate (for system settings, maintenance, unmapped tools).
     *
     * @return array{error: string, message: string}|null
     */
    public function ensureAdmin(string $message): ?array
    {
        $userId = $this->callContext->getUserId();
        if ($userId === null || $userId <= 0) {
            return null; // trusted mode
        }
        if ($this->userContext->isAdmin($userId)) {
            return null;
        }

        return $this->deny('permission_denied', $message, ['user_id' => $userId]);
    }

    /**
     * Coarse, record-free check: may the current caller access the backend
     * module that owns this table? Used for tools/list + discovery visibility
     * (no record id is available at list time, so this is module-level only —
     * row/field-level stays enforced at call time via {@see ensureCan()}).
     */
    public function canAccessTableModule(string $table): bool
    {
        $userId = $this->callContext->getUserId();
        if ($userId === null || $userId <= 0) {
            return true; // trusted mode
        }
        if ($this->userContext->isAdmin($userId)) {
            return true;
        }
        $module = $this->moduleForTable($table);
        if ($module === null) {
            // No module claims the table — can't gate at list time; the
            // per-record voter still applies when the tool is actually called.
            return true;
        }

        return $this->ensureModule($module) === null;
    }

    /**
     * Coarse "is the caller an admin / unauthenticated" check for visibility
     * of admin-only and unmapped tools.
     */
    public function isAdminOrTrusted(): bool
    {
        return $this->ensureAdmin('') === null;
    }

    /**
     * Backend-module access gate (e.g. "files", "tpl_editor", "user").
     *
     * @return array{error: string, message: string}|null
     */
    public function ensureModule(string $module): ?array
    {
        $userId = $this->callContext->getUserId();
        if ($userId === null || $userId <= 0) {
            return null;
        }
        if ($this->userContext->isAdmin($userId)) {
            return null;
        }
        $token = $this->userContext->tokenFor($userId);
        if ($token === null) {
            return $this->deny('permission_denied', 'Your backend user could not be resolved.', ['user_id' => $userId]);
        }
        if (!$this->accessDecisionManager->decide($token, [ContaoCorePermissions::USER_CAN_ACCESS_MODULE], $module)) {
            return $this->deny(
                'permission_denied',
                sprintf('You do not have access to the "%s" backend module.', $module),
                ['module' => $module],
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $record  pre-loaded record (null = not looked up / table-level)
     * @param array<string, mixed>|null $newData
     */
    private function buildAction(string $table, string $operation, ?int $id, ?array $record, ?array $newData): CreateAction|ReadAction|UpdateAction|DeleteAction|null
    {
        $current = $record ?? ['id' => $id];

        return match ($operation) {
            'create' => new CreateAction($table, $newData ?? []),
            'read' => new ReadAction($table, $current),
            'update' => new UpdateAction($table, $current, $newData),
            'delete' => new DeleteAction($table, $current),
            default => null,
        };
    }

    /**
     * Load a record by id, or null if it does not exist.
     *
     * @return array<string, mixed>|null
     */
    private function loadRecord(string $table, ?int $id): ?array
    {
        if ($id === null || $id <= 0) {
            return null;
        }
        // Defence in depth: for `dc_arg` tools the table name originates from the
        // CALL ARGUMENTS (ToolPermissionMap::hydrate validates the shape), and
        // this runs before the dispatcher validates the schema. Re-assert the
        // identifier here and quote it, so no caller-controlled string can ever
        // reach the SQL unquoted.
        if (preg_match('/^tl_[a-z0-9_]+$/', $table) !== 1) {
            return null;
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT * FROM '.$this->connection->quoteIdentifier($table).' WHERE id = ?',
                [$id],
            );
        } catch (\Throwable) {
            $row = false;
        }

        return $row === false ? null : $row;
    }

    /**
     * Writing an excluded field needs the alexf right — the rule the backend
     * applies when it builds an edit form.
     *
     * "Excluded" is Contao's answer (DataContainer::isFieldExcluded), not the
     * `exclude` key alone. Since Contao 5.0 every field with an input counts as
     * excluded unless its DCA says `exclude => false`, and the core DCAs no
     * longer set the key at all. Reading `exclude ?? false` — the Contao 4
     * default — therefore checked nothing on tl_content, tl_article, tl_news or
     * any other core table: a restricted editor could write through MCP fields
     * their backend form never shows them.
     *
     * A value that changes nothing is not a write. On an update that is the
     * stored value; on a create, the value a new record starts with (the DCA
     * default, else the column default). The backend's own create sets those
     * without asking, so content_create(type: "text") needs no right to the
     * type field, exactly like the "new element" button. null means "not sent"
     * to every write tool.
     *
     * @param array<array-key, mixed>   $values field => value being written
     * @param array<string, mixed>|null $record the stored row (update)
     *
     * @return array{error: string, message: string}|null
     */
    private function ensureFields(object $token, string $table, array $values, string $operation, ?array $record): ?array
    {
        $this->loadDca($table);
        $dcaFields = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];
        $dataContainer = $this->framework->getAdapter(DataContainer::class);

        foreach ($values as $field => $value) {
            $field = (string) $field;

            if ($value === null
                || \in_array($field, self::FIELDS_WITHOUT_FIELD_RIGHT[$table] ?? [], true)
                || !$dataContainer->isFieldExcluded($table, $field)
            ) {
                continue;
            }

            $unchanged = match ($operation) {
                'create' => self::isNewRecordValue(\is_array($dcaFields[$field] ?? null) ? $dcaFields[$field] : [], $value),
                default => $record !== null && \array_key_exists($field, $record) && self::sameValue($value, $record[$field]),
            };
            if ($unchanged) {
                continue;
            }

            if (!$this->accessDecisionManager->decide($token, [ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE], $table.'::'.$field)) {
                return $this->deny(
                    'permission_denied',
                    sprintf('You are not allowed to edit the field "%s" of %s.', $field, $table),
                    ['table' => $table, 'field' => $field],
                );
            }
        }

        return null;
    }

    /**
     * Whether $value is what a freshly created record holds in this field:
     * the DCA `default` (what DC_Table::create() fills in), otherwise the
     * column default. A default computed per request (a closure — the current
     * date, the current user) cannot be matched and counts as a write.
     *
     * @param array<string, mixed> $config the field's DCA definition
     */
    private static function isNewRecordValue(array $config, mixed $value): bool
    {
        if (\array_key_exists('default', $config)) {
            $default = $config['default'];

            return !$default instanceof \Closure
                && self::sameValue($value, \is_array($default) ? serialize($default) : $default);
        }

        $sql = $config['sql'] ?? null;

        if (\is_array($sql)) {
            return \array_key_exists('default', $sql) && self::sameValue($value, $sql['default']);
        }

        // "varchar(32) NOT NULL default 'main'", "int(10) unsigned NOT NULL default 0", "… default NULL"
        if (\is_string($sql) && preg_match("/\\bdefault\\s+(?:'((?:[^'\\\\]|\\\\.)*)'|(null)\\b|(-?\\d+(?:\\.\\d+)?))/i", $sql, $m) === 1) {
            if (($m[2] ?? '') !== '') {
                return self::sameValue($value, null);
            }
            if (($m[3] ?? '') !== '') {
                return self::sameValue($value, $m[3]);
            }

            return self::sameValue($value, stripslashes($m[1]));
        }

        return false;
    }

    /**
     * Loose enough for a checkbox (true against '1' / 1, false against '' / '0'
     * / 0, however the column stores it), strict for everything else. A value
     * that is not scalar — a headline object, a list — never counts as
     * unchanged: comparing it against the serialised column would be a guess.
     */
    private static function sameValue(mixed $value, mixed $current): bool
    {
        $stored = match (true) {
            $current === null => '',
            \is_bool($current) => $current ? '1' : '',
            \is_scalar($current) => (string) $current,
            default => null,
        };

        if ($stored === null) {
            return false;
        }

        if (\is_bool($value)) {
            return \in_array($stored, $value ? ['1'] : ['', '0'], true);
        }

        return \is_scalar($value) && (string) $value === $stored;
    }

    /** @var array<string, string>|null table → owning backend-module name */
    private ?array $tableModuleMap = null;

    /**
     * The backend module that owns a table (from $GLOBALS['BE_MOD']), or null
     * if no module claims it. Used to enforce module access before the
     * per-record voter check.
     */
    private function moduleForTable(string $table): ?string
    {
        if ($this->tableModuleMap === null) {
            $this->framework->initialize();
            $map = [];
            foreach (($GLOBALS['BE_MOD'] ?? []) as $modules) {
                if (!\is_array($modules)) {
                    continue;
                }
                foreach ($modules as $module => $config) {
                    foreach ((array) ($config['tables'] ?? []) as $tableName) {
                        if (\is_string($tableName) && !isset($map[$tableName])) {
                            $map[$tableName] = (string) $module;
                        }
                    }
                }
            }
            $this->tableModuleMap = $map;
        }

        return $this->tableModuleMap[$table] ?? null;
    }

    private function loadDca(string $table): void
    {
        if (isset($this->loadedTables[$table])) {
            return;
        }
        if (!$this->dcaLoaded) {
            $this->framework->initialize();
            $this->dcaLoaded = true;
        }
        $this->framework->getAdapter(Controller::class)->loadDataContainer($table);
        $this->loadedTables[$table] = true;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array{error: string, message: string}
     */
    private function deny(string $code, string $message, array $context = []): array
    {
        $this->logger->info('MCP permission denied.', ['code' => $code] + $context);

        return ['error' => $code, 'message' => $message];
    }
}
