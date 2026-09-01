<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Contao\Model;

/**
 * Returns the DCA-declared fields a serialiser does not know about by name, so
 * the read side covers exactly what the write side accepts.
 *
 * When foreign fields first became writable, only half the job was done: a
 * value could be written and then not read back. `page_get` still omitted it,
 * `pages_list(q: …)` still found nothing, and the only way to check a write was
 * a dry run of the patch tool or the front end. The grass-merkur report put it
 * exactly right — for a human that is awkward, for an agent that wants to read
 * the state, change it, and read it back, it is the difference between working
 * and working blind.
 *
 * The rule here is the symmetry itself, and it is deliberately expressed as one
 * condition rather than two lists: a field comes back if
 * {@see DcaScalarWriter} would accept it. Anything else would drift the moment
 * one side changed.
 */
final class ForeignFieldReader
{
    /**
     * @param array<string, mixed> $alreadySerialised what the hand-written
     *                                                serialiser has covered
     *
     * @return array<string, mixed> the remaining fields, keyed by name
     */
    public static function extra(string $table, Model $model, string $type, array $alreadySerialised): array
    {
        $dca = $GLOBALS['TL_DCA'][$table] ?? null;

        if (!\is_array($dca)) {
            return [];
        }

        $out = [];

        foreach (DcaPalette::resolve($dca, $type)['fields'] as $field) {
            if (\array_key_exists($field, $alreadySerialised)) {
                continue;
            }

            // Writable ⇒ readable. A column this class cannot classify is also
            // one the writer refuses, so leaving it out keeps the two sides
            // describing the same set instead of two lists that drift apart.
            if (!DcaScalarWriter::supports($table, $field)) {
                continue;
            }

            $out[$field] = self::cast($table, $field, $model->$field);
        }

        return $out;
    }

    private static function cast(string $table, string $field, mixed $value): bool|int|string
    {
        $definition = $GLOBALS['TL_DCA'][$table]['fields'][$field] ?? [];
        $sql = \is_array($definition) ? ($definition['sql'] ?? null) : null;

        $type = \is_array($sql) ? strtolower((string) ($sql['type'] ?? '')) : strtolower((string) $sql);

        return match (true) {
            $type === 'boolean' || str_contains($type, 'tinyint(1)') => (bool) $value,
            \in_array($type, ['integer', 'smallint', 'bigint'], true) || str_contains($type, 'int(') => (int) $value,
            default => (string) $value,
        };
    }
}
