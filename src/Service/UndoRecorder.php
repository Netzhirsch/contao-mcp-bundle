<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\ToolPermissionMap;
use Psr\Log\LoggerInterface;

/**
 * Makes MCP deletions recoverable through Contao's OWN backend undo.
 *
 * Contao only fills `tl_undo` from `DC_Table::delete()` — i.e. from the backend
 * data container. The MCP tools delete through Models/DBAL, so until now an
 * AI-deleted record was gone for good (a version snapshot does not help: a
 * restore is an UPDATE, and there is no row left to update).
 *
 * This service snapshots the record plus its DCA-declared children into
 * `tl_undo` right before the deletion, exactly the way DC_Table does. The
 * recovery itself deliberately stays where it belongs: a human clicks
 * "Undo" in the backend. The AI can delete, but it cannot silently un-delete.
 */
final class UndoRecorder
{
    /** Same strict identifier rule as the permission layer — this ends up in SQL. */
    private const TABLE_PATTERN = '/^tl_[a-z0-9_]+$/';

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly ToolPermissionMap $map,
        private readonly AuthorResolver $authorResolver,
        private readonly DeletionScope $scope,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Snapshot before a `*_delete` tool runs. Returns the `tl_undo` id, or 0
     * when this call does not delete anything.
     *
     * Wired into the two places that also enforce permissions (the controller
     * and the lazy-mode `contao_call` proxy), so every delete tool — including
     * ones added later — is covered without touching 21 call sites.
     *
     * @param array<string, mixed> $args
     */
    public function beforeToolCall(string $tool, array $args): int
    {
        $req = $this->map->requirement($tool, $args);

        if (!\is_array($req) || 'dc' !== ($req['kind'] ?? null) || 'delete' !== ($req['op'] ?? null)) {
            return 0;
        }

        // The tool refuses without an explicit confirmation, so nothing will be
        // deleted — don't leave a snapshot of rows that still exist.
        if (\array_key_exists('confirm_destructive', $args) && true !== $args['confirm_destructive']) {
            return 0;
        }

        $table = (string) ($req['table'] ?? '');
        $id = (int) ($req['id'] ?? 0);

        if ('' === $table || $id <= 0) {
            return 0;
        }

        return $this->record($table, $id);
    }

    /**
     * Drop a snapshot again — used when the tool did NOT delete after all
     * (record not found, a cascade guard refused, …). Without this a stale
     * entry would offer to restore rows that were never removed, and the undo
     * would then fail on duplicate ids.
     */
    public function discard(int $undoId): void
    {
        if ($undoId <= 0) {
            return;
        }

        try {
            $this->connection->delete('tl_undo', ['id' => $undoId]);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not discard MCP undo snapshot.', ['undo_id' => $undoId, 'exception' => $e]);
        }
    }

    /**
     * Snapshot `$table.$id` + its children into tl_undo. MUST run before the
     * rows are deleted. Returns the tl_undo id (0 = nothing recorded).
     */
    public function record(string $table, int $id): int
    {
        // Recording a deletion FROM tl_undo would be circular — Contao skips it too.
        if ('tl_undo' === $table || 1 !== preg_match(self::TABLE_PATTERN, $table) || $id <= 0) {
            return 0;
        }

        try {
            $this->framework->initialize();

            // The cascade walk (and the DCA loading it needs) lives in
            // DeletionScope, so the undo snapshot and the delete guard can
            // never disagree about which rows a deletion removes.
            $ids = $this->scope->collect($table, $id);

            $data = [];
            $affected = 0;

            foreach ($ids as $childTable => $childIds) {
                foreach (array_values(array_unique($childIds)) as $k => $childId) {
                    $row = $this->connection->fetchAssociative(
                        'SELECT * FROM '.$this->connection->quoteIdentifier($childTable).' WHERE id = ?',
                        [$childId],
                    );

                    if (false !== $row) {
                        $data[$childTable][$k] = $row;
                        ++$affected;
                    }
                }
            }

            if ([] === $data) {
                return 0;
            }

            $this->connection->insert('tl_undo', [
                // The acting backend user — same attribution the tools use for
                // tl_log/tl_version. Contao's UndoVoter shows a non-admin only
                // their own entries, so this is what makes the entry visible
                // to the person whose AI session deleted the record.
                'pid' => $this->authorResolver->resolve(),
                'tstamp' => time(),
                'fromTable' => $table,
                'query' => 'DELETE FROM '.$table.' WHERE id='.$id,
                'affectedRows' => $affected,
                'data' => serialize($data),
            ]);

            return (int) $this->connection->lastInsertId();
        } catch (\Throwable $e) {
            // A failed snapshot must never block the operation the user asked
            // for — it degrades to today's behaviour (no undo entry).
            $this->logger->warning('Could not record MCP undo snapshot.', ['table' => $table, 'id' => $id, 'exception' => $e]);

            return 0;
        }
    }
}
