<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;

/**
 * Reads DCA introspection and translates LLM-supplied query parameters
 * (`q`, `filters`, `updated_after`/`updated_before`) into safe DBAL
 * WHERE-clauses.
 *
 * The resolver is the single source of truth for "what is queryable on table
 * X" — every list-tool that wires search/filter capabilities goes through
 * here so the rules stay consistent and security-relevant validation
 * (column allow-list, parameter binding, no string-interpolation of user
 * data) doesn't have to be re-implemented 12 times.
 *
 * Field discovery follows Contao's DCA conventions:
 *
 *   - searchable: fields with `'search' => true` (same as Backend quicksearch)
 *   - filterable: fields with `'filter' => true` (same as Backend filter-panel)
 *
 * Only fields the bundle author EXPLICITLY marked as searchable / filterable
 * are exposed — never internal columns like password hashes, session blobs,
 * or trusted-token versions.
 */
final class QueryFilterResolver
{
    /**
     * Per-call cache so a list-tool can call discover() + buildSearchClause()
     * + buildFilterClauses() without reloading the same DCA three times.
     *
     * @var array<string, array{table: string, searchable_fields: list<string>, filterable_fields: array<string, array<string, mixed>>, has_tstamp: bool}>
     */
    private array $cache = [];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Returns the queryable shape of a DCA — used by both internal clause-
     * builders and the public `entity_query_options` discovery tool.
     *
     * @return array{table: string, searchable_fields: list<string>, filterable_fields: array<string, array<string, mixed>>, has_tstamp: bool}
     */
    public function discover(string $table): array
    {
        if (isset($this->cache[$table])) {
            return $this->cache[$table];
        }

        $this->framework->initialize();
        $controller = $this->framework->getAdapter(Controller::class);
        $controller->loadDataContainer($table);

        $dca = $GLOBALS['TL_DCA'][$table] ?? null;
        if ($dca === null || !\is_array($dca)) {
            throw new \InvalidArgumentException(sprintf('Unknown or unloaded DCA: %s', $table));
        }

        $searchable = [];
        $filterable = [];

        foreach (($dca['fields'] ?? []) as $name => $field) {
            if (!\is_array($field)) {
                continue;
            }
            if (($field['search'] ?? false) === true) {
                $searchable[] = (string) $name;
            }
            if (($field['filter'] ?? false) === true) {
                $filterable[(string) $name] = $this->describeFilterableField($field);
            }
        }

        return $this->cache[$table] = [
            'table' => $table,
            'searchable_fields' => $searchable,
            'filterable_fields' => $filterable,
            'has_tstamp' => isset($dca['fields']['tstamp']),
        ];
    }

    /**
     * Builds the `q`-LIKE-clause across all DCA-searchable fields, or null
     * when the table has no searchable fields or the query is empty.
     *
     * @return array{clause: string, params: list<string>}|null
     */
    public function buildSearchClause(string $table, ?string $q): ?array
    {
        if ($q === null || trim($q) === '') {
            return null;
        }
        $opts = $this->discover($table);
        if ($opts['searchable_fields'] === []) {
            return null;
        }

        // Escape SQL LIKE metachars so a query like "100%" or "name_" matches
        // literally instead of as a wildcard. Backslashes are doubled because
        // MySQL's default LIKE escape is also `\`.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($q));
        $needle = '%'.$escaped.'%';

        $tableId = $this->connection->quoteIdentifier($table);
        $clauses = [];
        $params = [];
        foreach ($opts['searchable_fields'] as $field) {
            $clauses[] = sprintf('%s.%s LIKE ?', $tableId, $this->connection->quoteIdentifier($field));
            $params[] = $needle;
        }

        return ['clause' => '('.implode(' OR ', $clauses).')', 'params' => $params];
    }

    /**
     * Builds equality / IN clauses for a filters-map. Throws on unknown
     * columns (strict mode — only DCA-declared filter fields are allowed).
     *
     * Filter values:
     *   - scalar       → `col = ?`
     *   - null         → `col IS NULL`
     *   - list<scalar> → `col IN (?, ?, ...)`
     *   - empty list   → `1 = 0` (matches no rows — explicit "nothing")
     *
     * @param array<string, mixed> $filters
     *
     * @return array{clauses: list<string>, params: list<mixed>}
     *
     * @throws \InvalidArgumentException on a disallowed or unknown column
     */
    public function buildFilterClauses(string $table, array $filters): array
    {
        if ($filters === []) {
            return ['clauses' => [], 'params' => []];
        }

        $opts = $this->discover($table);
        $allowed = $opts['filterable_fields'];

        $tableId = $this->connection->quoteIdentifier($table);
        $clauses = [];
        $params = [];

        foreach ($filters as $field => $value) {
            if (!\is_string($field) || $field === '') {
                throw new \InvalidArgumentException('filter keys must be non-empty strings.');
            }
            if (!isset($allowed[$field])) {
                throw new \InvalidArgumentException(sprintf(
                    'Filter field "%s" is not allowed for %s. Allowed: %s. Use entity_query_options to discover the full set.',
                    $field,
                    $table,
                    implode(', ', array_keys($allowed)) ?: '(none)',
                ));
            }

            $col = sprintf('%s.%s', $tableId, $this->connection->quoteIdentifier($field));

            if (\is_array($value)) {
                if ($value === []) {
                    $clauses[] = '1 = 0';
                    continue;
                }
                $clauses[] = sprintf('%s IN (%s)', $col, implode(', ', array_fill(0, \count($value), '?')));
                foreach ($value as $v) {
                    $params[] = $v;
                }
                continue;
            }
            if ($value === null) {
                $clauses[] = sprintf('%s IS NULL', $col);
                continue;
            }

            $clauses[] = sprintf('%s = ?', $col);
            $params[] = $value;
        }

        return ['clauses' => $clauses, 'params' => $params];
    }

    /**
     * Builds `tstamp >= ?` / `tstamp <= ?` clauses from string inputs.
     * Accepts Unix-timestamps (string of digits) or ISO-8601 / common
     * datetime formats.
     *
     * @return array{clauses: list<string>, params: list<int>}
     *
     * @throws \InvalidArgumentException on unparseable input or table without tstamp
     */
    public function buildTstampRange(string $table, ?string $after, ?string $before): array
    {
        if ($after === null && $before === null) {
            return ['clauses' => [], 'params' => []];
        }

        $opts = $this->discover($table);
        if (!$opts['has_tstamp']) {
            throw new \InvalidArgumentException(sprintf(
                'Table %s has no tstamp column — updated_after / updated_before cannot be applied.',
                $table,
            ));
        }

        $tableId = $this->connection->quoteIdentifier($table);
        $clauses = [];
        $params = [];

        if ($after !== null) {
            $clauses[] = sprintf('%s.%s >= ?', $tableId, $this->connection->quoteIdentifier('tstamp'));
            $params[] = $this->parseTimestamp($after, 'updated_after');
        }
        if ($before !== null) {
            $clauses[] = sprintf('%s.%s <= ?', $tableId, $this->connection->quoteIdentifier('tstamp'));
            $params[] = $this->parseTimestamp($before, 'updated_before');
        }

        return ['clauses' => $clauses, 'params' => $params];
    }

    /**
     * Helper for list-tools: accepts the raw `filters` input (which may be
     * a stdClass via php-mcp's object decoding, an assoc-array, or null)
     * and normalises it to an array<string, mixed>.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException on a JSON list (which can't be a key→value map)
     */
    public function normaliseFilters(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (\is_object($raw)) {
            return get_object_vars($raw);
        }
        if (\is_array($raw)) {
            if (array_is_list($raw)) {
                throw new \InvalidArgumentException('filters must be a JSON object (key→value), not a list.');
            }
            return $raw;
        }

        throw new \InvalidArgumentException('filters must be a JSON object.');
    }

    private function parseTimestamp(string $input, string $paramName): int
    {
        $input = trim($input);
        if ($input === '') {
            throw new \InvalidArgumentException(sprintf('%s is empty.', $paramName));
        }

        if (preg_match('/^\d+$/', $input) === 1) {
            return (int) $input;
        }

        // Try the most common formats in descending strictness.
        foreach ([\DateTimeImmutable::RFC3339, 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $input);
            if ($dt !== false) {
                return $dt->getTimestamp();
            }
        }

        throw new \InvalidArgumentException(sprintf(
            '%s: cannot parse "%s". Use a Unix timestamp or ISO-8601 (e.g. 2024-12-31T23:59:59).',
            $paramName,
            $input,
        ));
    }

    /**
     * @param array<string, mixed> $field
     *
     * @return array<string, mixed>
     */
    private function describeFilterableField(array $field): array
    {
        $info = ['type' => (string) ($field['inputType'] ?? 'text')];

        if (isset($field['options']) && \is_array($field['options'])) {
            $info['type'] = 'enum';
            // tl_dca options can be assoc (value→label) or numeric list. Normalise to
            // the value-set so Claude knows what to send.
            $info['values'] = array_is_list($field['options'])
                ? $field['options']
                : array_keys($field['options']);
        }
        if (isset($field['foreignKey']) && \is_string($field['foreignKey'])) {
            $info['type'] = 'foreign_key';
            $info['references'] = $field['foreignKey'];
        }
        if (isset($field['eval']) && \is_array($field['eval'])) {
            $eval = $field['eval'];
            if (($eval['isBoolean'] ?? false) === true) {
                $info['type'] = 'boolean';
            } elseif (isset($eval['rgxp']) && \in_array($eval['rgxp'], ['date', 'datim', 'time'], true)) {
                $info['type'] = (string) $eval['rgxp'];
            }
            if (isset($eval['multiple']) && $eval['multiple'] === true) {
                $info['multiple'] = true;
            }
        }

        return $info;
    }
}
