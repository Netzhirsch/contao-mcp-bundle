<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Contao\Model;

/**
 * Snapshot + diff helper for the update-tool family.
 *
 * Used to detect true no-ops in update tools whose FieldMapper does NOT
 * already track changes (Layout, Theme, Member, ImageSize, ImageSize-Item).
 * The Option-B tools (Content, Calendar, …) compute changes inside their
 * FieldMappers and don't need this helper.
 *
 * Usage pattern:
 *   $before = UpdateDiff::snapshot($model);
 *   $this->mapper->apply($model, $input);
 *   $changed = UpdateDiff::diff($model, $before, self::PUBLIC_TO_COLUMN, array_keys($input));
 *   if ($changed === []) { return ['updated' => false, ...]; }
 *   $model->save();
 *
 * Why string-cast both sides:
 *   Contao stores boolean fields as '' / '1' strings via DCA eval->isBoolean,
 *   stores nullable ints as '' too. A fresh model loaded from the DB carries
 *   those string types; FieldMappers typically cast to int / bool when
 *   writing back. Strict !== would report every assignment as a change.
 *   String-cast flattens 0 vs '0' vs '' vs null into the same equivalence
 *   class, which matches Contao's own "did the column change" semantics.
 */
final class UpdateDiff
{
    /**
     * Snapshot ALL columns of the model BEFORE applyFields runs. Cheap —
     * `$model->row()` is a plain array view onto already-loaded data, no
     * DB round-trip.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Model $model): array
    {
        return $model->row();
    }

    /**
     * Walk the candidate public field names and compare each one's column
     * value against the pre-applyFields snapshot.
     *
     * @param array<string, mixed>  $before          Snapshot from {@see snapshot()}.
     * @param array<string, string> $publicToColumn  Map of API field-name → Contao column-name. Keys with no entry default to the API name being also the column name (the common case).
     * @param list<string>          $candidateKeys   Only check these public keys (typically array_keys($input)); empty = check every column in the snapshot.
     *
     * @return list<string>  Public field names whose value changed.
     */
    public static function diff(
        Model $model,
        array $before,
        array $publicToColumn = [],
        array $candidateKeys = [],
    ): array {
        if ($candidateKeys === []) {
            $candidateKeys = array_keys($before);
        }

        $changed = [];
        foreach ($candidateKeys as $publicKey) {
            $column = $publicToColumn[$publicKey] ?? $publicKey;
            if (!\array_key_exists($column, $before)) {
                continue;
            }
            $newValue = $model->{$column};
            if ((string) $newValue !== (string) $before[$column]) {
                $changed[] = $publicKey;
            }
        }
        return $changed;
    }
}
