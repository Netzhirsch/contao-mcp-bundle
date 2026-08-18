<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\SmallIntType;

/**
 * Every row a delete will actually remove: the record itself, its tree
 * descendants, and the DCA-declared child records — the same walk
 * `DC_Table::delete()` / `deleteChildren()` performs.
 *
 * Two callers need exactly this set and must not disagree about it:
 *   - {@see UndoRecorder} snapshots it into tl_undo so a human can restore.
 *   - {@see DeletionGuard} subtracts it from the reference scan, because a
 *     row that is going away too cannot be the reason to refuse the deletion
 *     (an article's `pid` pointing at the page being deleted is not a
 *     dangling reference — it is the cascade).
 */
final class DeletionScope
{
    /** Same strict identifier rule as the permission layer — this ends up in SQL. */
    private const TABLE_PATTERN = '/^tl_[a-z0-9_]+$/';

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, list<int>> table => ids that will be deleted
     */
    public function collect(string $table, int $id): array
    {
        if (1 !== preg_match(self::TABLE_PATTERN, $table) || $id <= 0) {
            return [];
        }

        $this->framework->initialize();
        $this->framework->getAdapter(Controller::class)->loadDataContainer($table);

        $ids = [$table => $this->collectIds($table, $id)];

        foreach ($ids[$table] as $rowId) {
            $ids = $this->collectChildren($table, $rowId, $ids);
        }

        return $ids;
    }

    /**
     * The record itself plus, for tree tables (pid but no parent table), all
     * of its descendants — deleting a page takes its subpages with it.
     *
     * @return list<int>
     */
    private function collectIds(string $table, int $id): array
    {
        $dca = $GLOBALS['TL_DCA'][$table]['config'] ?? [];
        $isTree = empty($dca['ptable']) && $this->hasIntegerPid($table);

        if (!$isTree) {
            return [$id];
        }

        $ids = [$id];
        $level = [$id];

        // Iterative instead of recursive: a deep page tree would otherwise
        // recurse as deep as the tree is.
        while ([] !== $level) {
            $level = array_map('intval', $this->connection->fetchFirstColumn(
                'SELECT id FROM '.$this->connection->quoteIdentifier($table).' WHERE pid IN (?)',
                [$level],
                [ArrayParameterType::INTEGER],
            ));
            $level = array_diff($level, $ids); // cycle guard
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    /**
     * Walk the DCA `ctable` relations, exactly like DC_Table::deleteChildren().
     *
     * @param array<string, list<int>> $ids
     *
     * @return array<string, list<int>>
     */
    private function collectChildren(string $table, int $id, array $ids): array
    {
        $ctables = $GLOBALS['TL_DCA'][$table]['config']['ctable'] ?? [];

        if (!\is_array($ctables) || [] === $ctables) {
            return $ids;
        }

        foreach ($ctables as $child) {
            if (1 !== preg_match(self::TABLE_PATTERN, (string) $child)) {
                continue;
            }

            $this->framework->getAdapter(Controller::class)->loadDataContainer($child);

            if ($GLOBALS['TL_DCA'][$child]['config']['doNotDeleteRecords'] ?? false) {
                continue;
            }

            $quoted = $this->connection->quoteIdentifier($child);

            // Dynamic parent tables (tl_content) need the ptable as well,
            // otherwise the scope would grab rows of a foreign parent.
            $childIds = ($GLOBALS['TL_DCA'][$child]['config']['dynamicPtable'] ?? false)
                ? $this->connection->fetchFirstColumn("SELECT id FROM $quoted WHERE pid = ? AND ptable = ?", [$id, $table])
                : $this->connection->fetchFirstColumn("SELECT id FROM $quoted WHERE pid = ?", [$id]);

            foreach (array_map('intval', $childIds) as $childId) {
                if (\in_array($childId, $ids[$child] ?? [], true)) {
                    continue;
                }

                $ids[$child] = [...($ids[$child] ?? []), $childId];
                $ids = $this->collectChildren($child, $childId, $ids);
            }
        }

        return $ids;
    }

    /**
     * A `pid` alone does not make a tree: `tl_files.pid` is the parent's
     * binary(16) UUID, not a row id. Comparing it against integer ids lets
     * MySQL coerce the bytes to a number, which can quietly pull an unrelated
     * row into the deletion scope — and anything in that scope is excluded
     * from the reference check, i.e. a real reference could go unreported.
     */
    private function hasIntegerPid(string $table): bool
    {
        try {
            $columns = $this->connection->createSchemaManager()->introspectTable($table);

            if (!$columns->hasColumn('pid')) {
                return false;
            }

            return $columns->getColumn('pid')->getType() instanceof IntegerType
                || $columns->getColumn('pid')->getType() instanceof BigIntType
                || $columns->getColumn('pid')->getType() instanceof SmallIntType;
        } catch (\Throwable) {
            return false;
        }
    }
}
