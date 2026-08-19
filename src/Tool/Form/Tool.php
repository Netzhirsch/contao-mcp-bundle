<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Form;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\FormFieldModel;
use Contao\FormModel;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\DbalRetry;
use Netzhirsch\ContaoMcpBundle\Service\QueryFilterResolver;
use Netzhirsch\ContaoMcpBundle\Service\SubmittedKeys;
use Netzhirsch\ContaoMcpBundle\Service\UpdateDiff;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;

/**
 * MCP facade for tl_form (form containers). Five tools:
 *   forms_list, form_get, form_create, form_update, form_delete.
 *
 * Form fields (tl_form_field) live in their own tool family
 * ({@see \Netzhirsch\ContaoMcpBundle\Tool\FormField\Tool}). Deleting a form
 * with form_delete cascades into its fields unless force=false (default).
 */
final class Tool
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly DbalRetry $dbalRetry,
        private readonly FieldMapper $mapper,
        private readonly QueryFilterResolver $filterResolver,
        private readonly McpPermissionGuard $guard,
    ) {
    }

    /**
     * @param object|null $filters DCA-validated filter map. See entity_query_options("tl_form").
     *
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'forms_list',
        description: 'Lists tl_form rows (form containers). Legacy `search` does a hardcoded title LIKE; prefer `q` (honours DCA-searchable fields). filters is a DCA-validated equality map; updated_after/before take Unix-ts or ISO-8601.',
    )]
    public function list(
        ?string $search = null,
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

        $columns = [];
        $values = [];
        if ($search !== null && trim($search) !== '') {
            $columns[] = 'tl_form.title LIKE ?';
            $values[] = '%'.trim($search).'%';
        }

        $newSearch = $this->filterResolver->buildSearchClause('tl_form', $q);
        if ($newSearch !== null) {
            $columns[] = $newSearch['clause'];
            $values = array_merge($values, $newSearch['params']);
        }
        try {
            $filtersArr = $this->filterResolver->normaliseFilters($filters);
            $fr = $this->filterResolver->buildFilterClauses('tl_form', $filtersArr);
            $columns = array_merge($columns, $fr['clauses']);
            $values = array_merge($values, $fr['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_filter', 'message' => $e->getMessage()];
        }
        try {
            $ts = $this->filterResolver->buildTstampRange('tl_form', $updated_after, $updated_before);
            $columns = array_merge($columns, $ts['clauses']);
            $values = array_merge($values, $ts['params']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $items = FormModel::findBy(
            $columns === [] ? null : $columns,
            $values === [] ? null : $values,
            ['order' => 'tl_form.title', 'limit' => $limit, 'offset' => $offset],
        );

        $out = [];
        foreach ($items ?? [] as $f) {
            if (!$this->guard->mayRead('tl_form', $f->row())) {
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
        name: 'form_get',
        description: 'Returns a single tl_form row by id + the count of its tl_form_field children.',
    )]
    public function get(int $id): array
    {
        $this->framework->initialize();

        $form = FormModel::findByPk($id);
        if ($form === null) {
            return ['error' => 'not_found', 'message' => sprintf('No form with id %d', $id)];
        }

        return Serializer::full($form) + [
            'field_count' => $this->countFields($id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'form_create',
        description: <<<'DESC'
            Creates a new tl_form row.

            Required: title. Optional via `fields`:
              - alias, method ("POST"|"GET"), jump_to (page id), confirmation (text)
              - send_via_email (bool), mailer_transport, recipient, subject
              - format ("raw"|"xml"), skip_empty (bool), store_values (bool), target_table
              - custom_tpl, novalidate (bool), attributes (list<string>), form_id, ajax (bool), allow_tags

            Form fields (the actual inputs) are managed via form_field_create afterwards.
        DESC,
    )]
    /**
     * @param object|null $fields Optional form columns as a JSON object.
     */
    public function create(string $title, #[Schema(type: 'object')] mixed $fields = null): array
    {
        $this->framework->initialize();

        if (trim($title) === '') {
            return ['error' => 'invalid_input', 'message' => 'title is required'];
        }

        $form = new FormModel();
        $form->tstamp = time();
        $form->title = mb_substr(trim($title), 0, 255);
        $form->method = 'POST';
        $form->format = 'raw';

        $input = self::normaliseFields($fields);
        $ignored = [];
        if ($input !== []) {
            $result = $this->mapper->apply($form, $input, isCreate: false);
        $ignored = SubmittedKeys::ignored($input, $result['applied_keys']);
            if ($result['errors'] !== []) {
                return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $result['errors']];
            }
            // Same check update has always done. Without it create reported
            // success for fields it never wrote — the worst outcome for an
            // agent, which then builds on a row that did not take the values.
            if ($result['applied'] === 0) {
                return [
                    'error' => 'no_mappable_fields',
                    'message' => 'No mappable fields were applied — every submitted key is unknown for tl_form. Check form_get(id) for valid keys.',
                    'submitted_keys' => array_keys($input),
                ];
            }
        }

        $form->save();
        $this->log(sprintf('Created form "%s" (id=%d)', $form->title, (int) $form->id), __METHOD__);

        return ['created' => true, 'id' => (int) $form->id] + SubmittedKeys::report($ignored) + Serializer::full($form);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'form_update',
        description: 'Updates a tl_form row. Pass id, then `fields` as a JSON OBJECT with any column from form_create.',
    )]
    /**
     * @param object|null $fields Form columns to change as a JSON object.
     */
    public function update(int $id, #[Schema(type: 'object')] mixed $fields = null): array
    {
        $this->framework->initialize();

        $form = FormModel::findByPk($id);
        if ($form === null) {
            return ['error' => 'not_found', 'message' => sprintf('No form with id %d', $id)];
        }

        $input = self::normaliseFields($fields);
        if ($input === []) {
            return ['error' => 'no_fields', 'message' => 'fields must be a non-empty JSON object'];
        }

        // Snapshot before applyFields — Form's FieldMapper only tracks
        // assignments (`applied: int`), not actual value changes. We need
        // the snapshot to distinguish "row already has those values" from
        // "row was actually updated".
        $before = UpdateDiff::snapshot($form);

        $result = $this->mapper->apply($form, $input, isCreate: false);
        $ignored = SubmittedKeys::ignored($input, $result['applied_keys']);
        if ($result['errors'] !== []) {
            return ['error' => 'invalid_input', 'message' => 'field validation failed', 'errors' => $result['errors']];
        }
        if ($result['applied'] === 0) {
            // No submitted key mapped to a known column — caller mistake.
            return [
                'error' => 'no_mappable_fields',
                'message' => 'No mappable fields were applied — every submitted key is unknown for tl_form. Check form_get(id) for valid keys.',
                'submitted_keys' => array_keys($input),
            ];
        }

        $publicToColumn = [
            'send_via_email' => 'sendViaEmail',
            'recipient_email' => 'recipient',
            'mailer_transport' => 'mailerTransport',
            'attach_form_data' => 'attachFormData',
            'store_values' => 'storeValues',
            'target_table' => 'targetTable',
            'custom_template' => 'customTpl',
            'allow_tags' => 'allowTags',
        ];
        $changedFields = UpdateDiff::diff($form, $before, $publicToColumn, array_keys($input));
        if ($changedFields === []) {
            return [
                'updated' => false,
                'id' => (int) $form->id,
                'changed_fields' => [],
                'applied' => 0,
            ] + Serializer::full($form);
        }

        $versions = $this->bootVersions($id);
        $form->tstamp = time();
        $form->save();
        $versions->create();

        $this->log(sprintf('Updated form "%s" (id=%d, fields=%s)', $form->title, $id, implode(',', $changedFields)), __METHOD__);

        return [
            'updated' => true,
            'id' => $id,
            'changed_fields' => $changedFields,
            'applied' => \count($changedFields),
        ] + Serializer::full($form);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'form_delete',
        description: 'Deletes a tl_form row. Safe-by-default: refuses to drop a form that has fields, unless cascade=true (cascade-deletes tl_form_field children). Requires confirm_destructive=true to proceed.',
    )]
    public function delete(int $id, bool $confirm_destructive = false, bool $cascade = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'form_delete is irreversible. Pass confirm_destructive=true to proceed. Use cascade=true to also drop children.',
            ];
        }

        $form = FormModel::findByPk($id);
        if ($form === null) {
            return ['error' => 'not_found', 'message' => sprintf('No form with id %d', $id)];
        }

        $fieldCount = $this->countFields($id);
        if ($fieldCount > 0 && !$cascade) {
            return [
                'error' => 'has_children',
                'message' => sprintf('Form has %d field(s) — pass cascade=true to cascade-delete', $fieldCount),
                'field_count' => $fieldCount,
            ];
        }

        // Cascade-delete the form's fields THEN the form itself in a single
        // DBAL transaction so a mid-cascade failure doesn't leave orphan
        // tl_form_field rows pointing at a now-deleted form (or, conversely,
        // delete the form while fields fail to clear).
        $title = (string) $form->title;
        $this->dbalRetry->transactional($this->connection, function () use ($id, $form, $fieldCount) {
            if ($fieldCount > 0) {
                $this->connection->executeStatement('DELETE FROM tl_form_field WHERE pid = ?', [$id]);
            }
            $form->delete();
        });

        $this->log(sprintf('Deleted form "%s" (id=%d, cascaded_fields=%d)', $title, $id, $fieldCount), __METHOD__);

        return ['deleted' => true, 'id' => $id, 'cascaded_fields' => $fieldCount];
    }

    // ─────────────────────────── helpers ────────────────────────────

    private function countFields(int $formId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_form_field WHERE pid = ?',
            [$formId],
        );
    }

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
        $v = new Versions('tl_form', $id);
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
