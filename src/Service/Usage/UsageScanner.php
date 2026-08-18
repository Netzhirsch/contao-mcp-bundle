<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service\Usage;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Answers "where is this thing actually used?" across the whole installation.
 *
 * Three independent sources, because Contao has three ways of pointing at a
 * record and only the first is visible to a naive `WHERE x = id`:
 *
 *   1. STRUCTURAL — a picker column: `tl_module.jumpTo = 42`,
 *      `tl_content.singleSRC = <uuid>`, `tl_layout.modules` (serialized).
 *      Discovered from the DCA, see {@see ReferenceFieldMap}.
 *   2. INSERT TAGS — free text anywhere: `{{link::42}}`, `{{link::kontakt}}`,
 *      `{{file::<uuid>}}`, `{{insert_module::7}}`.
 *   3. FILE CONTENTS — for files only: `@import` in SCSS, `url()` in CSS, a
 *      path written into a template. See {@see FileContentScanner}.
 *
 * Two-stage matching throughout: SQL narrows with `LOCATE()` (substring, no
 * wildcard escaping to get wrong, works on blobs), then PHP decides. The SQL
 * side is allowed to over-fetch — `LOCATE('::4')` also hits `{{link::42}}` —
 * because only the PHP verification lands in the result. Getting this the
 * other way round would mean either false blocks or missed references.
 */
final class UsageScanner
{
    /** A reference we can prove. Blocks a deletion. */
    public const CONFIDENCE_CERTAIN = 'certain';

    /** Looks like a reference but cannot be proven — reported, never blocks. */
    public const CONFIDENCE_POSSIBLE = 'possible';

    /**
     * Tables that are never "usage": caches, logs and history. A hit in
     * tl_search only means the crawler saw the page once; a hit in tl_version
     * or tl_undo is a snapshot of the PAST, and blocking a deletion because
     * the record used to be referenced would make deletions impossible after
     * the first edit. Skipping them is also most of the speed.
     *
     * @var array<string, true>
     */
    private const VOLATILE_TABLES = [
        'tl_search' => true,
        'tl_search_index' => true,
        'tl_search_term' => true,
        'tl_version' => true,
        'tl_undo' => true,
        'tl_log' => true,
        'tl_session' => true,
        'tl_remember_me' => true,
        'tl_trusted_device' => true,
        'tl_cron_job' => true,
        'tl_crawl_queue' => true,
        'tl_opt_in' => true,
        'tl_opt_in_related' => true,
        'tl_rate_limit' => true,
        'tl_preview_link' => true,
        // This bundle's own OAuth bookkeeping — tokens, not content.
        'tl_mcp_oauth_access_token' => true,
        'tl_mcp_oauth_authcode' => true,
        'tl_mcp_oauth_refresh_token' => true,
        'tl_mcp_oauth_iat' => true,
    ];

    /**
     * Tables whose references are permission scope, not content.
     *
     * `tl_user.pagemounts`, `tl_user_group.filemount` and friends list what a
     * backend user may reach. Contao ignores ids in there that no longer
     * exist, so a stale entry breaks nothing — while nearly every root page
     * sits in somebody's pagemounts, which would make page deletion
     * permanently "blocked" for no reason. Reported, never blocking.
     *
     * @var array<string, true>
     */
    private const PERMISSION_TABLES = [
        'tl_user' => true,
        'tl_user_group' => true,
    ];

    /** Candidate rows fetched per table before PHP verification. */
    private const CANDIDATES_PER_TABLE = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly SchemaIndex $schema,
        private readonly ReferenceFieldMap $fieldMap,
        private readonly FileContentScanner $fileScanner,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, list<int>> $ignore     Rows that must not count as
     *                                             referrers — typically the ones
     *                                             a cascading delete removes anyway
     * @param bool                     $scanFiles  Read file contents (files only; the
     *                                             expensive part)
     * @param int                      $limit      Max references to return
     *
     * @return array{
     *     in_use: bool,
     *     total: int,
     *     blocking: int,
     *     truncated: bool,
     *     references: list<array<string, mixed>>,
     *     scanned: array<string, mixed>,
     *     notes: list<string>,
     * }
     */
    public function scan(UsageTarget $target, array $ignore = [], bool $scanFiles = true, int $limit = 100): array
    {
        $limit = max(1, $limit);
        $references = [];
        $notes = [];
        $truncated = false;

        $tables = array_values(array_filter(
            $this->schema->tables(),
            static fn (string $t): bool => !isset(self::VOLATILE_TABLES[$t]),
        ));

        $structural = $this->fieldMap->columnsPointingAt($target->table, $tables);
        $tags = InsertTagMap::tagsFor($target->table);
        $needles = $target->insertTagNeedles();

        foreach ($tables as $table) {
            if (\count($references) >= $limit) {
                $truncated = true;
                break;
            }

            $capped = false;

            try {
                $found = $this->scanTable($table, $target, $structural[$table] ?? [], $tags, $needles, $ignore, $capped);
            } catch (\Throwable $e) {
                // One unreadable table must not sink the whole answer — but the
                // caller has to know the picture is incomplete, otherwise an
                // empty result reads as "safe to delete".
                $this->logger->warning('Usage scan failed for a table.', ['table' => $table, 'exception' => $e]);
                $notes[] = sprintf('Table "%s" could not be scanned — results may be incomplete.', $table);

                continue;
            }

            if ($capped) {
                $notes[] = sprintf(
                    'Table "%s" produced more than %d candidate rows — only the first were checked.',
                    $table,
                    self::CANDIDATES_PER_TABLE,
                );
            }

            foreach ($found as $reference) {
                $references[] = $reference;
            }
        }

        $filesScanned = 0;

        if ($scanFiles && null !== $target->path) {
            $remaining = max(0, $limit - \count($references));

            $result = UsageTarget::TABLE_TEMPLATES === $target->table
                ? $this->fileScanner->scanTemplate($target, $remaining)
                : $this->fileScanner->scan($target, $remaining);

            $references = [...$references, ...$result['references']];
            $filesScanned = $result['files_scanned'];
            $notes = [...$notes, ...$result['notes']];
        }

        if (\count($references) > $limit) {
            $truncated = true;
            $references = \array_slice($references, 0, $limit);
        }

        $blocking = \count(array_filter($references, static fn (array $r): bool => self::blocks($r)));

        return [
            'in_use' => $blocking > 0,
            'total' => \count($references),
            'blocking' => $blocking,
            'truncated' => $truncated,
            'references' => $references,
            'scanned' => [
                'tables' => \count($tables),
                'structural_columns' => array_sum(array_map(static fn (array $c): int => \count($c), $structural)),
                'insert_tags' => $tags,
                'files' => $filesScanned,
            ],
            'notes' => $notes,
        ];
    }

    /**
     * A reference stops a deletion only when it is both provable AND breaking.
     *
     * @param array<string, mixed> $reference
     */
    public static function blocks(array $reference): bool
    {
        return self::CONFIDENCE_CERTAIN === ($reference['confidence'] ?? '')
            && true === ($reference['blocking'] ?? false);
    }

    /**
     * @param list<array{field: string, encoding: string}> $structural
     * @param list<string>                                 $tags
     * @param list<string>                                 $needles
     * @param array<string, list<int>>                     $ignore
     *
     * @return list<array<string, mixed>>
     */
    private function scanTable(string $table, UsageTarget $target, array $structural, array $tags, array $needles, array $ignore, bool &$capped = false): array
    {
        $capped = false;

        if (!$this->schema->hasColumn($table, 'id')) {
            return [];
        }

        $textColumns = ([] !== $tags && [] !== $needles) ? $this->schema->textColumns($table) : [];

        if ([] === $structural && [] === $textColumns) {
            return [];
        }

        $quoted = $this->connection->quoteIdentifier($table);
        $where = [];
        $params = [];
        $columns = ['id' => true];

        foreach ($structural as $entry) {
            $columns[$entry['field']] = true;
            $predicate = $this->structuralPredicate($entry['field'], $entry['encoding'], $target, $params);

            if (null !== $predicate) {
                $where[] = $predicate;
            }
        }

        if ([] !== $textColumns) {
            // One haystack per row instead of one predicate per column: a
            // table with 40 text columns would otherwise produce 40 × needles
            // OR-terms. NUL as separator can't occur inside an insert tag.
            $haystack = 'CONCAT_WS(0x00, '.implode(', ', array_map(
                fn (string $c): string => $this->connection->quoteIdentifier($c),
                $textColumns,
            )).')';

            foreach ($needles as $i => $needle) {
                $key = 'tag'.$i;
                $where[] = sprintf('LOCATE(:%s, %s) > 0', $key, $haystack);
                $params[$key] = '::'.$needle;
            }

            foreach ($textColumns as $column) {
                $columns[$column] = true;
            }
        }

        if ([] === $where) {
            return [];
        }

        $select = implode(', ', array_map(
            fn (string $c): string => $this->connection->quoteIdentifier($c),
            array_keys($columns),
        ));

        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT %s FROM %s WHERE %s LIMIT %d', $select, $quoted, implode(' OR ', $where), self::CANDIDATES_PER_TABLE),
            $params,
        );

        $capped = \count($rows) >= self::CANDIDATES_PER_TABLE;
        $ignored = $ignore[$table] ?? [];
        $out = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);

            // The row is itself going away, or IS the target.
            if (\in_array($id, $ignored, true) || ($table === $target->table && $id === $target->id)) {
                continue;
            }

            $isPermission = isset(self::PERMISSION_TABLES[$table]);

            foreach ($structural as $entry) {
                if (ReferenceValue::matches($row[$entry['field']] ?? null, $entry['encoding'], $target)) {
                    $out[] = [
                        'source' => 'db_field',
                        'confidence' => self::CONFIDENCE_CERTAIN,
                        'blocking' => !$isPermission,
                        'table' => $table,
                        'id' => $id,
                        'field' => $entry['field'],
                        'detail' => $isPermission
                            ? sprintf('%s.%s grants access to it — a stale entry is harmless', $table, $entry['field'])
                            : sprintf('%s.%s points at this %s', $table, $entry['field'], $target->type),
                    ];
                }
            }

            foreach ($textColumns as $column) {
                $value = $row[$column] ?? null;

                if (!\is_string($value) || '' === $value) {
                    continue;
                }

                foreach ($this->matchInsertTags($value, $tags, $needles) as $match) {
                    $out[] = [
                        'source' => 'insert_tag',
                        'confidence' => self::CONFIDENCE_CERTAIN,
                        'blocking' => true,
                        'table' => $table,
                        'id' => $id,
                        'field' => $column,
                        'tag' => $match['tag'],
                        'snippet' => $match['snippet'],
                        'detail' => sprintf('%s.%s contains %s', $table, $column, $match['tag']),
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params Bound by reference — predicates add their own
     */
    private function structuralPredicate(string $field, string $encoding, UsageTarget $target, array &$params): ?string
    {
        $column = $this->connection->quoteIdentifier($field);
        $key = 'ref_'.$field;

        switch ($encoding) {
            case ReferenceFieldMap::ENC_INT:
                if ($target->id <= 0) {
                    return null;
                }
                $params[$key] = $target->id;

                return sprintf('%s = :%s', $column, $key);

            case ReferenceFieldMap::ENC_UUID:
                $terms = [];

                foreach ($target->uuids() as $i => $uuid) {
                    $params[$key.'_'.$i] = StringUtil::uuidToBin($uuid);
                    $terms[] = sprintf('%s = :%s', $column, $key.'_'.$i);
                }

                return [] === $terms ? null : '('.implode(' OR ', $terms).')';

            case ReferenceFieldMap::ENC_UUID_LIST:
                $terms = [];

                // Binary-safe substring search: the serialized blob holds the
                // raw 16 bytes, which LIKE could not be given without escaping
                // whatever `%` or `_` the UUID happens to contain.
                foreach ($target->uuids() as $i => $uuid) {
                    $params[$key.'_'.$i] = StringUtil::uuidToBin($uuid);
                    $terms[] = sprintf('LOCATE(:%s, %s) > 0', $key.'_'.$i, $column);
                }

                return [] === $terms ? null : '('.implode(' OR ', $terms).')';

            case ReferenceFieldMap::ENC_TEMPLATE_NAME:
                $terms = [];

                // Exact equality — a template selector holds the name and
                // nothing else, so there is no reason to fuzzy-match and every
                // reason not to ("text" would hit half the install).
                foreach ($target->aliases as $i => $name) {
                    $params[$key.'_'.$i] = $name;
                    $terms[] = sprintf('%s = :%s', $column, $key.'_'.$i);
                }

                return [] === $terms ? null : '('.implode(' OR ', $terms).')';

            case ReferenceFieldMap::ENC_INT_LIST:
            case ReferenceFieldMap::ENC_IMAGE_SIZE:
            case ReferenceFieldMap::ENC_MODULE_WIZARD:
                if ($target->id <= 0) {
                    return null;
                }
                // `s:2:"42";` for string members, `i:42;` for integer members —
                // Contao produces both depending on the widget. Verified in PHP.
                $params[$key] = ':"'.$target->id.'";';
                $params[$key.'_i'] = 'i:'.$target->id.';';

                return sprintf('(LOCATE(:%s, %s) > 0 OR LOCATE(:%s, %s) > 0)', $key, $column, $key.'_i', $column);

            default:
                return null;
        }
    }

    /**
     * @param list<string> $tags
     * @param list<string> $needles
     *
     * @return list<array{tag: string, snippet: string}>
     */
    private function matchInsertTags(string $value, array $tags, array $needles): array
    {
        $out = [];
        $seen = [];

        foreach ($needles as $needle) {
            if (1 !== preg_match(InsertTagMap::pattern($tags, $needle), $value, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $tag = sprintf('{{%s::%s}}', $m[1][0], $needle);

            if (isset($seen[$tag])) {
                continue;
            }

            $seen[$tag] = true;
            $out[] = ['tag' => $tag, 'snippet' => self::snippet($value, (int) $m[0][1])];
        }

        return $out;
    }

    /**
     * A readable window around the match. `$offset` is a BYTE offset from
     * preg_match, so the window is cut from the raw string first and only
     * then cleaned — cutting mid-character is repaired below rather than
     * risking a broken-UTF-8 string in the JSON response.
     */
    private static function snippet(string $text, int $offset): string
    {
        $start = max(0, $offset - 60);
        $raw = substr($text, $start, 220);
        $clean = trim(preg_replace('/\s+/', ' ', strip_tags($raw)) ?? $raw);

        if (!mb_check_encoding($clean, 'UTF-8')) {
            $clean = (string) mb_convert_encoding($clean, 'UTF-8', 'UTF-8');
        }

        return ($start > 0 ? '…' : '').mb_strimwidth($clean, 0, 160, '…');
    }
}
