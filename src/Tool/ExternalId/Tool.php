<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\ExternalId;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Backend\ExternalIdDcaInjector;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use PhpMcp\Server\Attributes\McpTool;
use Psr\Log\LoggerInterface;

/**
 * Maps caller-chosen external identifiers to Contao row ids.
 *
 * Use case: automation pipelines (manifest-driven builders, Skill-2-style
 * agents, sync-with-external-system jobs) need idempotent re-runs — given
 * the same input twice they must NOT create duplicate Contao rows. A local
 * state file works but couples the caller to file storage; this tool moves
 * that bookkeeping into Contao itself so a re-run from a fresh checkout
 * still finds its previously-created rows.
 *
 * Storage model — DECENTRAL (since v0.2.0-beta1):
 *   Two columns per supported table: `external_id_namespace` (VARCHAR 64),
 *   `external_id_key` (VARCHAR 255). UNIQUE per table on (ns, key). NULL on
 *   both = "this row is not managed". When the Contao backend deletes the
 *   row, the mapping disappears with it (cascade for free).
 *
 *   Previous central model (`tl_mcp_external_ref`) was dropped — see the
 *   v0.2.0-beta1 migration in src/Migration.
 *
 * Conflict semantics (briefing §6.2 / §6.3):
 *   `set` REJECTS rebinding by default. If (ns, key) is already on a
 *   different row in the same table, the caller has to `unset` first.
 *   Set-on-same-row with same key is a no-op (idempotent).
 *
 * Pattern:
 *   1. existing = external_id_lookup(ns, key, table)
 *   2. if existing.found  →  <entity>_update(existing.row_id, ...)
 *      else              →  new = <entity>_create(...); external_id_set(ns, key, table, new.id)
 */
final class Tool
{
    /**
     * Tables the tool will read/write. Must stay in sync with
     * {@see ExternalIdDcaInjector::SUPPORTED_TABLES} (we re-use that constant
     * to guarantee both stay aligned).
     *
     * @var list<string>
     */
    public const SUPPORTED_TABLES = ExternalIdDcaInjector::SUPPORTED_TABLES;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly McpPermissionGuard $guard,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'external_id_set',
        description: <<<'DESC'
            Binds an external identifier to a Contao row by writing the
            external_id_namespace + external_id_key columns on the row itself.

            Conflict semantics:
              - Same (namespace, key) already on the SAME row_id → no-op
                (idempotent, returns updated=false).
              - Same (namespace, key) already on a DIFFERENT row_id in the same
                table → error "mapping_conflict". Caller must external_id_unset
                first if the rebind is intentional.
              - row_id has its OWN existing (namespace, key) pair (different
                from what you're setting) → error "row_already_mapped". Caller
                must external_id_unset the row's current binding first.

            Returns {ok: true, created: bool, updated: bool, table, row_id,
            namespace, external_key} on success; {error, message, …} on
            conflict.

            Parameters:
              - namespace:    short caller-chosen partition, e.g. "skill2-builder"
              - external_key: stable id inside that namespace, e.g.
                              "kunde-mueller:el.hero.intro"
              - table:        Contao table — must be in the supported list
                              (run external_ids_list with no args to discover).
              - row_id:       primary key in `table`. Row must exist.
        DESC,
    )]
    public function set(string $namespace, string $external_key, string $table, int $row_id): array
    {
        $this->framework->initialize();

        if (($err = $this->validateInput($namespace, $external_key, $table)) !== null) {
            return $err;
        }
        if ($row_id <= 0) {
            return ['error' => 'invalid_input', 'message' => 'row_id must be a positive integer.'];
        }

        $qTable = $this->connection->quoteIdentifier($table);

        // Target row must exist — without this check we'd write to nothing
        // (and on MySQL silently affect 0 rows; on a typo we'd lose the data).
        $current = $this->connection->fetchAssociative(
            'SELECT id, external_id_namespace, external_id_key FROM '.$qTable.' WHERE id = ?',
            [$row_id],
        );

        if ($current === false) {
            return [
                'error' => 'row_not_found',
                'message' => sprintf('No row with id=%d in %s.', $row_id, $table),
            ];
        }

        $currentNs = $current['external_id_namespace'];
        $currentKey = $current['external_id_key'];

        // Case A: row already has the same mapping — idempotent no-op.
        if ((string) $currentNs === $namespace && (string) $currentKey === $external_key) {
            return [
                'ok' => true,
                'created' => false,
                'updated' => false,
                'table' => $table,
                'row_id' => $row_id,
                'namespace' => $namespace,
                'external_key' => $external_key,
            ];
        }

        // Case B: row owns a DIFFERENT mapping. Caller must explicitly unset
        // first — we never silently overwrite a different binding.
        if (($currentNs !== null && $currentNs !== '') || ($currentKey !== null && $currentKey !== '')) {
            return [
                'error' => 'row_already_mapped',
                'message' => sprintf(
                    'Row %s#%d already has external mapping (%s/%s). Call external_id_unset(table=%s, row_id=%d) first if you want to rebind.',
                    $table,
                    $row_id,
                    (string) $currentNs,
                    (string) $currentKey,
                    $table,
                    $row_id,
                ),
                'current_namespace' => (string) $currentNs,
                'current_external_key' => (string) $currentKey,
            ];
        }

        // Case C: a different row in the same table owns (ns, key). UNIQUE
        // index would throw at INSERT; we pre-check for a clean error message.
        $conflict = $this->connection->fetchOne(
            'SELECT id FROM '.$qTable.' WHERE external_id_namespace = ? AND external_id_key = ?',
            [$namespace, $external_key],
        );
        if ($conflict !== false && (int) $conflict !== $row_id) {
            return [
                'error' => 'mapping_conflict',
                'message' => sprintf(
                    'Mapping (%s/%s) is already in use by %s#%d. Call external_id_unset(namespace=%s, external_key=%s, table=%s) first if you want to rebind.',
                    $namespace,
                    $external_key,
                    $table,
                    (int) $conflict,
                    $namespace,
                    $external_key,
                    $table,
                ),
                'conflicting_row_id' => (int) $conflict,
            ];
        }

        // Clean slate — write the mapping.
        $this->connection->update(
            $table,
            [
                'external_id_namespace' => $namespace,
                'external_id_key' => $external_key,
            ],
            ['id' => $row_id],
        );

        $this->log(
            sprintf('external_id_set [%s/%s] → %s#%d', $namespace, $external_key, $table, $row_id),
            __METHOD__,
        );

        return [
            'ok' => true,
            'created' => true,
            'updated' => false,
            'table' => $table,
            'row_id' => $row_id,
            'namespace' => $namespace,
            'external_key' => $external_key,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'external_id_lookup',
        description: <<<'DESC'
            Resolves an external identifier to its current Contao row id.
            Returns {found: bool, row_id?: int, table?: string} — cheap
            indexed SELECT, call as often as needed before
            *_create / *_update decisions.

            Idempotency pattern:
              existing = external_id_lookup("skill2", "theme.acme", "tl_theme")
              if existing.found:
                  theme_update(existing.row_id, ...)
              else:
                  new = theme_create(name="acme", ...)
                  external_id_set("skill2", "theme.acme", "tl_theme", new.id)
        DESC,
    )]
    public function lookup(string $namespace, string $external_key, string $table): array
    {
        $this->framework->initialize();

        if (($err = $this->validateInput($namespace, $external_key, $table)) !== null) {
            return $err;
        }

        $row = $this->connection->fetchOne(
            'SELECT id FROM '.$this->connection->quoteIdentifier($table).'
             WHERE external_id_namespace = ? AND external_id_key = ?',
            [$namespace, $external_key],
        );

        if ($row === false) {
            return ['found' => false];
        }

        // An id the caller may not open in the backend must not come back here
        // either — otherwise the external key becomes an oracle for the
        // existence and primary key of records behind their access boundary
        // (audit F16). "Not found" is the honest answer: for this caller, it
        // is not there.
        if (!$this->maySee($table, (int) $row)) {
            return ['found' => false];
        }

        return [
            'found' => true,
            'row_id' => (int) $row,
            'table' => $table,
            'namespace' => $namespace,
            'external_key' => $external_key,
        ];
    }

    /**
     * Whether the caller may see one record — the same question
     * {@see McpPermissionGuard::mayAccessRecord()} answers, with the row loaded
     * for it. The guard needs the record's own columns (a pagemount is decided
     * by an article's `pid`, a voter by whatever it inspects), and the caller
     * only has an id.
     */
    private function maySee(string $table, int $id): bool
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM '.$this->connection->quoteIdentifier($table).' WHERE id = ?',
            [$id],
        );

        return \is_array($row) && $this->guard->mayAccessRecord($table, $row);
    }

    /**
     * Resolve a page of UNION rows to the ids the caller may see, one query per
     * table rather than one per row.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array<int, true>> table → set of visible ids
     */
    private function visibleIdsByTable(array $rows): array
    {
        $idsByTable = [];
        foreach ($rows as $r) {
            $idsByTable[(string) $r['tbl']][] = (int) $r['id'];
        }

        $visible = [];

        foreach ($idsByTable as $table => $ids) {
            $ids = array_values(array_unique($ids));
            $placeholders = implode(',', array_fill(0, \count($ids), '?'));
            $records = $this->connection->fetchAllAssociative(
                'SELECT * FROM '.$this->connection->quoteIdentifier($table).' WHERE id IN ('.$placeholders.')',
                $ids,
            );

            foreach ($records as $record) {
                if ($this->guard->mayAccessRecord($table, $record)) {
                    $visible[$table][(int) $record['id']] = true;
                }
            }
        }

        return $visible;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'external_id_unset',
        description: <<<'DESC'
            Clears the external-id columns on the row currently bound to
            (namespace, external_key) in the given table. No row_id needed —
            the binding itself is the lookup.

            Use after deleting (or before deleting) the underlying Contao row
            so the mapping is gone before the row is. Or before rebinding
            with external_id_set when you want to move the same external_key
            to a different row.

            Returns {ok: true, was_set: bool, row_id?: int}. was_set=false
            means no mapping was found in the table — not an error.
        DESC,
    )]
    public function unset(string $namespace, string $external_key, string $table): array
    {
        $this->framework->initialize();

        if (($err = $this->validateInput($namespace, $external_key, $table)) !== null) {
            return $err;
        }

        $qTable = $this->connection->quoteIdentifier($table);

        $rowId = $this->connection->fetchOne(
            'SELECT id FROM '.$qTable.' WHERE external_id_namespace = ? AND external_id_key = ?',
            [$namespace, $external_key],
        );

        if ($rowId === false) {
            return ['ok' => true, 'was_set' => false];
        }

        $this->connection->update(
            $table,
            [
                'external_id_namespace' => null,
                'external_id_key' => null,
            ],
            ['id' => (int) $rowId],
        );

        $this->log(
            sprintf('external_id_unset [%s/%s] removed from %s#%d', $namespace, $external_key, $table, (int) $rowId),
            __METHOD__,
        );

        return ['ok' => true, 'was_set' => true, 'row_id' => (int) $rowId];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'external_ids_list',
        description: <<<'DESC'
            Returns all external-id mappings in a namespace, optionally
            filtered by table. Without `table`, runs a UNION across every
            supported table — cheaper than it sounds because each table has
            an indexed (ns, key) UNIQUE and the namespace filter usually
            scans few rows.

            Call with `namespace=null, table=null` to discover the supported
            table list (returned in `supported_tables`).

            Returns {items: list<{namespace, external_key, table, row_id}>,
            count, supported_tables}.
        DESC,
    )]
    public function listMappings(
        ?string $namespace = null,
        ?string $table = null,
        int $limit = 200,
        int $offset = 0,
    ): array {
        $this->framework->initialize();

        $limit = max(1, min($limit, 1000));
        $offset = max(0, $offset);

        $supported = self::SUPPORTED_TABLES;

        // No filters at all → return only the metadata; caller is just
        // probing what's available.
        if ($namespace === null && $table === null) {
            return [
                'items' => [],
                'count' => 0,
                'supported_tables' => $supported,
                'hint' => 'Pass `namespace` (and optionally `table`) to see actual mappings.',
            ];
        }

        if ($table !== null) {
            if (!\in_array($table, $supported, true)) {
                return ['error' => 'unsupported_table', 'message' => sprintf('%s is not in the supported list.', $table), 'supported_tables' => $supported];
            }
            if (!$this->tableExists($table)) {
                return [
                    'error' => 'extension_not_available',
                    'message' => sprintf('%s is in the supported list but the table does not exist — the host extension is not installed.', $table),
                    'table' => $table,
                ];
            }
            $tables = [$table];
        } else {
            // UNION-mode: silently skip tables whose extension is not
            // installed (e.g. tl_url_rewrite when terminal42/contao-url-rewrite
            // is absent). Without this filter the UNION would crash on
            // "table doesn't exist" mid-query. Operator-friendly because
            // calling `list` shouldn't require knowing which extensions
            // happen to be wired up.
            $tables = array_values(array_filter(
                $supported,
                fn (string $t): bool => $this->tableExists($t),
            ));
        }

        $unionParts = [];
        $params = [];
        foreach ($tables as $t) {
            $q = $this->connection->quoteIdentifier($t);
            $unionParts[] = 'SELECT '.$this->connection->quote($t).' AS tbl, id, external_id_namespace AS ns, external_id_key AS k
                            FROM '.$q.'
                            WHERE external_id_namespace IS NOT NULL'
                            .($namespace !== null ? ' AND external_id_namespace = ?' : '');
            if ($namespace !== null) {
                $params[] = $namespace;
            }
        }

        $sql = '('.implode(') UNION ALL (', $unionParts).')
               ORDER BY ns, tbl, k
               LIMIT '.$limit.' OFFSET '.$offset;

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        // The UNION spans every supported table with no notion of who is
        // asking, so a restricted caller could enumerate the existence,
        // primary key and business key of records they cannot open — including
        // tl_member and tl_page (audit F16). Filter the page down to what this
        // caller may actually see, in the order the query returned it.
        $items = [];
        $visible = $this->visibleIdsByTable($rows);

        foreach ($rows as $r) {
            if (!isset($visible[(string) $r['tbl']][(int) $r['id']])) {
                continue;
            }

            $items[] = [
                'namespace' => (string) $r['ns'],
                'external_key' => (string) $r['k'],
                'table' => (string) $r['tbl'],
                'row_id' => (int) $r['id'],
            ];
        }

        $result = [
            'items' => $items,
            'count' => \count($items),
            'supported_tables' => $supported,
            'limit' => $limit,
            'offset' => $offset,
        ];

        // Say so rather than letting a short page read as "that is all there
        // is" — the caller would otherwise page forward believing the mapping
        // is complete.
        $hidden = \count($rows) - \count($items);
        if ($hidden > 0) {
            $result['hidden_by_permissions'] = $hidden;
        }

        return $result;
    }

    /**
     * Shared validation. Returns null on success, error array on failure.
     *
     * Reserves length limits matching the DCA column declarations in
     * ExternalIdDcaInjector — VARCHAR(64) for namespace, VARCHAR(255) for key.
     *
     * Also gates against tables that ARE supported in principle but whose
     * host extension is not installed (e.g. `tl_url_rewrite` when the
     * terminal42/contao-url-rewrite bundle is absent — the DCA injector
     * never fires for those, so the table doesn't exist in the DB). The
     * single-row tools (set / lookup / unset) would otherwise hit a raw
     * `Table doesn't exist` SQL error.
     *
     * @return array{error: string, message: string, supported_tables?: list<string>}|null
     */
    private function validateInput(string $namespace, string $external_key, string $table): ?array
    {
        if (trim($namespace) === '' || \strlen($namespace) > 64) {
            return ['error' => 'invalid_input', 'message' => 'namespace must be 1-64 chars.'];
        }
        if (trim($external_key) === '' || \strlen($external_key) > 255) {
            return ['error' => 'invalid_input', 'message' => 'external_key must be 1-255 chars.'];
        }
        if (!\in_array($table, self::SUPPORTED_TABLES, true)) {
            return [
                'error' => 'unsupported_table',
                'message' => sprintf('%s is not in the supported table list.', $table),
                'supported_tables' => self::SUPPORTED_TABLES,
            ];
        }
        if (!$this->tableExists($table)) {
            return [
                'error' => 'extension_not_available',
                'message' => sprintf('%s is supported but the table does not exist — the host extension is not installed.', $table),
                'table' => $table,
            ];
        }
        return null;
    }

    /**
     * Per-instance cache of `tablesExist()` lookups. We hit this at least
     * once per call (validateInput) and N-times in `listMappings` UNION
     * mode (where N is the size of SUPPORTED_TABLES = 23); without caching
     * we'd issue 23 INFORMATION_SCHEMA queries per `external_ids_list()`
     * invocation.
     *
     * @var array<string, bool>|null
     */
    private ?array $tableExistsCache = null;

    private function tableExists(string $table): bool
    {
        if ($this->tableExistsCache === null) {
            $this->tableExistsCache = [];
        }
        if (!\array_key_exists($table, $this->tableExistsCache)) {
            $this->tableExistsCache[$table] = $this->connection
                ->createSchemaManager()
                ->tablesExist([$table]);
        }
        return $this->tableExistsCache[$table];
    }

    private function log(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::GENERAL, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }
}
