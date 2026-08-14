<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Migration\Version020Beta01;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Netzhirsch\ContaoMcpBundle\Backend\ExternalIdDcaInjector;

/**
 * One-shot migration from the legacy central `tl_mcp_external_ref` table to
 * the decentral per-row columns (`external_id_namespace`, `external_id_key`)
 * introduced in v0.2.0-beta1.
 *
 * What changes:
 *   - Pre-v0.2: one row per mapping in `tl_mcp_external_ref` keyed by
 *               (namespace, external_id, target_table) → target_id.
 *   - v0.2+:   two columns on the target row itself; UNIQUE per table.
 *
 * Migration policy:
 *   - Re-key: legacy `namespace` → `external_id_namespace`,
 *             legacy `external_id` → `external_id_key`,
 *             writes happen on the row identified by `target_table` + `target_id`.
 *   - Skip + log orphans: if `target_table.target_id` does not exist anymore
 *     (operator deleted the row between mapping create and this migration)
 *     we drop the legacy entry silently rather than aborting — the mapping
 *     was already dead.
 *   - Conflict resolution: if two legacy entries would collide on the new
 *     UNIQUE (ns, key) in the same table (briefing §6.3) we keep the
 *     newest by `tstamp` and drop the older. This case is practically
 *     impossible because the legacy table also had a UNIQUE on
 *     (namespace, external_id, target_table) — included only for safety.
 *   - DROP the legacy table after the loop.
 *
 * Idempotent:
 *   shouldRun() returns false once `tl_mcp_external_ref` is gone, so a
 *   re-execution after a successful first pass is a no-op.
 */
final class MigrateExternalIdToDecentralColumns extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'Migrate External-ID mapping from tl_mcp_external_ref to per-row columns (v0.2.0)';
    }

    /**
     * Migration is meaningful only while the legacy table still exists. Once
     * it has been dropped (by us or by hand), `shouldRun` returns false and
     * `contao:migrate` skips this step on subsequent runs.
     *
     * Note: we don't gate on whether the decentral columns are *present* —
     * the `loadDataContainer` hook ensures they're declared in the DCA, and
     * `contao:migrate` adds them before we run. If the columns are missing
     * (e.g. operator partial install), `run()` surfaces a clear error.
     */
    public function shouldRun(): bool
    {
        $sm = $this->schemaManager();

        return $sm->tablesExist(['tl_mcp_external_ref']);
    }

    public function run(): MigrationResult
    {
        $sm = $this->schemaManager();

        if (!$sm->tablesExist(['tl_mcp_external_ref'])) {
            return $this->createResult(true, 'Legacy table tl_mcp_external_ref already gone — nothing to do.');
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT namespace, external_id, target_table, target_id, tstamp
             FROM tl_mcp_external_ref
             ORDER BY tstamp ASC, id ASC',
        );

        $stats = [
            'total' => \count($rows),
            'migrated' => 0,
            'skipped_unsupported_table' => 0,
            'skipped_missing_column' => 0,
            'skipped_orphan_row' => 0,
            'skipped_conflict' => 0,
        ];

        $supported = ExternalIdDcaInjector::SUPPORTED_TABLES;

        foreach ($rows as $row) {
            $targetTable = (string) $row['target_table'];
            $targetId = (int) $row['target_id'];
            $namespace = (string) $row['namespace'];
            $key = (string) $row['external_id'];

            // Hard gate: only tables on the new whitelist can be carried over.
            // If the legacy data mapped onto something we don't track now
            // (caller registered an off-whitelist table back when the central
            // model accepted anything), there is nowhere to write — log + skip.
            if (!\in_array($targetTable, $supported, true)) {
                ++$stats['skipped_unsupported_table'];
                continue;
            }

            // The target table must actually have the new columns. If the DCA
            // injector didn't add them yet (e.g. extension bundle absent so
            // `tl_url_rewrite` never had its DCA loaded → no columns), skip.
            if (!$this->columnsExist($targetTable)) {
                ++$stats['skipped_missing_column'];
                continue;
            }

            // Orphan check: the row the legacy mapping points at might have
            // been deleted manually since. Verify before write.
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM '.$this->quoteIdentifier($targetTable).' WHERE id = ?',
                [$targetId],
            );
            if ($exists === 0) {
                ++$stats['skipped_orphan_row'];
                continue;
            }

            // Conflict guard. If a *different* row in the same table already
            // owns this (ns, key) pair we keep the existing binding and skip
            // the legacy one — newer-wins through tstamp ASC ordering above
            // means later rows overwrite earlier ones if both target the same
            // physical row, but never overwrite each other across rows.
            $conflicting = $this->connection->fetchOne(
                'SELECT id FROM '.$this->quoteIdentifier($targetTable).'
                 WHERE external_id_namespace = ? AND external_id_key = ?',
                [$namespace, $key],
            );
            if ($conflicting !== false && (int) $conflicting !== $targetId) {
                ++$stats['skipped_conflict'];
                continue;
            }

            // Write the mapping onto the target row. UPDATE not INSERT — the
            // row already exists by construction.
            $this->connection->update(
                $targetTable,
                [
                    'external_id_namespace' => $namespace,
                    'external_id_key' => $key,
                ],
                ['id' => $targetId],
            );
            ++$stats['migrated'];
        }

        // Contao's migrate command runs in two passes: pending migrations
        // first, schema diff second. If our DCA-injected columns aren't on
        // the target tables YET (first pass after a fresh checkout),
        // skipped_missing_column would be > 0 — which means we would drop
        // the legacy table before its data made it across, losing mappings.
        //
        // Guard: refuse to drop while there are still rows we couldn't
        // migrate due to missing columns. shouldRun() stays true (legacy
        // table still exists), Contao runs the migrate command again after
        // the schema diff is applied, and on the second pass the columns
        // exist so the rows go through.
        if ($stats['skipped_missing_column'] > 0) {
            return $this->createResult(true, sprintf(
                'tl_mcp_external_ref → decentral columns: %d migrated this pass, %d still pending (target columns missing — will retry after schema diff). Legacy table kept for now.',
                $stats['migrated'],
                $stats['skipped_missing_column'],
            ));
        }

        // All rows accounted for (either migrated or rejected for a permanent
        // reason — unsupported table, orphan row, conflict). Safe to drop.
        $this->connection->executeStatement('DROP TABLE tl_mcp_external_ref');

        $message = sprintf(
            'tl_mcp_external_ref → decentral columns: %d migrated, %d skipped (%d unsupported table, %d orphan rows, %d conflicts). Legacy table dropped.',
            $stats['migrated'],
            $stats['total'] - $stats['migrated'],
            $stats['skipped_unsupported_table'],
            $stats['skipped_orphan_row'],
            $stats['skipped_conflict'],
        );

        return $this->createResult(true, $message);
    }

    private function schemaManager(): AbstractSchemaManager
    {
        return $this->connection->createSchemaManager();
    }

    /**
     * Verify both decentral columns are present on the target table. We rely
     * on the DCA injector + `contao:migrate` to have created them; this check
     * is a belt-and-braces guard against partial deployments.
     */
    private function columnsExist(string $table): bool
    {
        $sm = $this->schemaManager();

        if (!$sm->tablesExist([$table])) {
            return false;
        }

        $columns = array_map(static fn ($col) => strtolower($col->getName()), $sm->listTableColumns($table));

        return \in_array('external_id_namespace', $columns, true)
            && \in_array('external_id_key', $columns, true);
    }

    /**
     * The whitelist already restricts to `tl_[a-z_]+`, but quote defensively
     * so the table name flows through the same path as user-supplied
     * identifiers would.
     */
    private function quoteIdentifier(string $name): string
    {
        return $this->connection->quoteIdentifier($name);
    }
}
