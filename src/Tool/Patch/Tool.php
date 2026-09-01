<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Patch;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuditedUpdater;
use PhpMcp\Server\Attributes\McpTool;

/**
 * Replace one passage inside a long text column instead of rewriting the column.
 *
 * The `*_update` tools take whole field values, which is right for a headline
 * and wrong for a 9 KB SCSS blob: changing three numbers means reproducing
 * every other byte from memory, and every reproduction is a chance to lose
 * something that was never part of the change. AL-07 rewrote one theme field
 * five times to adjust font sizes.
 *
 * The safety property is the occurrence count. `old` must appear exactly
 * `expect_occurrences` times (default 1); anything else refuses without
 * touching the record, so an anchor that turns out to be ambiguous — or to have
 * been edited away — fails loudly instead of patching the wrong place. `dry_run`
 * shows the match in context first.
 *
 * The write goes through the table's own `*_update` tool, so the patch is an
 * ordinary edit: field validation, Versions snapshot, tl_log entry,
 * changed_fields. A patch is therefore as recoverable as any other change —
 * which matters, because the whole point is to avoid holding 9 KB in your head.
 */
final class Tool
{
    /**
     * Both sides of the patch travel in the tool call, so they are bounded by
     * what a client can sensibly send — not by anything in the database.
     */
    private const MAX_PATCH_LENGTH = 100000;

    /** How much of the surrounding text a dry run shows around each match. */
    private const CONTEXT_CHARS = 80;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly AuditedUpdater $updater,
        private readonly McpPermissionGuard $guard,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'entity_field_patch',
        description: <<<'DESC'
            Replaces one passage inside a single text column, instead of sending the whole
            new value. Made for long fields — theme SCSS, an html module, a form
            confirmation — where rewriting everything to change three lines is both
            expensive and risky.

            Parameters:
              - table, id, field:    which column to patch. See the writable_tables list in
                                     the error if the table is not supported.
              - old:                 the exact passage to replace. Whitespace and
                                     indentation count.
              - new:                 what to put there. Pass "" to delete the passage.
              - expect_occurrences:  how many times `old` must appear (default 1). Any other
                                     count refuses and writes NOTHING — that is the guard
                                     against patching the wrong place with an anchor that
                                     turned out to be ambiguous.
              - dry_run:             report the matches with surrounding context and the
                                     resulting size, without writing.

            Returns patched, occurrences, field_size_before / field_size_after and the
            changed_fields of the underlying update. The write goes through the table's own
            *_update tool, so it gets the same validation, Versions snapshot and tl_log
            entry as a normal edit — and can be rolled back the same way.
            DESC,
    )]
    public function patch(
        string $table,
        int $id,
        string $field,
        string $old,
        string $new = '',
        int $expect_occurrences = 1,
        bool $dry_run = false,
    ): array {
        $this->framework->initialize();

        if (!$this->updater->supports($table)) {
            return [
                'error' => 'table_not_patchable',
                'message' => sprintf('"%s" has no audited update tool, so it cannot be patched.', $table),
                'writable_tables' => AuditedUpdater::tables(),
            ];
        }
        if ($id <= 0) {
            return ['error' => 'invalid_input', 'message' => 'Pass the id of the record to patch.'];
        }
        if (trim($field) === '') {
            return ['error' => 'invalid_input', 'message' => 'Pass the column to patch as `field`.'];
        }
        if ($old === '') {
            return [
                'error' => 'invalid_input',
                'message' => 'Pass the passage to replace as `old`. An empty `old` would match everywhere and has no meaning here.',
            ];
        }
        if (\strlen($old) > self::MAX_PATCH_LENGTH || \strlen($new) > self::MAX_PATCH_LENGTH) {
            return [
                'error' => 'patch_too_long',
                'message' => sprintf('`old` and `new` are limited to %d bytes each.', self::MAX_PATCH_LENGTH),
            ];
        }
        if ($expect_occurrences < 1) {
            return ['error' => 'invalid_input', 'message' => 'expect_occurrences must be at least 1.'];
        }

        // Same gate a direct update would pass, before the record is disclosed.
        $denial = $this->guard->ensureCan($table, 'update', $id, [$field => $new]);
        if ($denial !== null) {
            return $denial;
        }

        // $table has already passed the AuditedUpdater whitelist above, which
        // is a fixed list of constants — that is the only reason it may be
        // interpolated into an identifier position here.
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT * FROM %s WHERE id = ?', $table),
            [$id],
        );

        if ($row === false) {
            return ['error' => 'not_found', 'message' => sprintf('No record with id %d in %s.', $id, $table)];
        }

        $column = $this->columnName($row, $field);
        if ($column === null) {
            return [
                'error' => 'unknown_field',
                'message' => sprintf('"%s" has no column "%s".', $table, $field),
            ];
        }

        $current = $row[$column];
        if ($current !== null && !\is_scalar($current)) {
            return [
                'error' => 'not_patchable',
                'message' => sprintf('"%s.%s" does not hold text.', $table, $column),
            ];
        }
        $current = (string) $current;

        $occurrences = substr_count($current, $old);

        if ($occurrences !== $expect_occurrences) {
            return [
                'error' => 'occurrence_mismatch',
                'message' => sprintf(
                    'Expected %d occurrence(s) of the passage in %s.%s, found %d. Nothing was written — narrow the anchor, or set expect_occurrences to the real count.',
                    $expect_occurrences,
                    $table,
                    $column,
                    $occurrences,
                ),
                'occurrences' => $occurrences,
                'expected' => $expect_occurrences,
                'field_size' => \strlen($current),
            ];
        }

        $patched = str_replace($old, $new, $current);

        // A serialised column carries byte-length prefixes: `s:7:"Karriere"`
        // stops being readable the moment the string inside it changes length,
        // and Contao then reads the whole column as empty. The replacement
        // itself succeeds and the tool would report success — silent
        // corruption, which is worse than a refusal.
        //
        // Reading stays allowed: the dry run above is genuinely useful for
        // finding out what is inside such a column, which is exactly how the
        // grass-merkur report located a field name without database access.
        if (!$dry_run && self::isSerialised($current)) {
            return [
                'error' => 'serialised_field',
                'message' => sprintf(
                    '"%s.%s" holds a serialised value (Contao stores several things in this column). '
                    .'A text replacement would break its length prefixes and Contao would read the column as empty. '
                    .'Use the update tool for this table instead — it writes the parts separately. '
                    .'A dry run on this field still works if you only want to read it.',
                    $table,
                    $column,
                ),
                'table' => $table,
                'id' => $id,
                'field' => $column,
            ];
        }

        if ($dry_run) {
            return [
                'dry_run' => true,
                'table' => $table,
                'id' => $id,
                'field' => $column,
                'occurrences' => $occurrences,
                'field_size_before' => \strlen($current),
                'field_size_after' => \strlen($patched),
                'matches' => $this->contextsOf($current, $old),
            ];
        }

        if ($patched === $current) {
            return [
                'patched' => false,
                'table' => $table,
                'id' => $id,
                'field' => $column,
                'occurrences' => $occurrences,
                'field_size_before' => \strlen($current),
                'field_size_after' => \strlen($current),
                'changed_fields' => [],
                'message' => '`new` is identical to `old`; the record was not touched.',
            ];
        }

        $result = $this->updater->save($table, $id, [$column => $patched]);

        if (isset($result['error'])) {
            return [
                'error' => (string) $result['error'],
                'message' => (string) ($result['message'] ?? ''),
                'table' => $table,
                'id' => $id,
                'field' => $column,
            ];
        }

        $changed = \is_array($result['changed_fields'] ?? null) ? $result['changed_fields'] : [];

        return [
            'patched' => $changed !== [],
            'table' => $table,
            'id' => $id,
            'field' => $column,
            'occurrences' => $occurrences,
            'field_size_before' => \strlen($current),
            'field_size_after' => \strlen($patched),
            'changed_fields' => $changed,
        ];
    }

    /**
     * Each match with the text around it, so a dry run shows WHERE the patch
     * would land rather than only how often it matched.
     *
     * @return list<array{offset: int, context: string}>
     */
    /**
     * True when the column holds a PHP-serialised structure.
     *
     * Deliberately conservative: it must actually unserialise, so a piece of
     * prose that happens to start with `a:` is not mistaken for one. Objects
     * are not instantiated on the way in.
     */
    private static function isSerialised(string $value): bool
    {
        if ($value === '' || !preg_match('/^[aOs]:\d+:/', $value)) {
            return false;
        }

        return @unserialize($value, ['allowed_classes' => false]) !== false;
    }

    private function contextsOf(string $haystack, string $needle): array
    {
        $out = [];
        $offset = 0;

        while (($position = strpos($haystack, $needle, $offset)) !== false) {
            $from = max(0, $position - self::CONTEXT_CHARS);
            $length = \strlen($needle) + 2 * self::CONTEXT_CHARS;

            $out[] = [
                'offset' => $position,
                'context' => ($from > 0 ? '…' : '')
                    .substr($haystack, $from, $length)
                    .($from + $length < \strlen($haystack) ? '…' : ''),
            ];

            $offset = $position + \strlen($needle);
        }

        return $out;
    }

    /**
     * Column lookup that tolerates the case MySQL hands back.
     *
     * @param array<string, mixed> $row
     */
    private function columnName(array $row, string $field): ?string
    {
        if (\array_key_exists($field, $row)) {
            return $field;
        }

        foreach (array_keys($row) as $key) {
            if (strcasecmp((string) $key, $field) === 0) {
                return (string) $key;
            }
        }

        return null;
    }
}
