<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\FormField;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\FormFieldModel;
use Contao\FormModel;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\QueryFilterResolver;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;

/**
 * MCP facade for tl_form_field (the actual input widgets inside a form).
 * Seven tools:
 *   form_fields_list, form_field_get, form_field_create, form_field_update,
 *   form_field_delete, form_field_types_list, form_field_palette_get.
 *
 * Field types are dynamic — bundles register additional widgets through
 * $GLOBALS['TL_FFL']. We resolve the allowed columns per type at runtime,
 * same pattern as tl_module / tl_content.
 */
final class Tool
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly FieldMapper $mapper,
        private readonly QueryFilterResolver $filterResolver,
        private readonly McpPermissionGuard $guard,
    ) {
    }

    /**
     * @param object|null $filters DCA-validated filter map. See entity_query_options("tl_form_field").
     *
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'form_fields_list',
        description: 'Lists tl_form_field rows under one form, sorted by their `sorting` column. q does LIKE-search across DCA-searchable fields plus the serialised `options` blob, so a checkbox or select label is findable by its text; filters is a DCA-validated equality map; updated_after/before take Unix-ts or ISO-8601.',
    )]
    public function list(
        int $form_id,
        bool $include_inactive = true,
        int $limit = 100,
        int $offset = 0,
        ?string $q = null,
        #[Schema(type: 'object')] mixed $filters = null,
        ?string $updated_after = null,
        ?string $updated_before = null,
    ): array {
        $this->framework->initialize();

        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);

        $columns = ['tl_form_field.pid = ?'];
        $values = [$form_id];
        if (!$include_inactive) {
            $columns[] = "tl_form_field.invisible = ''";
        }

        // `options` is a serialised blob and not DCA-searchable, but it holds
        // the text a person actually reads — a checkbox label, a select entry.
        $search = $this->filterResolver->buildSearchClause('tl_form_field', $q, ['options']);
        if ($search !== null) {
            $columns[] = $search['clause'];
            $values = array_merge($values, $search['params']);
        }
        try {
            $filtersArr = $this->filterResolver->normaliseFilters($filters);
            $fr = $this->filterResolver->buildFilterClauses('tl_form_field', $filtersArr);
            $columns = array_merge($columns, $fr['clauses']);
            $values = array_merge($values, $fr['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_filter', 'message' => $e->getMessage()];
        }
        try {
            $ts = $this->filterResolver->buildTstampRange('tl_form_field', $updated_after, $updated_before);
            $columns = array_merge($columns, $ts['clauses']);
            $values = array_merge($values, $ts['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $items = FormFieldModel::findBy(
            $columns,
            $values,
            ['order' => 'tl_form_field.sorting', 'limit' => $limit, 'offset' => $offset],
        );

        $out = [];
        foreach ($items ?? [] as $f) {
            if (!$this->guard->mayRead('tl_form_field', $f->row())) {
                continue;
            }
            $out[] = Serializer::summary($f);
        }

        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'form_field_get',
        description: 'Returns a single tl_form_field row with every column. Serialised blobs (options, attributes) are decoded.',
    )]
    public function get(int $id): array
    {
        $this->framework->initialize();

        $field = FormFieldModel::findByPk($id);
        if ($field === null) {
            return ['error' => 'not_found', 'message' => sprintf('No form_field with id %d', $id)];
        }

        return Serializer::full($field);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'form_field_create',
        description: <<<'DESC'
            Creates a new tl_form_field row.

            Required: form_id (tl_form.id), type (e.g. "text", "textarea", "select",
            "checkbox", "radio", "captcha", "submit", "html", "password", "hidden",
            "fieldsetStart", "fieldsetStop", "explanation").

            Type-specific values go in `fields` — call form_field_palette_get(type) to
            see the exact list. Common examples:
              - text: name, label, mandatory, placeholder, rgxp, minlength, maxlength
              - select / radio / checkbox: name, label, mandatory, options (list of {value, label, default?, group?})
              - submit: slabel (button label), imageSubmit (bool), class, accesskey
              - captcha: label, placeholder

            Default sorting = max(sorting) + 128 in the same form.
        DESC,
    )]
    /**
     * @param object|null $fields Type-specific form_field columns as a JSON object. Use form_field_palette_get(type) for the allowed keys.
     */
    public function create(int $form_id, string $type, #[Schema(type: 'object')] mixed $fields = null): array
    {
        $this->framework->initialize();

        if (FormModel::findByPk($form_id) === null) {
            return ['error' => 'invalid_input', 'message' => sprintf('No form with id %d', $form_id)];
        }

        $field = new FormFieldModel();
        $field->pid = $form_id;
        $field->tstamp = time();
        $field->type = $type;
        $field->invisible = 0;

        $max = (int) $this->connection->fetchOne(
            'SELECT MAX(sorting) FROM tl_form_field WHERE pid = ?',
            [$form_id],
        );
        $field->sorting = $max + 128;

        $input = array_merge(['pid' => $form_id, 'type' => $type], self::normaliseFields($fields));

        try {
            $this->mapper->apply($field, $input, detectChanges: false);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        try {
            $field->save();
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage()];
        }

        $this->bootVersions((int) $field->id)->create();
        $this->log(sprintf('Created form_field type=%s (id=%d, form=%d)', $type, (int) $field->id, $form_id), __METHOD__);

        return ['created' => true, 'id' => (int) $field->id] + Serializer::full($field);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'form_field_update',
        description: 'Updates a tl_form_field row. Pass id, then `fields` as a JSON OBJECT. Pass `type` inside fields to switch widget type (palette of the new type is used for validation).',
    )]
    /**
     * @param object|null $fields form_field columns to change as a JSON object.
     */
    public function update(int $id, #[Schema(type: 'object')] mixed $fields = null): array
    {
        $this->framework->initialize();

        $field = FormFieldModel::findByPk($id);
        if ($field === null) {
            return ['error' => 'not_found', 'message' => sprintf('No form_field with id %d', $id)];
        }

        $input = self::normaliseFields($fields);
        if ($input === []) {
            return ['error' => 'no_fields', 'message' => 'fields must be a non-empty JSON object'];
        }

        $versions = $this->bootVersions($id);

        try {
            $changed = $this->mapper->apply($field, $input, detectChanges: true);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }
        if ($changed === []) {
            return [
                'updated' => false,
                'id' => (int) $field->id,
                'changed_fields' => [],
                'applied' => 0,
            ] + Serializer::full($field);
        }

        $field->tstamp = time();
        $field->save();
        $versions->create();

        $this->log(sprintf('Updated form_field id=%d (fields: %s)', $id, implode(', ', $changed)), __METHOD__);

        return [
            'updated' => true,
            'id' => (int) $field->id,
            'changed_fields' => $changed,
            'applied' => \count($changed),
        ] + Serializer::full($field);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'form_field_delete',
        description: 'Deletes a tl_form_field row. Requires confirm_destructive=true to proceed.',
    )]
    public function delete(int $id, bool $confirm_destructive = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'form_field_delete is irreversible. Pass confirm_destructive=true to proceed.',
            ];
        }

        $field = FormFieldModel::findByPk($id);
        if ($field === null) {
            return ['error' => 'not_found', 'message' => sprintf('No form_field with id %d', $id)];
        }

        $this->bootVersions($id);
        $type = (string) $field->type;
        $formId = (int) $field->pid;
        $field->delete();

        $this->log(sprintf('Deleted form_field type=%s (id=%d, form=%d)', $type, $id, $formId), __METHOD__);

        return ['deleted' => true, 'id' => $id];
    }

    /**
     * @return array{types: list<string>, count: int}
     */
    #[McpTool(
        name: 'form_field_types_list',
        description: 'Lists every tl_form_field type registered via $GLOBALS["TL_FFL"] (built-in + bundle-contributed). Pass any returned type to form_field_palette_get.',
    )]
    public function typesList(): array
    {
        $types = $this->mapper->listTypes();

        return ['types' => $types, 'count' => \count($types)];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'form_field_palette_get',
        description: 'Returns the field set valid for a given form-field type (live tl_form_field DCA palette + common columns).'
            .' Sub-palette children are listed only when their toggle is part of the palette of THIS type — Contao keeps one wide table per DCA, so a column existing on the row does not mean the type has it.'
            .' `subpalettes` maps each toggle to the fields it opens; a toggle and its children may be set in the same call.',
    )]
    public function paletteGet(string $type): array
    {
        try {
            $fields = $this->mapper->allowedFieldsFor($type);
        } catch (\Throwable $e) {
            return ['error' => 'load_failed', 'message' => $e->getMessage()];
        }

        $known = \in_array($type, $this->mapper->listTypes(), true);

        return [
            'type' => $type,
            'known' => $known,
            'fields' => $fields,
            'count' => \count($fields),
            'subpalettes' => $this->mapper->subpalettesFor($type),
        ];
    }

    // ─────────────────────────── helpers ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function normaliseFields(mixed $fields): array
    {
        if ($fields === null) {
            return [];
        }
        if (\is_object($fields)) {
            return (array) $fields;
        }
        if (\is_array($fields)) {
            if ($fields !== [] && array_is_list($fields)) {
                throw new \InvalidArgumentException('`fields` must be a JSON object, not a list.');
            }
            return $fields;
        }

        throw new \InvalidArgumentException('`fields` must be a JSON object.');
    }

    private function bootVersions(int $id): Versions
    {
        $v = new Versions('tl_form_field', $id);
        $v->setUsername($this->authorResolver->getLogUsername());
        $v->setUserId($this->authorResolver->resolve());
        $v->initialize();

        return $v;
    }

    private function log(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::GENERAL, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }
}
