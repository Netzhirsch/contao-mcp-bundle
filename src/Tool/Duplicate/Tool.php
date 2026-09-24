<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Duplicate;

use Contao\ContentModel;
use Contao\FormFieldModel;
use Contao\ModuleModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\ProviderFields;
use Netzhirsch\ContaoMcpBundle\Service\RecordDuplicator;
use Netzhirsch\ContaoMcpBundle\Service\ToolError;
use Netzhirsch\ContaoMcpBundle\Tool\Content\FieldMapper as ContentFieldMapper;
use Netzhirsch\ContaoMcpBundle\Tool\FormField\FieldMapper as FormFieldFieldMapper;
use Netzhirsch\ContaoMcpBundle\Tool\Module\FieldMapper as ModuleFieldMapper;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;

/**
 * Generic, DCA-driven record duplication for the high-value Contao trees:
 * pages, articles and content elements. Mirrors the backend "copy" button —
 * children cascade (article → content, container → nested content, page →
 * articles), `doNotCopy` fields regenerate, external-id mappings are reset.
 *
 * The heavy lifting is in {@see RecordDuplicator}; this tool adds the table
 * allowlist, permission parity (read source + create under target, via the
 * same voters the CRUD tools use) and tl_log attribution.
 */
final class Tool
{
    /**
     * Tables that may be duplicated. Their child trees cascade automatically
     * via DCA `ctable`, so listing the roots is enough.
     *
     * The list started at three, which made the tool useless for exactly the
     * rows that hurt most to rebuild by hand: a `tl_module` row has around 250
     * columns and `module_create` wants each one. Copying is DCA-driven
     * throughout — ctable cascade, doNotCopy, alias regeneration — so a narrow
     * list was the arbitrary part, not a safety property.
     *
     * What is deliberately NOT here: tl_user and tl_member. Contao offers a
     * copy button for both, but it lands you in the edit mask to resolve the
     * unique username/e-mail before anything is saved. This tool writes
     * straight to the database, and a half-formed identity row is not
     * something to hand back as `duplicated: true`.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        // Page tree and its contents
        'tl_page', 'tl_article', 'tl_content',
        // Theme building blocks
        'tl_module', 'tl_layout',
        // Archives / categories and their entries
        'tl_news_archive', 'tl_news',
        'tl_calendar', 'tl_calendar_events',
        'tl_faq_category', 'tl_faq',
        // Forms
        'tl_form', 'tl_form_field',
    ];

    /**
     * The tables whose columns depend on the record's type: table => the
     * model a provider works on, the tool that lists the types.
     *
     * @var array<string, array{0: class-string<\Contao\Model>, 1: string}>
     */
    private const TYPE_DRIVEN = [
        'tl_content' => [ContentModel::class, 'content_types_list'],
        'tl_module' => [ModuleModel::class, 'module_types_list'],
        'tl_form_field' => [FormFieldModel::class, 'form_field_types_list'],
    ];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly RecordDuplicator $duplicator,
        private readonly McpPermissionGuard $guard,
        private readonly AuthorResolver $authorResolver,
        private readonly LoggerInterface $logger,
        private readonly ContentFieldMapper $contentMapper,
        private readonly ModuleFieldMapper $moduleMapper,
        private readonly FormFieldFieldMapper $formFieldMapper,
        private readonly ProviderFields $providerFields,
    ) {
    }

    /**
     * @param object|null $overrides Top-level field overrides applied to the copy (e.g. {"title": "Copy of …"}).
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'entity_duplicate',
        description: <<<'DESC'
            Duplicates a Contao record with its child tree — the MCP equivalent of the
            backend "copy" button. Use it instead of retyping a record into a *_create
            call: a tl_module row has ~250 columns and the copy carries all of them.

            Supported tables:
              tl_page, tl_article, tl_content,
              tl_module, tl_layout,
              tl_news_archive, tl_news,
              tl_calendar, tl_calendar_events,
              tl_faq_category, tl_faq,
              tl_form, tl_form_field

            What cascades automatically (DCA ctable, always):
              - tl_article           → its content elements (incl. nested elements)
              - tl_content           → nested children of container elements
                                       (accordion / element_group / swiper)
              - tl_page              → its articles (and their content)
              - tl_news / tl_calendar_events → their content elements
              - tl_news_archive / tl_calendar / tl_faq_category → EVERY entry in them
                                       (and each entry's content) — copying an archive to
                                       seed a second language copies all of it; `copied`
                                       reports the total, so check it before assuming.
              - tl_form              → its form fields

            Parameters:
              - table:         one of the tables above
              - id:            source record id
              - into_pid:      optional new parent id (default: same parent as the source,
                               i.e. duplicate in place). What "parent" means per table:
                               tl_page → parent page (0 = root level), tl_article → page,
                               tl_content → parent article/element, tl_module + tl_layout
                               → theme, tl_news → archive, tl_calendar_events → calendar,
                               tl_faq → category, tl_form_field → form. The archive,
                               calendar, category and form tables have no parent — leave
                               into_pid unset for those.
              - into_ptable:   optional parent table for tl_content (default: keep source's
                               ptable, e.g. tl_article or tl_content for nested).
              - with_children: tl_page only — also copy the whole sub-page tree (default false).
              - overrides:     object of fields to set on the TOP-LEVEL copy (e.g. {"title": "…"}).
                               Values are strings, numbers, booleans or null — `published: false`
                               is written as 0, so an unpublished copy needs no follow-up call.
                               A column the table does not have is rejected before anything
                               is copied, and so are id, pid and ptable (the parent is
                               into_pid / into_ptable). On tl_content, tl_module and
                               tl_form_field the same fields are accepted as on the table's
                               *_update tool for the copy's type (tl_content: also its new
                               parent — sectionHeadline when copying into an accordion), and
                               rsce_data on RSCE records is merged into the source's settings.
                               Your backend field permissions apply as they do on the regular
                               write tools.

            Conventions mirrored from Contao's own copy:
              - doNotCopy fields are NOT carried over. They are refilled from the DCA
                `default` where there is one, so a copied news entry is dated today
                rather than 1970, and the alias is regenerated from the record's own
                title field (headline for news, question for FAQs).
              - the author is set to the calling identity, as Contao's copy button does
                (the source's author is a doNotCopy field and is not inherited).
              - a name/title is NOT made unique — the copy carries the source's. Pass
                `overrides` to name it, exactly as the backend expects you to.
              - external-id mappings (external_id_namespace/key) are reset on every copy.
              - the copy is appended after the last sibling (fresh sorting).
              - a duplicated ROOT page gets its fallback + dns cleared (uniqueness) — set
                them explicitly afterwards via page_update.

            Permission parity: you must be allowed to READ the source and CREATE under the
            target (same voters / pagemount scope as the CRUD tools). The new primary record
            gets a Versions snapshot; the operation is logged to tl_log.

            Returns {duplicated: true, table, source_id, new_id, copied: <total rows>, tree}.
        DESC,
    )]
    public function duplicate(
        string $table,
        int $id,
        ?int $into_pid = null,
        ?string $into_ptable = null,
        bool $with_children = false,
        #[Schema(type: 'object')] mixed $overrides = null,
    ): array {
        $this->framework->initialize();

        if (($denied = $this->guard->ensureMcpAccess()) !== null) {
            return $denied;
        }

        if (!\in_array($table, self::ALLOWED, true)) {
            return [
                'error' => 'unsupported_table',
                'message' => sprintf('entity_duplicate supports: %s.', implode(', ', self::ALLOWED)),
                'supported' => self::ALLOWED,
            ];
        }

        // Read parity on the source.
        if (($denied = $this->guard->ensureCan($table, 'read', $id)) !== null) {
            return $denied;
        }

        $q = $this->connection->quoteIdentifier($table);
        $source = $this->connection->fetchAssociative("SELECT * FROM {$q} WHERE id = ?", [$id]);
        if ($source === false) {
            return ['error' => 'not_found', 'message' => sprintf('%s.%d does not exist.', $table, $id)];
        }

        // Resolve the effective target parent (default: duplicate in place).
        $intoPid = $into_pid ?? (int) ($source['pid'] ?? 0);
        $intoPtable = $into_ptable;
        if ($table === 'tl_content' && $intoPtable === null) {
            $intoPtable = (string) ($source['ptable'] ?? 'tl_article');
        }

        try {
            $overridesArr = $this->checkedOverrides($table, $source, $intoPid, $intoPtable, $this->normaliseOverrides($overrides));
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        // Create parity under the target parent, asked with what the copy WILL
        // be: its type (Contao's own copy button asks with the whole new row,
        // so a type the account may not create is refused there too) and every
        // override. Before, only pid/ptable went in, and an excluded field was
        // writable through overrides alone.
        $newData = ['pid' => $intoPid];
        if ($intoPtable !== null) {
            $newData['ptable'] = $intoPtable;
        }
        if (\array_key_exists('type', $source)) {
            $newData['type'] = (string) ($overridesArr['type'] ?? $source['type']);
        }
        $newData += $overridesArr;

        // Field rights, though, only for what the CALLER writes: the overrides
        // that change something. Copying does not edit the source's type or
        // headline — the backend copies them without asking — and demanding
        // the field right for them would make MCP stricter than the button.
        $written = [];
        foreach ($overridesArr as $column => $value) {
            $column = (string) $column;
            $before = $source[$column] ?? null;

            if (\is_scalar($value) && \is_scalar($before) && (string) $value === (string) $before) {
                continue; // the copy keeps the source's value — nothing is written
            }

            // null sets the column to NULL here; to the guard null means "not
            // sent", so it is handed over as the empty value it writes.
            $written[$column] = $value ?? '';
        }

        if (($denied = $this->guard->ensureCan($table, 'create', null, $newData, $written)) !== null) {
            return $denied;
        }

        try {
            $tree = $this->duplicator->duplicate(
                $table,
                $id,
                $into_pid,
                $intoPtable,
                $with_children,
                $overridesArr,
                $this->authorResolver->resolve(),
            );
        } catch (\InvalidArgumentException $e) {
            // A rejected override — the caller's own input, so it gets its own
            // error rather than being reported as a failed copy.
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ToolError::opaque($this->logger, $e, 'duplicate_failed', 'Tool/Duplicate/Tool duplicate_failed');
        }

        // Versions snapshot for the primary new record, attributed to the
        // calling identity (same as the CRUD create tools).
        $versions = new Versions($table, $tree['new_id']);
        $versions->setUsername($this->authorResolver->getLogUsername());
        $versions->setUserId($this->authorResolver->resolve());
        $versions->initialize();
        $versions->create();

        $this->log(
            sprintf('Duplicated %s.%d → %d (%d rows copied) via MCP', $table, $id, $tree['new_id'], $tree['copied']),
            __METHOD__,
        );

        return [
            'duplicated' => true,
            'table' => $table,
            'source_id' => $id,
            'new_id' => $tree['new_id'],
            'copied' => $tree['copied'],
            'tree' => $tree['children'],
        ];
    }

    /**
     * Holds overrides to the rules the regular write tools apply.
     *
     * They used to go into the INSERT with one question asked — does the table
     * have that column. The v1.33.0 report used exactly that as a workaround:
     * content_update refused rsce_data and sectionHeadline, a duplicate with
     * overrides wrote both without a check. One route blocking what another
     * lets through raw is not a validation, it is two.
     *
     *   - id, pid, ptable are refused on every table. A copy gets a new id,
     *     and its parent is into_pid / into_ptable — the parent the permission
     *     check above looks at. An override landed the copy somewhere else,
     *     after that check.
     *   - On the type-driven tables — tl_content, tl_module, tl_form_field —
     *     overrides take exactly what the table's *_update tool takes for the
     *     copy's type (on tl_content also for its NEW parent, so sectionHeadline
     *     when copying into an accordion), and extension columns such as
     *     rsce_data go through their provider: validated, merged into the
     *     source's value, stored in the provider's form.
     *
     * Values stay in stored form otherwise ("Serialised columns must be passed
     * as their stored string"): that is the documented contract of overrides,
     * and existing calls rely on it.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    private function checkedOverrides(string $table, array $source, int $intoPid, ?string $intoPtable, array $overrides): array
    {
        foreach (['id', 'pid', 'ptable'] as $column) {
            if (\array_key_exists($column, $overrides)) {
                throw new \InvalidArgumentException($column === 'id'
                    ? 'overrides: "id" cannot be set — a copy always gets a new id. Nothing was copied.'
                    : sprintf('overrides: "%s" cannot be set — the parent of the copy is into_pid / into_ptable, and that is where it is permission-checked. Nothing was copied.', $column));
            }
        }

        if (!isset(self::TYPE_DRIVEN[$table]) || $overrides === []) {
            return $overrides;
        }

        [$modelClass, $typesTool] = self::TYPE_DRIVEN[$table];
        $keys = array_map(strval(...), array_keys($overrides));
        $type = (string) ($overrides['type'] ?? $source['type'] ?? '');

        $known = match ($table) {
            'tl_content' => $this->contentMapper->allKnownTypes(),
            'tl_module' => $this->moduleMapper->allKnownTypes(),
            default => $this->formFieldMapper->listTypes(),
        };
        if (!\in_array($type, $known, true)) {
            throw new \InvalidArgumentException(sprintf('overrides: unknown type "%s" — see %s. Nothing was copied.', $type, $typesTool));
        }

        $rejected = match ($table) {
            'tl_content' => $this->contentMapper->rejectedFields(
                $type,
                $keys,
                $this->contentMapper->parentTypeOf((string) ($intoPtable ?? $source['ptable'] ?? ''), $intoPid),
            ),
            'tl_module' => $this->moduleMapper->rejectedFields($type, $keys),
            default => $this->formFieldMapper->rejectedFields($type, $keys),
        };
        if ($rejected !== []) {
            throw new \InvalidArgumentException('overrides: '.self::sentences($rejected).' Nothing was copied.');
        }

        $owned = array_intersect_key($overrides, array_flip($this->providerFields->declaredFor($table)));
        if ($owned === []) {
            return $overrides;
        }

        // The provider works on a model; a throwaway one carrying the source
        // row gives it the value to merge into, and is never saved.
        $probe = new $modelClass();
        $probe->setRow($source);
        $probe->type = $type;

        $result = $this->providerFields->apply($table, $probe, $owned, false, $type);
        if ($result['errors'] !== []) {
            throw new \InvalidArgumentException('overrides: '.self::sentences($result['errors']).' Nothing was copied.');
        }

        foreach ($result['applied'] as $field) {
            $overrides[$field] = $probe->$field;
        }

        return $overrides;
    }

    /**
     * The refusals as one text, each ending like a sentence — the mappers'
     * messages end in a field list, and "…, rsce_data Nothing was copied."
     * reads as one broken sentence.
     *
     * @param array<array-key, string> $messages
     */
    private static function sentences(array $messages): string
    {
        return implode(' ', array_map(
            static fn (string $message): string => rtrim($message, '. ').'.',
            array_values(array_unique($messages)),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function normaliseOverrides(mixed $overrides): array
    {
        if ($overrides === null) {
            return [];
        }
        if (\is_object($overrides)) {
            return (array) $overrides;
        }
        if (\is_array($overrides)) {
            return $overrides;
        }

        return [];
    }

    private function log(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::GENERAL, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }
}
