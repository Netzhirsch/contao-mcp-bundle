<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\OpenGraph;

use Contao\FilesModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Model;
use Netzhirsch\ContaoMcpBundle\Tool\Contract\FieldProvider;
use numero2\Opengraph3Bundle\Opengraph3Bundle;

/**
 * Teaches the generic read/write path about numero2/contao-opengraph3.
 *
 * Without this, two of the shared writer's guards fire and both are right to:
 * `og_type` takes its options from an `options_callback` the writer cannot
 * evaluate, and `og_properties` is a custom widget over a blob. Both were
 * refused rather than written from a guess — which left the fields reachable
 * through nothing at all.
 *
 * A provider is the seam the bundle already has for this. Declared fields skip
 * the scalar writer entirely and become this class's responsibility, so the
 * knowledge about the storage lives in exactly one place and every path — the
 * dedicated opengraph_* tools, page_update, entity_field_patch — goes through it.
 *
 * The caller always sees one flat map. Which of the 62 fields is a column and
 * which lives inside the `og_properties` blob is settled here, from the live
 * DCA, so an upstream release that adds a property needs no change.
 *
 * The one rule worth restating: the Backend widget keeps only the properties
 * belonging to the current `og_type` and drops the rest on the next save. A
 * write that ignores that does not fail — it disappears weeks later. So a
 * mismatch is refused here, naming the type that would accept it.
 */
abstract class AbstractFieldProvider implements FieldProvider
{
    public function __construct(
        protected readonly ContaoFramework $framework,
        protected readonly Schema $schema,
    ) {
    }

    abstract public function getTable(): string;

    public function getRequiredExtension(): string
    {
        return 'numero2/contao-opengraph3';
    }

    public function isAvailable(): bool
    {
        return class_exists(Opengraph3Bundle::class);
    }

    /**
     * Columns and properties alike — the caller should not have to know which
     * is which.
     *
     * @return list<string>
     */
    public function getDeclaredFields(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        return array_values(array_unique([
            ...$this->schema->columns(),
            Schema::PROPERTIES_COLUMN,
            ...$this->schema->propertyNames(),
        ]));
    }

    /**
     * OpenGraph fields do not depend on the entity's own type (a `regular` page
     * and a `root` page carry the same set), so the entity-type gate passes
     * everything. The gate that does matter here is `og_type`, which is a value
     * on the record rather than its type, and is enforced in {@see apply()}.
     *
     * @return list<string>
     */
    public function getAllowedFields(?string $type): array
    {
        return $this->getDeclaredFields();
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Model $model): array
    {
        if (!$this->isAvailable() || !$this->schema->coversTable($this->getTable())) {
            return [];
        }

        $out = [];

        foreach ($this->schema->columns() as $column) {
            $value = $model->$column ?? null;

            $out[$column] = \in_array($column, $this->schema->uuidColumns(), true)
                ? (\is_string($value) && $value !== '' ? bin2hex($value) : null)
                : $value;
        }

        foreach ($this->decode($model->{Schema::PROPERTIES_COLUMN} ?? null) as $name => $value) {
            $out[$name] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException when a value cannot be stored as asked
     */
    public function apply(Model $model, array $input, bool $detectChanges): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $table = $this->getTable();
        $columns = $this->schema->columns();
        $properties = $this->schema->propertyNames();

        $touched = [];

        // The type this write is judged against: the one being set in this very
        // call when there is one, otherwise what the record already carries.
        // Any other reading would make "set the type and its fields at once"
        // fail for no reason a caller could guess.
        $effectiveType = \array_key_exists('og_type', $input)
            ? trim((string) $input['og_type'])
            : (string) ($model->og_type ?? '');

        if (\array_key_exists('og_type', $input)) {
            $allowed = $this->schema->typesForTable($table);

            if ($effectiveType !== '' && !\in_array($effectiveType, $allowed, true)) {
                throw new Refusal('type_not_allowed', sprintf(
                    'og_type "%s" is not offered on %s. This table accepts: %s.',
                    $effectiveType,
                    $table,
                    $allowed === [] ? '(none)' : implode(', ', $allowed),
                ));
            }
        }

        $validProperties = $this->schema->propertiesForType($effectiveType);

        foreach ($columns as $column) {
            if (!\array_key_exists($column, $input)) {
                continue;
            }

            $value = $this->castColumn($column, $input[$column]);

            if (!$detectChanges || ($model->$column ?? null) !== $value) {
                $model->$column = $value;
                $touched[] = $column;
            }
        }

        $incoming = [];

        foreach ($properties as $property) {
            if (\array_key_exists($property, $input)) {
                $incoming[$property] = (string) $input[$property];
            }
        }

        // `og_properties` may also be handed over wholesale, as a map. Useful
        // for "replace everything" and for callers that read it back and send
        // it on; the individual names above win when both are present.
        $wholesale = $input[Schema::PROPERTIES_COLUMN] ?? null;
        $replaceAll = false;

        if ($wholesale !== null) {
            $replaceAll = true;
            $map = \is_object($wholesale) ? get_object_vars($wholesale) : $wholesale;

            if (!\is_array($map)) {
                throw new Refusal('invalid_value', sprintf(
                    '"%s" must be an object of property => value (for example {"og_description": "…"}), '
                    .'not a plain string — it is stored as a structure, and a string would corrupt it.',
                    Schema::PROPERTIES_COLUMN,
                ));
            }

            foreach ($map as $name => $value) {
                $incoming[(string) $name] ??= (string) $value;
            }
        }

        $rejected = [];

        foreach (array_keys($incoming) as $name) {
            if (!\in_array($name, $properties, true)) {
                throw new Refusal('unknown_field', sprintf(
                    '"%s" is not an OpenGraph property. Call opengraph_types("%s") for the list.',
                    $name,
                    $table,
                ));
            }

            if (!\in_array($name, $validProperties, true)) {
                $rejected[$name] = $this->schema->typesAllowing($table, $name);
            }
        }

        if ($rejected !== []) {
            throw new Refusal('property_not_valid_for_type', $this->explainRejection($effectiveType, $rejected, $validProperties));
        }

        if ($incoming === [] && !\array_key_exists('og_type', $input)) {
            return $touched;
        }

        $stored = $this->decode($model->{Schema::PROPERTIES_COLUMN} ?? null);

        // A type change prunes exactly what the Backend would have pruned, now
        // and visibly, instead of leaving rows that vanish on the next save.
        $merged = $replaceAll ? [] : array_filter(
            $stored,
            static fn (string $name): bool => \in_array($name, $validProperties, true),
            ARRAY_FILTER_USE_KEY,
        );

        foreach ($incoming as $name => $value) {
            if ($value === '') {
                unset($merged[$name]);
                continue;
            }
            $merged[$name] = $value;
        }

        $encoded = $this->encode($merged);

        if (!$detectChanges || ($model->{Schema::PROPERTIES_COLUMN} ?? null) !== $encoded) {
            $model->{Schema::PROPERTIES_COLUMN} = $encoded;
            $touched[] = Schema::PROPERTIES_COLUMN;
        }

        return array_values(array_unique($touched));
    }

    /**
     * @param array<string, list<string>> $rejected
     * @param list<string>                $valid
     */
    private function explainRejection(string $type, array $rejected, array $valid): string
    {
        $detail = [];

        foreach ($rejected as $name => $types) {
            $detail[] = sprintf(
                '"%s" (%s)',
                $name,
                $types === []
                    ? 'not offered on this table at all'
                    : 'needs og_type '.implode(' or ', array_map(static fn (string $t): string => '"'.$t.'"', $types)),
            );
        }

        return sprintf(
            'og_type "%s" does not keep %s. This is refused rather than written because the Backend widget '
            .'discards properties outside the current type the next time the record is saved — the value would '
            .'vanish later instead of failing now. Set a matching og_type in the same call to write both. '
            .'Valid right now: %s.',
            $type,
            implode(', ', $detail),
            $valid === [] ? '(none)' : implode(', ', $valid),
        );
    }

    /**
     * The widget stores a serialised list of [name, value] pairs. A row that is
     * not a pair is data we did not write, so it is skipped rather than guessed
     * at.
     *
     * @return array<string, string>
     */
    protected function decode(mixed $raw): array
    {
        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        $rows = @unserialize($raw, ['allowed_classes' => false]);

        if (!\is_array($rows)) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            if (\is_array($row) && isset($row[0]) && \is_scalar($row[0])) {
                $out[(string) $row[0]] = (string) ($row[1] ?? '');
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $properties
     */
    protected function encode(array $properties): ?string
    {
        if ($properties === []) {
            // NULL rather than an empty serialised array: that is what an
            // untouched record holds, and the widget reads both the same.
            return null;
        }

        $rows = [];

        foreach ($properties as $name => $value) {
            $rows[] = [$name, $value];
        }

        return serialize($rows);
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected function castColumn(string $column, mixed $value): mixed
    {
        if (!\in_array($column, $this->schema->uuidColumns(), true)) {
            return \is_scalar($value) || $value === null ? $value : (string) json_encode($value);
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        $hex = str_replace('-', '', $raw);

        if (\strlen($hex) === 32 && preg_match('/^[0-9a-fA-F]+$/', $hex) === 1) {
            return hex2bin($hex);
        }

        // Not a UUID — read it as a file path, which is what a caller holding a
        // file listing actually has.
        $file = $this->framework->getAdapter(FilesModel::class)->findByPath($raw);

        if ($file === null) {
            throw new Refusal('invalid_value', sprintf(
                '"%s" takes a 32-char hex UUID, a dashed UUID, or the path of a file in the Contao '
                .'file manager — "%s" is none of those (no such file).',
                $column,
                $raw,
            ));
        }

        return $file->uuid;
    }
}
