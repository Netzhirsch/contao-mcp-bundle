<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\OpenGraph;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Model;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Service\AuditedUpdater;
use Netzhirsch\ContaoMcpBundle\Service\FieldProviderRegistry;
use Netzhirsch\ContaoMcpBundle\Service\ToolError;
use numero2\Opengraph3Bundle\Opengraph3Bundle;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema as InputSchema;
use Psr\Log\LoggerInterface;

/**
 * MCP facade for numero2/contao-opengraph3.
 *
 * The extension splits its 60-odd fields across two very different places, and
 * that split is the whole reason this tool exists. Ten of them are real columns
 * on the host table. Every other one lives inside the `og_properties` blob as a
 * serialised list of `[name, value]` pairs — a format the generic writers have
 * no way to know, and a single wrong write replaces the lot.
 *
 * Worse, the blob is validated against `og_type` by the Backend widget, which
 * **drops** rows that do not belong to the current type the next time an editor
 * saves the record. A value written under the wrong type is not refused; it
 * disappears silently, hours or weeks later. So this tool refuses up front and
 * names the type that would accept the field, rather than writing something
 * that looks like it worked.
 *
 * What the caller sees is one flat map. Which half of the storage a field lands
 * in is our problem, not theirs — {@see Schema} derives both halves from the
 * live DCA so an upstream release that adds a property needs no change here.
 *
 * Writes go through {@see AuditedUpdater}, so they are versioned and land in
 * tl_log with the calling user attached, exactly like every other write path.
 */
final class Tool
{
    /**
     * Marker class for the optional host bundle. The Bundle class is the most
     * stable thing to test for across releases.
     */
    private const MARKER_CLASS = Opengraph3Bundle::class;
    private const REQUIRED_EXTENSION = 'numero2/contao-opengraph3';

    /** Tables the extension ships support for out of the box. */
    private const KNOWN_TABLES = ['tl_page', 'tl_news', 'tl_calendar_events', 'tl_faq'];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly AuditedUpdater $updater,
        private readonly Schema $schema,
        private readonly FieldProviderRegistry $providers,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'opengraph_get',
        description: <<<'DESC'
            Reads the OpenGraph / X-Card data of one record (numero2/contao-opengraph3).

            Returns the real columns and the `og_properties` blob merged into ONE flat
            `fields` map — you do not need to know which of the two a field lives in.

            Also returned:
              - allowed_types:      the og:type values THIS table accepts. tl_news only
                                    takes "article", tl_calendar_events only "website".
              - valid_properties:   the property fields the current og_type keeps.
              - stale_properties:   properties currently stored that the current og_type
                                    does NOT keep. The Backend widget will drop these the
                                    next time an editor saves the record — they are lost
                                    data waiting to happen, usually from a type change.

            File columns (og_image, twitter_image) come back as 32-char hex UUIDs plus a
            resolved `*_path` for readability.
            DESC,
    )]
    public function get(string $table, int $id): array
    {
        if (($err = $this->ensure($table)) !== null) {
            return $err;
        }

        $row = $this->row($table, $id);

        if ($row === null) {
            return [
                'error' => 'record_not_found',
                'message' => sprintf('No row with id %d in "%s".', $id, $table),
            ];
        }

        $type = (string) ($row['og_type'] ?? '');
        $valid = $this->schema->propertiesForType($type);
        $stored = $this->decodeProperties($row[Schema::PROPERTIES_COLUMN] ?? null);

        // The provider already produces the flat map for every generic read
        // path; using it here keeps one description of the record rather than
        // two that can disagree.
        $fields = [];

        foreach ($this->providers->availableForTable($table) as $provider) {
            if (!$provider instanceof AbstractFieldProvider) {
                continue;
            }

            $model = $this->model($table, $id);

            if ($model !== null) {
                $fields = $provider->serialize($model);
            }

            break;
        }

        return [
            'table' => $table,
            'id' => $id,
            'og_type' => $type,
            'allowed_types' => $this->schema->typesForTable($table),
            'fields' => $fields,
            'valid_properties' => $valid,
            'stale_properties' => array_values(array_diff(array_keys($stored), $valid)),
        ];
    }

    /**
     * @param object|array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'opengraph_set',
        description: <<<'DESC'
            Writes OpenGraph / X-Card data on one record (numero2/contao-opengraph3).

            Pass `fields` as ONE flat object using the extension's own field names —
            og_title, og_type, og_description, og_article_author, twitter_card, … The
            tool routes each one to its column or into the `og_properties` blob and
            rewrites that blob as a whole, preserving the properties you did not mention.

            Refusals you should expect, and why they are refusals rather than fixes:
              - a property that the effective og_type does not keep. The Backend would
                accept the write and then drop the value on the next save, so writing it
                would be worse than refusing. The error names the types that DO keep it.
              - an og_type this table does not allow (see opengraph_get.allowed_types).

            The effective type is the og_type in THIS call when you pass one, otherwise
            the stored one — so setting a type and its properties in a single call works.

            og_image / twitter_image accept a 32-char hex UUID, a dashed UUID, or a file
            path like "files/og/teaser.jpg". Pass "" to clear a field.

            Use dry_run to see the routing, the refusals and the resulting blob without
            touching the record.
            DESC,
    )]
    public function set(
        string $table,
        int $id,
        #[InputSchema(type: 'object')] mixed $fields,
        bool $dry_run = false,
    ): array {
        if (($err = $this->ensure($table)) !== null) {
            return $err;
        }

        $input = $this->toMap($fields);

        if ($input === null) {
            return [
                'error' => 'invalid_input',
                'message' => '`fields` must be an object of field => value.',
            ];
        }

        if ($input === []) {
            return [
                'error' => 'invalid_input',
                'message' => '`fields` is empty — nothing to write.',
            ];
        }

        $row = $this->row($table, $id);

        if ($row === null) {
            return [
                'error' => 'record_not_found',
                'message' => sprintf('No row with id %d in "%s".', $id, $table),
            ];
        }

        $declared = [...$this->schema->columns(), Schema::PROPERTIES_COLUMN, ...$this->schema->propertyNames()];
        $unknown = array_values(array_diff(array_keys($input), $declared));

        if ($unknown !== []) {
            return [
                'error' => 'unknown_field',
                'message' => sprintf(
                    'Not OpenGraph fields: %s. Call opengraph_types("%s") for the full list.',
                    implode(', ', $unknown),
                    $table,
                ),
                'unknown_fields' => $unknown,
            ];
        }

        $before = $this->decodeProperties($row[Schema::PROPERTIES_COLUMN] ?? null);

        $effectiveType = \array_key_exists('og_type', $input)
            ? trim((string) $input['og_type'])
            : (string) ($row['og_type'] ?? '');

        // A dry run is the same code on a throwaway model, not a second
        // implementation of the rules — the two drifting apart would make the
        // preview a lie exactly when someone leans on it.
        $rehearsal = $this->rehearse($table, $id, $input);

        if (isset($rehearsal['error'])) {
            return $rehearsal;
        }

        $plan = [
            'table' => $table,
            'id' => $id,
            'effective_og_type' => $effectiveType,
            'fields_written' => $rehearsal['touched'],
            'properties_after' => $rehearsal['properties'],
            'properties_pruned_by_type' => array_values(array_diff(
                array_keys($before),
                array_keys($rehearsal['properties']),
                array_keys($input),
            )),
        ];

        if ($dry_run) {
            return ['dry_run' => true] + $plan;
        }

        try {
            $result = $this->updater->save($table, $id, $input);
        } catch (\Throwable $e) {
            return ToolError::opaque($this->logger, $e, 'save_failed', ['table' => $table, 'id' => $id]);
        }

        if (isset($result['error'])) {
            return $result;
        }

        return ['updated' => true] + $plan;
    }

    /**
     * Runs the provider against a detached copy of the record, so the caller
     * learns what a write would do — including how it would be refused —
     * without anything being stored.
     *
     * @param array<string, mixed> $input
     *
     * @return array{touched: list<string>, properties: array<string, string>}|array{error: string, message: string}
     */
    private function rehearse(string $table, int $id, array $input): array
    {
        $provider = null;

        foreach ($this->providers->availableForTable($table) as $candidate) {
            if ($candidate instanceof AbstractFieldProvider) {
                $provider = $candidate;
                break;
            }
        }

        if ($provider === null) {
            return [
                'error' => 'opengraph_not_on_table',
                'message' => sprintf('No OpenGraph field provider is registered for "%s".', $table),
            ];
        }

        $modelClass = Model::getClassFromTable($table);

        if (!\is_string($modelClass) || !class_exists($modelClass)) {
            return [
                'error' => 'table_not_writable',
                'message' => sprintf('"%s" has no Contao model, so the write cannot be rehearsed.', $table),
            ];
        }

        /** @var Model|null $model */
        $model = $modelClass::findByPk($id);

        if ($model === null) {
            return [
                'error' => 'record_not_found',
                'message' => sprintf('No row with id %d in "%s".', $id, $table),
            ];
        }

        $copy = clone $model;

        try {
            $touched = $provider->apply($copy, $input, true);
        } catch (Refusal $e) {
            return [
                'error' => $e->errorCode,
                'message' => $e->getMessage(),
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'error' => 'invalid_input',
                'message' => $e->getMessage(),
            ];
        }

        return [
            'touched' => $touched,
            'properties' => $this->decodeProperties($copy->{Schema::PROPERTIES_COLUMN} ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'opengraph_types',
        description: <<<'DESC'
            Lists the og:types a table accepts and which property fields each one keeps
            (numero2/contao-opengraph3).

            Read this before opengraph_set when you are unsure where a field belongs:
            the same property name is valid under one type and silently dropped under
            another, and tables restrict the type list (tl_news → "article" only,
            tl_calendar_events → "website" only).

            `columns` are the fields that exist independently of og_type.
            DESC,
    )]
    public function types(string $table): array
    {
        if (($err = $this->ensure($table)) !== null) {
            return $err;
        }

        $byType = ['' => $this->schema->propertiesForType('')];

        foreach ($this->schema->typesForTable($table) as $type) {
            $byType[$type] = $this->schema->propertiesForType($type);
        }

        return [
            'table' => $table,
            'allowed_types' => $this->schema->typesForTable($table),
            'columns' => $this->schema->columns(),
            'properties_by_type' => $byType,
            'note' => 'The "" entry is what applies before any og_type is chosen; every '
                .'type keeps those as well.',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ensure(string $table): ?array
    {
        if (!class_exists(self::MARKER_CLASS)) {
            return [
                'error' => 'extension_not_available',
                'message' => 'This tool requires the optional Contao extension "'.self::REQUIRED_EXTENSION
                    .'", which is not installed in this project. Use installed_bundles to inspect availability.',
                'required_extension' => self::REQUIRED_EXTENSION,
            ];
        }

        $this->framework->initialize();

        if (!$this->schema->coversTable($table)) {
            return [
                'error' => 'opengraph_not_on_table',
                'message' => sprintf(
                    '"%s" carries no OpenGraph fields. The extension attaches them per table; '
                    .'out of the box that is %s. A project can add more via OpenGraphFields::addToTable().',
                    $table,
                    implode(', ', self::KNOWN_TABLES),
                ),
                'known_tables' => self::KNOWN_TABLES,
            ];
        }

        if (!$this->updater->supports($table)) {
            return [
                'error' => 'table_not_writable',
                'message' => sprintf('No audited update path is wired for "%s".', $table),
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $table, int $id): ?array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM '.$this->connection->quoteIdentifier($table).' WHERE id = ?',
            [$id],
        );

        return $row === false ? null : $row;
    }

    /**
     * The widget stores a serialised list of [name, value] pairs. Anything else
     * in there is data we did not write and must not silently reshape, so a row
     * that is not a pair is skipped rather than guessed at.
     *
     * @return array<string, string>
     */
    private function decodeProperties(mixed $raw): array
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
            if (!\is_array($row) || !isset($row[0]) || !\is_scalar($row[0])) {
                continue;
            }
            $out[(string) $row[0]] = (string) ($row[1] ?? '');
        }

        return $out;
    }

    private function model(string $table, int $id): ?Model
    {
        $class = Model::getClassFromTable($table);

        if (!\is_string($class) || !class_exists($class)) {
            return null;
        }

        /** @var Model|null $model */
        $model = $class::findByPk($id);

        return $model;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function toMap(mixed $fields): ?array
    {
        if (\is_object($fields)) {
            $fields = get_object_vars($fields);
        }

        if (!\is_array($fields)) {
            return null;
        }

        $out = [];

        foreach ($fields as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
