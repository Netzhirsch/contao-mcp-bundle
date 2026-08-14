<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Sorting;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\DbalRetry;
use PhpMcp\Server\Attributes\McpTool;
use Psr\Log\LoggerInterface;

/**
 * Generic move/reorder operations for Contao's sortable tables.
 *
 * Contao stores list order in a `sorting` int column with a 128-step gap
 * convention (128, 256, 384, ...). Tools that need to reorder normally have
 * to fetch the target row's sorting, find the next sibling, and split the
 * gap — annoying boilerplate for the LLM and error-prone (collisions when
 * two rows already share sorting values).
 *
 * `entity_move` collapses that into one call:
 *   entity_move(table: "tl_content", id: 12,
 *               position: "before"|"after"|"first"|"last",
 *               target_id?: 34)
 *
 * The tool fetches sibling rows (same parent), picks a valid sorting value
 * in the gap, and writes it. If the gap is exhausted (e.g. two siblings have
 * sorting 256 and 257) it re-sorts the whole sibling set with 128 steps
 * before placing the moved row.
 */
final class Tool
{
    /**
     * Supported tables → their parent-column. Keep this list narrow on
     * purpose — exposing arbitrary tables for sort-writes would be risky.
     *
     * @var array<string, string>
     */
    private const SUPPORTED = [
        'tl_content' => 'pid',           // ptable + pid actually; we keep ptable in same call
        'tl_article' => 'pid',
        'tl_news' => 'pid',
        'tl_calendar_events' => 'pid',
        'tl_faq' => 'pid',
        'tl_form_field' => 'pid',
        'tl_module' => 'pid',
        'tl_image_size_item' => 'pid',
        'tl_page' => 'pid',
    ];

    /**
     * Tables that need an additional discriminator column (e.g. tl_content
     * uses ptable in addition to pid).
     *
     * @var array<string, string>
     */
    private const PARENT_DISCRIMINATOR = [
        'tl_content' => 'ptable',
    ];

    /**
     * Re-parent targets: the parent table a row of <key> can be moved under.
     * tl_content is special (its parent table is the runtime ptable), handled
     * separately. Used for target-existence + cycle validation on re-parent.
     *
     * @var array<string, string>
     */
    private const REPARENT_PARENT_TABLE = [
        'tl_article' => 'tl_page',   // an article belongs to a page
        'tl_page' => 'tl_page',      // a page belongs to a parent page (0 = root)
    ];

    /** Parent tables a tl_content row may be moved under (its ptable). */
    private const CONTENT_PARENT_TABLES = ['tl_article', 'tl_content', 'tl_news', 'tl_calendar_events', 'tl_faq', 'tl_page'];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly DbalRetry $dbalRetry,
        private readonly McpPermissionGuard $guard,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'entity_move',
        description: <<<'DESC'
            Reorders AND/OR re-parents a row — the MCP equivalent of Contao's drag-sort
            (within a parent) and cut/paste (into another parent).

            Supported tables (each writes the same `sorting` int column):
              tl_content, tl_article, tl_news, tl_calendar_events, tl_faq,
              tl_form_field, tl_module, tl_image_size_item, tl_page.

            Parameters:
              - table:        one of the above
              - id:           row id to move
              - position:     "before" | "after" | "first" | "last"
              - target_id:    required for "before" / "after"; ignored for "first" / "last".
                              Must be a sibling UNDER THE DESTINATION parent.
              - into_pid:     optional — move the row under a NEW parent (cut/paste).
                              tl_content → the new parent article/element id;
                              tl_article → the destination page id;
                              tl_page    → the new parent page id (0 = root level).
              - into_ptable:  optional, tl_content only — the destination parent table
                              (default: keep the current ptable; pass "tl_content" to nest
                              into a container element, "tl_article" for an article, …).

            Without into_pid it just re-sorts within the current parent. With into_pid it
            re-parents and then positions among the destination siblings. Re-parenting:
              - validates the destination parent exists;
              - rejects cycles (a page under its own subtree, an element under itself/its
                descendants);
              - enforces permission parity — you must be allowed to edit the source AND
                create under the destination (same voters / pagemount scope as the CRUD tools).

            Sorting placement: "before"/"after" splits the gap (normalising the sibling set
            to 128-steps first if exhausted); "first" = min/2; "last" = max+128.

            Returns {moved, new_sorting, renumbered_siblings, reparented, into_pid?, into_ptable?}.
            DBAL transaction with row locks (no Versions snapshot — Contao doesn't version
            sort/move either).
        DESC,
    )]
    public function move(string $table, int $id, string $position, ?int $target_id = null, ?int $into_pid = null, ?string $into_ptable = null): array
    {
        $this->framework->initialize();

        if (!isset(self::SUPPORTED[$table])) {
            return [
                'error' => 'unsupported_table',
                'message' => sprintf('Table %s is not sortable via this tool.', $table),
                'supported' => array_keys(self::SUPPORTED),
            ];
        }
        if (!\in_array($position, ['before', 'after', 'first', 'last'], true)) {
            return ['error' => 'invalid_input', 'message' => 'position must be one of: before, after, first, last'];
        }
        if (\in_array($position, ['before', 'after'], true) && $target_id === null) {
            return ['error' => 'invalid_input', 'message' => sprintf('target_id is required for position=%s', $position)];
        }

        $q = $this->connection->quoteIdentifier($table);
        $discriminator = self::PARENT_DISCRIMINATOR[$table] ?? null;

        // Read the source row up front (outside the txn closure) so re-parent
        // validation + permission parity can run before we take locks.
        $row = $this->connection->fetchAssociative("SELECT * FROM {$q} WHERE id = ?", [$id]);
        if ($row === false) {
            return ['error' => 'not_found', 'message' => sprintf('No row in %s with id %d', $table, $id)];
        }

        $srcParentId = (int) ($row['pid'] ?? 0);
        $srcPtable = $discriminator !== null ? (string) ($row[$discriminator] ?? '') : null;

        // Resolve the destination parent.
        $destParentId = $into_pid ?? $srcParentId;
        $destPtable = $discriminator !== null ? ($into_ptable ?? $srcPtable) : null;
        $reparent = $into_pid !== null && ($destParentId !== $srcParentId || ($discriminator !== null && $destPtable !== $srcPtable));

        if ($reparent) {
            if (($err = $this->validateReparent($table, $id, $destParentId, $destPtable)) !== null) {
                return $err;
            }
            // Permission parity: edit the source + create under the destination.
            if (($denied = $this->guard->ensureCan($table, 'update', $id)) !== null) {
                return $denied;
            }
            $newData = ['pid' => $destParentId];
            if ($destPtable !== null) {
                $newData['ptable'] = $destPtable;
            }
            if (($denied = $this->guard->ensureCan($table, 'create', null, $newData)) !== null) {
                return $denied;
            }
        }

        try {
            return $this->dbalRetry->transactional($this->connection, function () use ($table, $id, $position, $target_id, $q, $discriminator, $destParentId, $destPtable, $reparent): array {
                // Lock the moving row.
                $this->connection->executeStatement("SELECT id FROM {$q} WHERE id = ? FOR UPDATE", [$id]);

                // Sibling set under the DESTINATION parent (= source parent when
                // not re-parenting). Locked for the duration of the txn.
                $where = sprintf('%s = ? AND id != ?', $this->connection->quoteIdentifier('pid'));
                $params = [$destParentId, $id];
                if ($discriminator !== null) {
                    $where .= sprintf(' AND %s = ?', $this->connection->quoteIdentifier($discriminator));
                    $params[] = $destPtable;
                }
                $siblings = $this->connection->fetchAllAssociative(
                    "SELECT id, sorting FROM {$q} WHERE {$where} ORDER BY sorting, id FOR UPDATE",
                    $params,
                );

                if ($target_id !== null && !$this->containsId($siblings, $target_id)) {
                    return [
                        'error' => 'target_not_sibling',
                        'message' => sprintf('target_id %d is not a sibling under the destination parent.', $target_id),
                    ];
                }

                $renumbered = 0;
                if (!self::hasHeadroom($siblings)) {
                    $renumbered = $this->renumber($table, $destParentId, $discriminator, $destPtable, $id, 0);
                    $siblings = $this->connection->fetchAllAssociative(
                        "SELECT id, sorting FROM {$q} WHERE {$where} ORDER BY sorting, id",
                        $params,
                    );
                }

                $newSorting = $this->computeSorting($siblings, $position, $target_id);
                if ($newSorting === null) {
                    return ['error' => 'compute_failed', 'message' => 'Could not compute a valid sorting position (target sibling missing?).'];
                }

                // Single UPDATE: re-parent (pid [+ ptable]) + sorting together.
                $set = 'sorting = ?, tstamp = ?';
                $values = [$newSorting, time()];
                if ($reparent) {
                    $set .= ', '.$this->connection->quoteIdentifier('pid').' = ?';
                    $values[] = $destParentId;
                    if ($discriminator !== null) {
                        $set .= ', '.$this->connection->quoteIdentifier($discriminator).' = ?';
                        $values[] = $destPtable;
                    }
                }
                $values[] = $id;
                $this->connection->executeStatement("UPDATE {$q} SET {$set} WHERE id = ?", $values);

                $this->log(sprintf(
                    'Moved %s id=%d to %s%s (new sorting=%d, renumbered=%d)%s',
                    $table, $id, $position,
                    $target_id !== null ? " target {$target_id}" : '',
                    $newSorting, $renumbered,
                    $reparent ? sprintf(' [re-parented to %s%d]', $destPtable !== null ? $destPtable.':' : '', $destParentId) : '',
                ), __METHOD__);

                return [
                    'moved' => true,
                    'table' => $table,
                    'id' => $id,
                    'new_sorting' => $newSorting,
                    'renumbered_siblings' => $renumbered,
                    'position' => $position,
                    'target_id' => $target_id,
                    'reparented' => $reparent,
                    'into_pid' => $reparent ? $destParentId : null,
                    'into_ptable' => $reparent ? $destPtable : null,
                ];
            });
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('entity_move failed for %s id=%d: %s', $table, $id, $e->getMessage()));
            return [
                'error' => 'move_failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate a re-parent target: existence + cycle freedom.
     *
     * @return array<string, mixed>|null null = ok
     */
    private function validateReparent(string $table, int $id, int $destParentId, ?string $destPtable): ?array
    {
        if ($table === 'tl_content') {
            $parentTable = $destPtable ?? 'tl_article';
            if (!\in_array($parentTable, self::CONTENT_PARENT_TABLES, true)) {
                return ['error' => 'invalid_input', 'message' => sprintf('Unsupported into_ptable "%s".', $parentTable)];
            }
            if (!$this->rowExists($parentTable, $destParentId)) {
                return ['error' => 'parent_not_found', 'message' => sprintf('Destination parent %s:%d does not exist.', $parentTable, $destParentId)];
            }
            if ($parentTable === 'tl_content' && $this->wouldContentCycle($destParentId, $id)) {
                return ['error' => 'invalid_parent', 'message' => 'Cannot move a content element under itself or one of its descendants.'];
            }

            return null;
        }

        // tl_page allows pid 0 (root); other tree/parent tables need a real row.
        if ($table === 'tl_page' && $destParentId === 0) {
            return null;
        }
        $parentTable = self::REPARENT_PARENT_TABLE[$table] ?? null;
        if ($parentTable === null) {
            return ['error' => 'reparent_unsupported', 'message' => sprintf('Re-parenting is not supported for %s.', $table)];
        }
        if (!$this->rowExists($parentTable, $destParentId)) {
            return ['error' => 'parent_not_found', 'message' => sprintf('Destination parent %s:%d does not exist.', $parentTable, $destParentId)];
        }
        if ($table === 'tl_page' && $this->wouldPageCycle($destParentId, $id)) {
            return ['error' => 'invalid_parent', 'message' => 'Cannot move a page under itself or one of its sub-pages.'];
        }

        return null;
    }

    private function rowExists(string $table, int $id): bool
    {
        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE id = ?', $this->connection->quoteIdentifier($table)),
            [$id],
        ) > 0;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function containsId(array $rows, int $id): bool
    {
        foreach ($rows as $r) {
            if ((int) $r['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /** Walk the tl_page tree up from $fromId; true if $movingId is on the path. */
    private function wouldPageCycle(int $fromId, int $movingId): bool
    {
        $current = $fromId;
        $seen = [];
        for ($depth = 0; $current > 0 && $depth < 500; ++$depth) {
            if ($current === $movingId || isset($seen[$current])) {
                return true;
            }
            $seen[$current] = true;
            $current = (int) $this->connection->fetchOne('SELECT pid FROM tl_page WHERE id = ?', [$current]);
        }

        return false;
    }

    /** Walk the tl_content nesting chain up from $fromId; true if $movingId is on the path. */
    private function wouldContentCycle(int $fromId, int $movingId): bool
    {
        $current = $fromId;
        $seen = [];
        for ($depth = 0; $current > 0 && $depth < 200; ++$depth) {
            if ($current === $movingId || isset($seen[$current])) {
                return true;
            }
            $seen[$current] = true;
            $r = $this->connection->fetchAssociative('SELECT pid, ptable FROM tl_content WHERE id = ?', [$current]);
            if ($r === false || (string) $r['ptable'] !== 'tl_content') {
                return false;
            }
            $current = (int) $r['pid'];
        }

        return false;
    }

    // ─────────────────────────── helpers ────────────────────────────

    /**
     * @param list<array<string, mixed>> $siblings
     */
    private static function hasHeadroom(array $siblings): bool
    {
        for ($i = 1; $i < \count($siblings); ++$i) {
            $diff = (int) $siblings[$i]['sorting'] - (int) $siblings[$i - 1]['sorting'];
            if ($diff < 2) {
                return false;
            }
        }
        return true;
    }

    /**
     * Re-spreads existing siblings on the 128-step grid. Returns the number
     * of rows touched. The moved row is NOT renumbered here — the caller
     * will write its sorting in the next step.
     */
    private function renumber(string $table, int $parentId, ?string $discriminator, ?string $discriminatorValue, int $excludeId, mixed $excludeOldSorting): int
    {
        $q = $this->connection->quoteIdentifier($table);
        $where = sprintf('%s = ? AND id != ?', $this->connection->quoteIdentifier(self::SUPPORTED[$table]));
        $params = [$parentId, $excludeId];
        if ($discriminator !== null) {
            $where .= sprintf(' AND %s = ?', $this->connection->quoteIdentifier($discriminator));
            $params[] = $discriminatorValue;
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT id FROM {$q} WHERE {$where} ORDER BY sorting, id",
            $params,
        );

        $step = 128;
        $i = 1;
        foreach ($rows as $r) {
            $this->connection->executeStatement(
                "UPDATE {$q} SET sorting = ? WHERE id = ?",
                [$i * $step, (int) $r['id']],
            );
            ++$i;
        }

        return \count($rows);
    }

    /**
     * @param list<array<string, mixed>> $siblings
     */
    private function computeSorting(array $siblings, string $position, ?int $target_id): ?int
    {
        if ($siblings === []) {
            return 128;
        }

        if ($position === 'first') {
            $min = (int) $siblings[0]['sorting'];
            return max(1, intdiv($min, 2));
        }

        if ($position === 'last') {
            $max = (int) $siblings[\count($siblings) - 1]['sorting'];
            return $max + 128;
        }

        // before / after
        foreach ($siblings as $idx => $s) {
            if ((int) $s['id'] !== $target_id) {
                continue;
            }
            $targetSort = (int) $s['sorting'];
            if ($position === 'before') {
                $prevSort = $idx === 0 ? 0 : (int) $siblings[$idx - 1]['sorting'];
                return $prevSort > 0 ? intdiv($prevSort + $targetSort, 2) : max(1, intdiv($targetSort, 2));
            }
            // after
            $nextSort = $idx === \count($siblings) - 1 ? 0 : (int) $siblings[$idx + 1]['sorting'];
            return $nextSort > 0 ? intdiv($targetSort + $nextSort, 2) : $targetSort + 128;
        }

        return null;
    }

    private function log(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::GENERAL, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }
}
