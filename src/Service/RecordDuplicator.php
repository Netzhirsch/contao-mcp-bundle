<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Slug\Slug;
use Doctrine\DBAL\Connection;

/**
 * DCA-driven recursive record duplication — mirrors Contao's backend "copy"
 * operation without re-implementing it per entity.
 *
 * What it honours, straight from the DCA (so a copy behaves like the backend
 * button):
 *   - `eval.doNotCopy` fields are NOT carried over (Contao marks alias,
 *     unique keys, etc. this way → the alias regenerates on save).
 *   - `config.ctable` children cascade recursively (article → content,
 *     content → nested content). `config.dynamicPtable` child tables get
 *     their `ptable` set to the parent table (tl_content).
 *   - same-table tree children (sub-pages) cascade only when `withChildren`
 *     is requested AND the table is a tree DCA (list.sorting.mode === 5).
 *
 * Always reset on every copy: the decentralised external-id columns
 * (`external_id_namespace`/`external_id_key`) carry a UNIQUE mapping and must
 * never be duplicated. The top-level copy is wrapped in a Versions snapshot +
 * the caller logs to tl_log.
 *
 * Permission-agnostic and identity-agnostic on purpose: the calling tool does
 * the parity checks (read source / create under target) AND the Versions
 * snapshot + tl_log attribution for the primary new record.
 */
final class RecordDuplicator
{
    private const TREE_MODE = 5;

    /** Columns never copied verbatim regardless of DCA. */
    private const ALWAYS_RESET = ['external_id_namespace', 'external_id_key'];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly Slug $slug,
    ) {
    }

    /**
     * @param array<string, mixed> $overrides applied to the TOP-LEVEL copy only
     *
     * @return array{table: string, source_id: int, new_id: int, copied: int, children: list<array<string, mixed>>}
     */
    public function duplicate(
        string $table,
        int $id,
        ?int $intoPid = null,
        ?string $intoPtable = null,
        bool $withChildren = false,
        array $overrides = [],
        ?int $authorUserId = null,
    ): array {
        $this->framework->initialize();

        $overrides = $this->normaliseOverrides($table, $overrides);

        $counter = 0;
        $result = $this->copyRecord($table, $id, $intoPid, $intoPtable, $withChildren, $overrides, $authorUserId, $counter, true);
        $result['copied'] = $counter;

        return $result;
    }

    /**
     * Overrides go straight into an INSERT, so they have to arrive as values
     * the column can hold.
     *
     * JSON has real booleans and MySQL columns do not: PDO binds `false` as the
     * empty string, and a strict-mode server answers "Incorrect integer value:
     * '' for column `tl_article`.`published`". That is a true message about a
     * value nobody typed — `published: false` is the obvious way to ask for an
     * unpublished copy, and it is how every other tool in this bundle takes a
     * boolean. Reported after a translation workflow had to write `0` instead.
     *
     * An unknown column is refused here too, rather than surfacing later as an
     * SQL error about a name the caller can no longer connect to their call.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    private function normaliseOverrides(string $table, array $overrides): array
    {
        $out = [];

        foreach ($overrides as $column => $value) {
            $column = (string) $column;

            if (!$this->hasColumn($table, $column)) {
                throw new \InvalidArgumentException(sprintf(
                    'overrides: %s has no column "%s". Nothing was copied.',
                    $table,
                    $column,
                ));
            }

            $out[$column] = match (true) {
                \is_bool($value) => $value ? 1 : 0,
                $value === null, \is_scalar($value) => $value,
                default => throw new \InvalidArgumentException(sprintf(
                    'overrides["%s"]: expected a string, number, boolean or null, got %s. '
                    .'Serialised columns must be passed as their stored string.',
                    $column,
                    get_debug_type($value),
                )),
            };
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{table: string, source_id: int, new_id: int, children: list<array<string, mixed>>}
     */
    private function copyRecord(
        string $table,
        int $id,
        ?int $intoPid,
        ?string $intoPtable,
        bool $withChildren,
        array $overrides,
        ?int $authorUserId,
        int &$counter,
        bool $isTopLevel,
    ): array {
        Controller::loadDataContainer($table);
        $dca = $GLOBALS['TL_DCA'][$table] ?? [];

        $q = $this->connection->quoteIdentifier($table);
        $source = $this->connection->fetchAssociative("SELECT * FROM {$q} WHERE id = ?", [$id]);
        if ($source === false) {
            throw new \RuntimeException(sprintf('%s.%d does not exist.', $table, $id));
        }

        $row = $this->buildCopyData($table, $dca, $source, $intoPid, $intoPtable, $isTopLevel ? $overrides : [], $authorUserId);
        $row['tstamp'] = time();

        // Build the INSERT with quoted column identifiers ourselves —
        // DBAL's Connection::insert() does NOT quote columns, so a reserved
        // word like `groups` (tl_content/tl_article/tl_page all have it)
        // throws a 42000 syntax error.
        $cols = array_keys($row);
        $quotedCols = implode(', ', array_map($this->connection->quoteIdentifier(...), $cols));
        $placeholders = implode(', ', array_fill(0, \count($cols), '?'));
        $this->connection->executeStatement(
            "INSERT INTO {$q} ({$quotedCols}) VALUES ({$placeholders})",
            array_values($row),
        );
        $newId = (int) $this->connection->lastInsertId();
        ++$counter;

        // A raw INSERT skips Contao's alias save_callback, so an alias-bearing
        // copy would land with an empty alias (broken page routing, ambiguous
        // article URLs). Regenerate a unique alias from the title — what the
        // backend copy effectively does.
        $this->regenerateAlias($table, $newId, $source);

        $children = [];

        // 1) ctable cascade — always (an article without its content, a
        //    container without its nested elements, is useless).
        foreach ($this->childTables($dca) as $childTable) {
            Controller::loadDataContainer($childTable);
            $childDca = $GLOBALS['TL_DCA'][$childTable] ?? [];
            $dynamicPtable = !empty($childDca['config']['dynamicPtable']);

            foreach ($this->findChildren($childTable, $id, $dynamicPtable ? $table : null) as $childId) {
                $children[] = $this->copyRecord(
                    $childTable,
                    $childId,
                    $newId,
                    $dynamicPtable ? $table : null,
                    $withChildren,
                    [],
                    $authorUserId,
                    $counter,
                    false,
                );
            }
        }

        // 2) same-table tree children (sub-pages) — only on request.
        if ($withChildren && (int) ($dca['list']['sorting']['mode'] ?? 0) === self::TREE_MODE) {
            foreach ($this->findChildren($table, $id, null) as $childId) {
                $children[] = $this->copyRecord($table, $childId, $newId, null, true, [], $authorUserId, $counter, false);
            }
        }

        return ['table' => $table, 'source_id' => $id, 'new_id' => $newId, 'children' => $children];
    }

    /**
     * Build the INSERT payload from the source row: drop id, honour
     * `doNotCopy`, reset external-id columns, set the new parent + a fresh
     * sorting, then apply caller overrides.
     *
     * @param array<string, mixed> $dca
     * @param array<string, mixed> $source
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function buildCopyData(string $table, array $dca, array $source, ?int $intoPid, ?string $intoPtable, array $overrides, ?int $authorUserId = null): array
    {
        $fields = $dca['fields'] ?? [];
        $row = $source;
        unset($row['id']);

        foreach (array_keys($row) as $col) {
            if (empty($fields[$col]['eval']['doNotCopy'])) {
                continue;
            }

            unset($row[$col]); // let the DB default / Contao regenerate (alias, …)

            // …except for the author. Contao's own copy button records the user
            // who pressed it (`tl_article.author` has both doNotCopy and a
            // `default` that reads the current backend user); dropping the
            // column here fell back to the DB default of 0, leaving copies
            // ownerless and needing a second call to repair.
            if ($authorUserId !== null && $authorUserId > 0 && self::isUserReference($fields[$col] ?? [])) {
                $row[$col] = $authorUserId;
            }
        }

        // Reset the decentralised external-id mapping. MUST be NULL, not ''
        // — the composite UNIQUE index allows many NULLs but only a single
        // '' per table, so '' would collide with the source (and every other
        // copy). The columns are nullable with default NULL.
        foreach (self::ALWAYS_RESET as $col) {
            if (\array_key_exists($col, $source)) {
                $row[$col] = null;
            }
        }

        // Re-parent the top-level copy if requested.
        if ($intoPid !== null && \array_key_exists('pid', $source)) {
            $row['pid'] = $intoPid;
        }
        if ($intoPtable !== null && \array_key_exists('ptable', $source)) {
            $row['ptable'] = $intoPtable;
        }

        // Fresh sorting: append after the last sibling under the new parent.
        if (\array_key_exists('sorting', $source)) {
            $row['sorting'] = $this->nextSorting(
                $table,
                (int) ($row['pid'] ?? $source['pid'] ?? 0),
                $row['ptable'] ?? ($source['ptable'] ?? null),
            );
        }

        // A duplicated root page must not clash on the fallback/dns uniqueness
        // (one language-fallback root per dns). Drop both so the operator sets
        // them explicitly on the copy.
        if ($table === 'tl_page' && ($source['type'] ?? '') === 'root') {
            $row['fallback'] = '';
            $row['dns'] = '';
        }

        foreach ($overrides as $k => $v) {
            $row[$k] = $v;
        }

        return $row;
    }

    /**
     * Regenerate a unique `alias` on the freshly copied row from its title.
     * No-op for tables without an `alias` column (e.g. tl_content) or without
     * a usable title source.
     *
     * @param array<string, mixed> $source
     */
    private function regenerateAlias(string $table, int $newId, array $source): void
    {
        if (!$this->hasColumn($table, 'alias')) {
            return;
        }
        $title = trim((string) ($source['title'] ?? $source['name'] ?? ''));
        if ($title === '') {
            return;
        }

        $q = $this->connection->quoteIdentifier($table);
        $check = fn (string $value): bool => (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$q} WHERE alias = ? AND id != ?",
            [$value, $newId],
        ) > 0;

        $alias = $this->slug->generate($title, ['validChars' => '0-9a-z'], $check);
        $this->connection->executeStatement("UPDATE {$q} SET alias = ? WHERE id = ?", [$alias, $newId]);
    }

    /**
     * Child tables declared via DCA config.ctable (string or list).
     *
     * @param array<string, mixed> $dca
     *
     * @return list<string>
     */
    private function childTables(array $dca): array
    {
        $ctable = $dca['config']['ctable'] ?? [];
        if (\is_string($ctable)) {
            $ctable = [$ctable];
        }

        return \is_array($ctable) ? array_values(array_filter($ctable, '\is_string')) : [];
    }

    /**
     * @return list<int>
     */
    private function findChildren(string $childTable, int $pid, ?string $ptable): array
    {
        $q = $this->connection->quoteIdentifier($childTable);
        $sql = "SELECT id FROM {$q} WHERE pid = ?";
        $params = [$pid];
        if ($ptable !== null) {
            $sql .= ' AND ptable = ?';
            $params[] = $ptable;
        }
        $order = $this->hasColumn($childTable, 'sorting') ? ' ORDER BY sorting, id' : ' ORDER BY id';

        return array_map('intval', $this->connection->fetchFirstColumn($sql.$order, $params));
    }

    private function nextSorting(string $table, int $pid, ?string $ptable): int
    {
        $q = $this->connection->quoteIdentifier($table);
        $sql = "SELECT MAX(sorting) FROM {$q} WHERE pid = ?";
        $params = [$pid];
        if ($ptable !== null && $this->hasColumn($table, 'ptable')) {
            $sql .= ' AND ptable = ?';
            $params[] = $ptable;
        }
        $max = (int) $this->connection->fetchOne($sql, $params);

        return $max + 128;
    }

    /**
     * A column that points at a backend user — `foreignKey: tl_user.name` is
     * how the core DCAs declare it.
     *
     * @param array<string, mixed> $definition
     */
    private static function isUserReference(array $definition): bool
    {
        return str_starts_with((string) ($definition['foreignKey'] ?? ''), 'tl_user.');
    }

    private function hasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $cache[$table] ??= array_map(
            static fn ($c) => $c->getName(),
            $this->connection->createSchemaManager()->listTableColumns($table),
        );

        return \in_array($column, $cache[$table], true);
    }
}
