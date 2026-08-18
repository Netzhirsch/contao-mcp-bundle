<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service\Usage;

use Doctrine\DBAL\Connection;

/**
 * Column metadata for every `tl_*` table, fetched ONCE per request.
 *
 * The usage scanner has to know which columns can even hold a reference
 * before it can search them. Asking DBAL's schema manager per table means one
 * introspection round-trip per table (~60 on a normal install); a single
 * information_schema query answers the same question in one.
 *
 * Contao only supports MySQL/MariaDB, so relying on information_schema is not
 * a portability regression — but {@see load()} still degrades to an empty
 * index instead of throwing, which turns a scan into "found nothing" rather
 * than a failed tool call.
 */
final class SchemaIndex
{
    /** Column types that can hold text — i.e. an insert tag. */
    private const TEXT_TYPES = [
        'varchar' => true,
        'char' => true,
        'tinytext' => true,
        'text' => true,
        'mediumtext' => true,
        'longtext' => true,
        // Contao keeps serialized arrays in blobs; insert tags inside a
        // serialized value (table cells, custom sections) are real usages.
        'blob' => true,
        'tinyblob' => true,
        'mediumblob' => true,
        'longblob' => true,
    ];

    private const INT_TYPES = [
        'int' => true,
        'smallint' => true,
        'mediumint' => true,
        'bigint' => true,
        'tinyint' => true,
    ];

    /** @var array<string, array<string, string>>|null table => column => data type */
    private ?array $columns = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * All `tl_*` tables that physically exist, in alphabetical order.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        return array_keys($this->load());
    }

    public function hasTable(string $table): bool
    {
        return isset($this->load()[$table]);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return isset($this->load()[$table][$column]);
    }

    /**
     * @return array<string, string> column => data type
     */
    public function columns(string $table): array
    {
        return $this->load()[$table] ?? [];
    }

    public function type(string $table, string $column): ?string
    {
        return $this->load()[$table][$column] ?? null;
    }

    /**
     * Columns worth searching for insert tags.
     *
     * @return list<string>
     */
    public function textColumns(string $table): array
    {
        $out = [];

        foreach ($this->load()[$table] ?? [] as $column => $type) {
            if (isset(self::TEXT_TYPES[$type])) {
                $out[] = $column;
            }
        }

        return $out;
    }

    public function isTextType(?string $type): bool
    {
        return null !== $type && isset(self::TEXT_TYPES[$type]);
    }

    public function isIntType(?string $type): bool
    {
        return null !== $type && isset(self::INT_TYPES[$type]);
    }

    public function isBinaryType(?string $type): bool
    {
        return 'binary' === $type || 'varbinary' === $type;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function load(): array
    {
        if (null !== $this->columns) {
            return $this->columns;
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME LIKE 'tl\_%'
                    ORDER BY TABLE_NAME, ORDINAL_POSITION
                    SQL,
            );
        } catch (\Throwable) {
            // No index means no scan — the caller reports "nothing found"
            // rather than failing the whole tool call.
            return $this->columns = [];
        }

        $columns = [];

        foreach ($rows as $row) {
            $table = (string) ($row['TABLE_NAME'] ?? $row['table_name'] ?? '');
            $column = (string) ($row['COLUMN_NAME'] ?? $row['column_name'] ?? '');
            $type = strtolower((string) ($row['DATA_TYPE'] ?? $row['data_type'] ?? ''));

            if ('' === $table || '' === $column) {
                continue;
            }

            $columns[$table][$column] = $type;
        }

        return $this->columns = $columns;
    }
}
