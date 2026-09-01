<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\DeepL;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuditedUpdater;
use Netzhirsch\ContaoMcpBundle\Service\TypePaletteFields;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;

/**
 * DeepL translation over MCP, on top of numero2/contao-deepl.
 *
 * The host bundle puts a translate button in the backend edit mask. That button
 * is unusable from here: its listener only attaches when a backend request
 * carries `do=` and `act=edit`, and its language resolvers take a
 * DataContainer. What IS reusable is the configuration — the API key parameter
 * and the DeepL library it installs — and that is what {@see Client} builds on.
 *
 * Every tool that touches records answers in one of three modes, chosen by two
 * booleans, because "translate" and "spend money" and "overwrite content" are
 * three different decisions:
 *
 *   dry_run=true            plan only. No API call, no write. Tells you which
 *                           records and fields are in scope and exactly how
 *                           many characters the real run would submit.
 *   dry_run/save both false translate and RETURN the values. Nothing is
 *                           written; you decide what to do with them.
 *   save=true               translate and write back through the table's own
 *                           `*_update` tool — Versions snapshot, tl_log entry,
 *                           changed-field reporting, permission check per
 *                           record, exactly as a direct update would.
 *
 * Translation is IN PLACE: the record you name is the record that changes. To
 * build a second-language tree, duplicate first and translate the copy —
 * `entity_duplicate(table: "tl_page", id: …, with_children: true)` followed by
 * `deepl_translate_page_tree(id: <the copy>, save: true)`. Doing it the other
 * way round overwrites the original.
 *
 * Cost is reported, never estimated after the fact: `characters_submitted` is
 * what was actually sent, which is what DeepL bills on, and
 * `characters_reused` is what a repeated or already-translated string saved.
 * The account counter from `deepl_status` lags behind by an unspecified amount
 * and is a period total, not the price of the last call.
 *
 * Because translations are cached for 30 days, looking before writing is close
 * to free: a dry run costs nothing at all, and a preview followed by a save
 * pays DeepL once.
 *
 * Fields are chosen per RECORD, not per table. tl_content, tl_module and
 * tl_form_field keep one wide table and decide by the row's type which columns
 * it has, so a column that is filled is not necessarily a column that can be
 * written — a headline element carrying a leftover `text` value from an earlier
 * type change is the ordinary case. Those columns are left out and listed as
 * `dropped_fields`, because the alternative was the update refusing the whole
 * record and the headline staying in the source language too.
 */
final class Tool
{
    /** Raw-text calls stay interactive; a bulk job belongs in the record tools. */
    private const MAX_TEXTS = 100;

    /**
     * Records per call. Measured on a real site: one page carries roughly 46
     * records, so this is about twenty pages — comfortably more than a branch,
     * far less than a whole site.
     *
     * The ceiling is not squeamishness about size, it is about what happens at
     * the far end. A run of several thousand records takes minutes of writes,
     * and an HTTP transport that times out mid-run leaves a partially
     * translated tree with no report of where it stopped. A refusal is
     * recoverable; a truncated write is not.
     */
    private const MAX_RECORDS = 1000;

    /**
     * Preview returns every source and translation, so it has a much lower
     * ceiling than a saving run — a 300-record preview would be a payload no
     * client can use.
     */
    private const MAX_PREVIEW_RECORDS = 50;

    /**
     * Default per-call character budget; 0 disables the check. Roughly 35 pages
     * at the ~7,000 characters a real page was measured to hold — a guard
     * against a runaway job, not against ordinary work.
     */
    private const DEFAULT_MAX_CHARACTERS = 250000;

    /** Columns tried, in order, when labelling a record in the answer. */
    private const LABEL_COLUMNS = ['title', 'headline', 'question', 'name', 'label'];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly Client $client,
        private readonly AuditedUpdater $saver,
        private readonly McpPermissionGuard $guard,
        private readonly TypePaletteFields $typePalette,
    ) {
    }

    // ─────────────────────────────── status ───────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'deepl_status',
        description: <<<'DESC'
            Reports whether DeepL translation is usable on this instance and which target
            languages the account supports. Requires numero2/contao-deepl to be installed
            and a DeepL API key (DEEPL_API_KEY in .env.local, or deepl.api_key in
            config.yaml) — returns extension_not_available or deepl_not_configured
            otherwise, naming which of the two is missing.

            Set include_account_usage=true to also read the account's character counter.
            Treat that number as a BILLING-PERIOD TOTAL, not as the cost of a recent call:
            DeepL's usage endpoint lags behind translation requests. The per-call cost is
            the characters_submitted field the translation tools return.
            DESC,
    )]
    public function status(bool $include_account_usage = false): array
    {
        if (($err = $this->client->unavailableReason()) !== null) {
            return $err + ['available' => false];
        }

        $out = [
            'available' => true,
            'required_extension' => Client::REQUIRED_EXTENSION,
            'translatable_tables' => TranslatableFields::tables(),
        ];

        try {
            $out['target_languages'] = $this->client->targetLanguages();
        } catch (\Throwable $e) {
            return $this->apiFailure($e);
        }

        if ($include_account_usage) {
            try {
                $out['account_usage'] = $this->client->accountUsage() + [
                    'note' => 'Billing-period total. DeepL updates this with a delay, so it is not the cost of your last call.',
                ];
            } catch (\Throwable $e) {
                return $this->apiFailure($e);
            }
        }

        return $out;
    }

    // ────────────────────────────── raw text ──────────────────────────────

    /**
     * @param list<string> $texts
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'deepl_translate',
        description: <<<'DESC'
            Translates a list of plain strings and returns them — touches no Contao record.
            Use it for text you are about to write yourself, or to check how a wording comes
            out before running a bulk job.

            Parameters:
              - texts:       list of strings (max 100). Empty entries are returned as-is
                             and cost nothing. Identical strings are translated once.
              - target_lang: DeepL target code, e.g. EN-GB, EN-US, DE, FR. Note that EN
                             alone is not a valid target — see deepl_status.
              - source_lang: optional; omit to let DeepL detect it.
              - html:        true when the strings contain markup. Sends DeepL's
                             tag_handling=html so tags and attributes survive intact.

            Returns the translations in the order given, plus characters_submitted — the
            number of source characters actually sent, which is what DeepL bills on.
            DESC,
    )]
    public function translate(
        #[Schema(type: 'array', items: ['type' => 'string'])] array $texts,
        string $target_lang,
        ?string $source_lang = null,
        bool $html = false,
    ): array {
        if (($err = $this->client->unavailableReason()) !== null) {
            return $err;
        }

        $texts = array_values(array_map(strval(...), $texts));

        if ($texts === []) {
            return ['error' => 'no_texts', 'message' => 'Pass a non-empty `texts` list.'];
        }
        if (\count($texts) > self::MAX_TEXTS) {
            return [
                'error' => 'too_many_texts',
                'message' => sprintf('At most %d texts per call, got %d.', self::MAX_TEXTS, \count($texts)),
            ];
        }
        if (trim($target_lang) === '') {
            return ['error' => 'no_target_lang', 'message' => 'Pass target_lang, e.g. "EN-GB". See deepl_status for the accepted codes.'];
        }

        $baseline = $this->client->spend();

        try {
            $result = $this->client->translate($texts, $target_lang, $source_lang, $html);
        } catch (\Throwable $e) {
            return $this->apiFailure($e);
        }

        return [
            'translations' => $result['translations'],
            'target_lang' => strtoupper(trim($target_lang)),
            'detected_source_lang' => $result['detected_source_lang'],
            'usage' => $this->client->spendSince($baseline),
        ];
    }

    // ──────────────────────────────── records ────────────────────────────────

    /**
     * @param list<int>         $ids
     * @param list<string>|null $fields
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'deepl_translate_records',
        description: <<<'DESC'
            Translates the text columns of one or more records of a single table — news,
            events, FAQs, articles, pages, content elements, form fields, modules. A single
            record is ids:[42].

            Translation is IN PLACE. To produce a second-language copy, duplicate first
            (entity_duplicate) and translate the duplicate.

            Parameters:
              - table:          see deepl_status → translatable_tables.
              - ids:            record ids (max 300).
              - target_lang:    DeepL target code, e.g. EN-GB.
              - source_lang:    optional; omit to let DeepL detect it.
              - fields:         which columns to translate. Omit for the table's sensible
                                default set. Unknown names are reported in ignored_fields
                                rather than failing the call. `alias` is not translatable
                                by design: to get a translated URL, translate the title
                                first and then pass an EMPTY alias to the table's *_update
                                tool, which regenerates the slug from the new title.
              - dry_run:        plan only. No API call, no write, no cost. Returns the
                                records, the fields in scope and the exact character count
                                the real run would submit.
              - save:           false (default) returns the translations for you to apply;
                                true writes them through the table's own *_update tool,
                                with a Versions snapshot, a tl_log entry and a per-record
                                permission check. Preview is capped at 50 records because
                                it returns every source and translation.
              - max_characters: refuses before spending anything if the plan exceeds this
                                (default 100000). Pass 0 to disable.

            Per record you get either changed_fields (save) or field → {source, translation}
            (preview). `dropped_fields` lists columns that were filled but do not belong to
            THIS record's type — tl_content, tl_module and tl_form_field decide that per row,
            so a leftover value from an earlier type change is left alone instead of taking
            the whole record down with it. A record that fails is reported and the rest
            continue.
            DESC,
    )]
    public function translateRecords(
        string $table,
        #[Schema(type: 'array', items: ['type' => 'integer'])] array $ids,
        string $target_lang,
        ?string $source_lang = null,
        #[Schema(type: 'array', items: ['type' => 'string'])] ?array $fields = null,
        bool $save = false,
        bool $dry_run = false,
        int $max_characters = self::DEFAULT_MAX_CHARACTERS,
    ): array {
        if (($err = $this->client->unavailableReason()) !== null) {
            return $err;
        }

        if (!TranslatableFields::knows($table)) {
            return [
                'error' => 'table_not_translatable',
                'message' => sprintf('"%s" has no translatable fields registered.', $table),
                'translatable_tables' => TranslatableFields::tables(),
            ];
        }

        $ids = array_values(array_unique(array_map(intval(...), $ids)));
        $ids = array_values(array_filter($ids, static fn (int $id) => $id > 0));

        if ($ids === []) {
            return ['error' => 'no_ids', 'message' => 'Pass a non-empty `ids` list.'];
        }
        if (\count($ids) > self::MAX_RECORDS) {
            return [
                'error' => 'too_many_records',
                'message' => sprintf(
                    'At most %d records per call, got %d. Split the job — the cap exists because a run that times out mid-way leaves a partly translated set with no report of where it stopped.',
                    self::MAX_RECORDS,
                    \count($ids),
                ),
            ];
        }
        if (trim($target_lang) === '') {
            return ['error' => 'no_target_lang', 'message' => 'Pass target_lang, e.g. "EN-GB".'];
        }

        $targets = array_map(static fn (int $id) => ['table' => $table, 'id' => $id], $ids);

        return $this->run($targets, $target_lang, $source_lang, $fields, $save, $dry_run, $max_characters);
    }

    // ─────────────────────────────── page tree ───────────────────────────────

    /**
     * @param list<string>|null $fields
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'deepl_translate_page_tree',
        description: <<<'DESC'
            Translates a page with its SEO meta data, its articles and their content
            elements — and, by default, the same for every page below it. One call instead
            of one per record: the whole tree is collected first, every string goes to DeepL
            in batched requests, and repeated strings are paid for once.

            Translation is IN PLACE. The normal way to build a second-language tree is
            entity_duplicate(table:"tl_page", id:…, with_children:true) to copy it, then
            this tool on the COPY with save:true. Running it on the original overwrites it.

            Parameters:
              - id:               the page to start at.
              - target_lang:      DeepL target code, e.g. EN-GB.
              - source_lang:      optional; omit to let DeepL detect it.
              - include_children: also every page below it (default true).
              - include_articles: also the articles on each page (default true).
              - include_content:  also the content elements in those articles, nested ones
                                  included (default true).
              - fields:           restrict the columns; omit for each table's defaults.
              - dry_run:          plan only — records, fields and the exact character count.
                                  Start here when you do not know what a tree will cost.
              - save:             false (default) returns the translations, capped at 50
                                  records; true writes them through page_update /
                                  article_update / content_update, each with a Versions
                                  snapshot, a tl_log entry and its own permission check.
              - max_characters:   refuses before spending anything if the plan exceeds this
                                  (default 100000). Pass 0 to disable.
              - max_records:      cap on the collected tree. Default AND hard ceiling is
                                  1000 — a higher value is silently reduced to it, so plan
                                  larger trees branch by branch rather than in one call.

            A record that cannot be written — no permission, validation error — is reported
            in the answer and the rest of the tree continues. `dropped_fields` per record
            lists columns that were filled but do not belong to that record's type.

            `dry_run: true` is also the fastest way to READ a tree: it returns, per page,
            every collected record with its id and its current field values in document
            order, and spends nothing. Mapping 86 headlines across 26 pages takes one call
            here instead of hundreds of content_list calls.

            Real sizes: one page was measured at ~46 records and ~7,000 characters, so the
            caps are roughly twenty pages of records and thirty-five of characters. A whole
            site does not fit in one call ON PURPOSE — work branch by branch
            (include_children=false, or start lower in the tree). A run long enough to time
            out leaves a partly translated tree with no report of where it stopped, which is
            far worse than a refusal.
            DESC,
    )]
    public function translatePageTree(
        int $id,
        string $target_lang,
        ?string $source_lang = null,
        bool $include_children = true,
        bool $include_articles = true,
        bool $include_content = true,
        #[Schema(type: 'array', items: ['type' => 'string'])] ?array $fields = null,
        bool $save = false,
        bool $dry_run = false,
        int $max_characters = self::DEFAULT_MAX_CHARACTERS,
        int $max_records = self::MAX_RECORDS,
    ): array {
        if (($err = $this->client->unavailableReason()) !== null) {
            return $err;
        }

        if ($id <= 0) {
            return ['error' => 'no_id', 'message' => 'Pass the id of the page to start at.'];
        }
        if (trim($target_lang) === '') {
            return ['error' => 'no_target_lang', 'message' => 'Pass target_lang, e.g. "EN-GB".'];
        }

        $root = $this->connection->fetchAssociative('SELECT id FROM tl_page WHERE id = ?', [$id]);
        if ($root === false) {
            return ['error' => 'not_found', 'message' => sprintf('No page with id %d.', $id)];
        }

        $limit = $max_records > 0 ? min($max_records, self::MAX_RECORDS) : self::MAX_RECORDS;
        $truncated = false;
        $targets = $this->collectTree($id, $include_children, $include_articles, $include_content, $limit, $truncated);

        $result = $this->run($targets, $target_lang, $source_lang, $fields, $save, $dry_run, $max_characters);
        $result = ['root_page' => $id] + $result;

        if ($truncated) {
            // Naming only the effective cap reads as if the parameter had been
            // misunderstood: pass max_records=4000 and the answer says "larger
            // than max_records (1000)". Say both numbers when they differ, so
            // it is clear the request was capped rather than ignored.
            $result['warnings'][] = sprintf(
                'The tree is larger than the effective cap of %d record(s)%s — only the first %d were processed, depth-first from the starting page. The rest is NOT translated. Continue branch by branch (include_children=false per page, or start at a lower page), rather than raising the cap until it fits: a run long enough to time out leaves a partly translated tree with no report of where it stopped.',
                $limit,
                $max_records > self::MAX_RECORDS
                    ? sprintf(' (you asked for %d; %d is the hard ceiling)', $max_records, self::MAX_RECORDS)
                    : '',
                \count($targets),
            );
        }

        return $result;
    }

    // ─────────────────────────────── internals ───────────────────────────────

    /**
     * Depth-first: a page, its articles, their content elements (nested
     * included), then the pages below it. The order matters only for
     * readability of the answer — the API call is batched across everything.
     *
     * @return list<array{table: string, id: int}>
     */
    private function collectTree(int $pageId, bool $children, bool $articles, bool $content, int $limit, bool &$truncated): array
    {
        $out = [];

        $walk = function (int $id) use (&$walk, &$out, $children, $articles, $content, $limit, &$truncated): void {
            if (\count($out) >= $limit) {
                $truncated = true;

                return;
            }

            $out[] = ['table' => 'tl_page', 'id' => $id];

            if ($articles) {
                $articleIds = $this->connection->fetchFirstColumn(
                    'SELECT id FROM tl_article WHERE pid = ? ORDER BY sorting, id',
                    [$id],
                );
                foreach ($articleIds as $articleId) {
                    if (\count($out) >= $limit) {
                        $truncated = true;

                        return;
                    }
                    $out[] = ['table' => 'tl_article', 'id' => (int) $articleId];

                    if ($content) {
                        foreach ($this->collectContent('tl_article', (int) $articleId) as $contentId) {
                            if (\count($out) >= $limit) {
                                $truncated = true;

                                return;
                            }
                            $out[] = ['table' => 'tl_content', 'id' => $contentId];
                        }
                    }
                }
            }

            if (!$children) {
                return;
            }

            $childIds = $this->connection->fetchFirstColumn(
                'SELECT id FROM tl_page WHERE pid = ? ORDER BY sorting, id',
                [$id],
            );
            foreach ($childIds as $childId) {
                $walk((int) $childId);
            }
        };

        $walk($pageId);

        return $out;
    }

    /**
     * Content elements of a parent, with the children of container elements
     * (accordion, element group, slider) folded in.
     *
     * @return list<int>
     */
    private function collectContent(string $ptable, int $pid): array
    {
        $ids = [];

        foreach ($this->connection->fetchFirstColumn(
            'SELECT id FROM tl_content WHERE ptable = ? AND pid = ? ORDER BY sorting, id',
            [$ptable, $pid],
        ) as $row) {
            $ids[] = (int) $row;
            foreach ($this->collectContent('tl_content', (int) $row) as $nested) {
                $ids[] = $nested;
            }
        }

        return $ids;
    }

    /**
     * The one pipeline behind both record tools: collect → plan → (translate) →
     * (save). Everything that can be decided without spending money is decided
     * before the first API call.
     *
     * @param list<array{table: string, id: int}> $targets
     * @param list<string>|null                   $fieldFilter
     *
     * @return array<string, mixed>
     */
    private function run(array $targets, string $targetLang, ?string $sourceLang, ?array $fieldFilter, bool $save, bool $dryRun, int $maxCharacters): array
    {
        $this->framework->initialize();

        $rows = $this->loadRows($targets);

        $plan = [];            // list of record entries
        $ignoredFields = [];
        $skipped = [];
        $plainTexts = [];      // flat slot buffers for the two batches
        $htmlTexts = [];

        foreach ($targets as $target) {
            $table = $target['table'];
            $id = $target['id'];
            $row = $rows[$table][$id] ?? null;

            if ($row === null) {
                $skipped[] = ['table' => $table, 'id' => $id, 'reason' => 'not_found'];
                continue;
            }

            $denial = $this->guard->ensureCan($table, $save ? 'update' : 'read', $id);
            if ($denial !== null) {
                $skipped[] = [
                    'table' => $table,
                    'id' => $id,
                    'reason' => (string) ($denial['error'] ?? 'permission_denied'),
                    'message' => (string) ($denial['message'] ?? ''),
                ];
                continue;
            }

            $resolved = TranslatableFields::resolve($table, $fieldFilter);
            foreach ($resolved['ignored'] as $name) {
                if (!\in_array($name, $ignoredFields, true)) {
                    $ignoredFields[] = $name;
                }
            }

            // tl_content, tl_module and tl_form_field decide PER RECORD which
            // of their columns exist for that row's type. Planning by table
            // alone offers columns the row cannot take, and the update then
            // refuses the WHOLE record — a headline element with a leftover
            // `text` value from an earlier type change lost its headline
            // translation as well. Narrowing here also stops us paying DeepL
            // for text that could never have been stored.
            $writable = $this->typePalette->writableFor($table, $row);
            $dropped = [];

            $entry = [
                'table' => $table,
                'id' => $id,
                'label' => $this->labelOf($table, $row),
                'fields' => [],
                'characters' => 0,
            ];

            foreach ($resolved['fields'] as $field) {
                $format = TranslatableFields::formatOf($table, $field);
                if ($format === null) {
                    continue;
                }

                if ($writable !== null && !\in_array($field, $writable, true)) {
                    // Only worth reporting when the column actually holds
                    // something — an empty one would not have been translated
                    // either, and listing it every time is noise.
                    $leftover = $this->valueOf($row, $field);
                    if ($leftover !== null && trim((string) $leftover) !== '') {
                        $dropped[] = $field;
                    }
                    continue;
                }

                $stored = $this->valueOf($row, $field);
                if ($stored === null) {
                    continue; // column not present on this instance
                }

                $slots = ValueCodec::extract($format, $stored);
                $nonEmpty = array_filter($slots, static fn (string $s) => trim($s) !== '');
                if ($nonEmpty === []) {
                    continue;
                }

                $isHtml = $format === TranslatableFields::FORMAT_HTML;
                $offsets = $isHtml
                    ? self::push($htmlTexts, $slots)
                    : self::push($plainTexts, $slots);

                $entry['fields'][$field] = [
                    'format' => $format,
                    'stored' => $stored,
                    'slots' => $slots,
                    'offsets' => $offsets,
                    'html' => $isHtml,
                ];
                $entry['characters'] += array_sum(array_map(mb_strlen(...), $nonEmpty));
            }

            if ($dropped !== []) {
                $entry['dropped_fields'] = $dropped;
            }

            if ($entry['fields'] === []) {
                $skipped[] = [
                    'table' => $table,
                    'id' => $id,
                    'reason' => $dropped === [] ? 'no_text' : 'no_field_of_this_type_holds_text',
                ] + ($dropped === [] ? [] : ['dropped_fields' => $dropped]);
                continue;
            }

            $plan[] = $entry;
        }

        // What will actually be submitted. Identical strings are paid once, but
        // only within their own batch: the same sentence sent once as markup and
        // once as plain text is two different API texts.
        $charactersPlanned = self::billableCharacters($plainTexts) + self::billableCharacters($htmlTexts);

        $tables = array_values(array_unique(array_column($targets, 'table')));

        $common = [
            'target_lang' => strtoupper(trim($targetLang)),
            'source_lang' => $sourceLang === null ? null : strtoupper(trim($sourceLang)),
            'records_in_scope' => \count($plan),
            // Upper bound: strings translated within the cache lifetime are
            // reused and cost nothing, so the run can come out cheaper. It is
            // never higher than this.
            'characters_planned' => $charactersPlanned,
        ];
        if (\count($tables) === 1) {
            $common = ['table' => $tables[0]] + $common;
        }
        if ($ignoredFields !== []) {
            $common['ignored_fields'] = $ignoredFields;
        }
        if ($skipped !== []) {
            $common['skipped'] = $skipped;
        }

        if ($plan === []) {
            return $common + [
                'error' => 'nothing_to_translate',
                'message' => 'None of the records carry text in the selected fields.',
            ];
        }

        if ($dryRun) {
            return $common + [
                'dry_run' => true,
                'mode' => $save ? 'would_save' : 'would_return_translations',
                'records' => array_map(
                    static fn (array $e) => [
                        'table' => $e['table'],
                        'id' => $e['id'],
                        'label' => $e['label'],
                        'fields' => array_keys($e['fields']),
                        'characters' => $e['characters'],
                    ] + (isset($e['dropped_fields']) ? ['dropped_fields' => $e['dropped_fields']] : []),
                    $plan,
                ),
            ];
        }

        if ($maxCharacters > 0 && $charactersPlanned > $maxCharacters) {
            return $common + [
                'error' => 'character_budget_exceeded',
                'message' => sprintf(
                    'The run would submit %d characters, above max_characters=%d. Nothing was translated. Work through the tree branch by branch (include_children=false, one page at a time), or raise max_characters (0 disables the check).',
                    $charactersPlanned,
                    $maxCharacters,
                ),
            ];
        }

        if (!$save && \count($plan) > self::MAX_PREVIEW_RECORDS) {
            return $common + [
                'error' => 'preview_too_large',
                'message' => sprintf(
                    'A preview returns every source and translation and is capped at %d records; this run covers %d. Either narrow the scope, or pass save=true to write them (use dry_run=true first to see the cost).',
                    self::MAX_PREVIEW_RECORDS,
                    \count($plan),
                ),
            ];
        }

        $baseline = $this->client->spend();

        try {
            $plainOut = $plainTexts === [] ? [] : $this->client->translate($plainTexts, $targetLang, $sourceLang, false);
            $htmlOut = $htmlTexts === [] ? [] : $this->client->translate($htmlTexts, $targetLang, $sourceLang, true);
        } catch (\Throwable $e) {
            return $common + $this->apiFailure($e);
        }

        $plain = $plainOut['translations'] ?? [];
        $html = $htmlOut['translations'] ?? [];
        $detected = $plainOut['detected_source_lang'] ?? $htmlOut['detected_source_lang'] ?? null;

        $records = [];
        $saved = 0;
        $unchanged = 0;
        $failed = 0;

        foreach ($plan as $entry) {
            $newValues = [];
            $preview = [];

            foreach ($entry['fields'] as $field => $spec) {
                $source = $spec['html'] ? $html : $plain;
                $translated = [];
                foreach ($spec['offsets'] as $offset) {
                    $translated[] = (string) ($source[$offset] ?? '');
                }

                $newValues[$field] = ValueCodec::rebuild($spec['format'], $spec['stored'], $translated);
                // Both sides shown the same way. The value handed to the update
                // tool keeps its structure (a headline needs its unit); the
                // preview shows the prose, so source and translation compare.
                $preview[$field] = [
                    'source' => ValueCodec::display($spec['format'], $spec['stored']),
                    'translation' => ValueCodec::display($spec['format'], $newValues[$field]),
                ];
            }

            $record = [
                'table' => $entry['table'],
                'id' => $entry['id'],
                'label' => $entry['label'],
                'characters' => $entry['characters'],
            ] + (isset($entry['dropped_fields']) ? ['dropped_fields' => $entry['dropped_fields']] : []);

            if (!$save) {
                $records[] = $record + ['fields' => $preview];
                continue;
            }

            $result = $this->saver->save($entry['table'], $entry['id'], $newValues);

            if (isset($result['error'])) {
                ++$failed;
                $records[] = $record + [
                    'saved' => false,
                    'error' => $result['error'],
                    'message' => $result['message'] ?? '',
                ];
                continue;
            }

            $changed = \is_array($result['changed_fields'] ?? null) ? $result['changed_fields'] : [];
            if ($changed === []) {
                ++$unchanged;
            } else {
                ++$saved;
            }

            $records[] = $record + ['saved' => $changed !== [], 'changed_fields' => $changed];
        }

        return $common + [
            'saved_to_database' => $save,
            'detected_source_lang' => $detected,
            'records' => $records,
            'totals' => [
                'records' => \count($records),
                'saved' => $saved,
                'unchanged' => $unchanged,
                'failed' => $failed,
                'skipped' => \count($skipped),
            ],
            'usage' => $this->client->spendSince($baseline),
        ];
    }

    /**
     * Appends the slots of one field to a batch buffer and reports where they
     * landed, so the translations can be put back in the right places.
     *
     * @param list<string> $buffer
     * @param list<string> $slots
     *
     * @return list<int>
     */
    private static function push(array &$buffer, array $slots): array
    {
        $offsets = [];
        foreach ($slots as $slot) {
            $offsets[] = \count($buffer);
            $buffer[] = $slot;
        }

        return $offsets;
    }

    /**
     * Characters DeepL will bill for one batch: non-empty, counted once each.
     *
     * @param list<string> $texts
     */
    private static function billableCharacters(array $texts): int
    {
        $unique = array_unique(array_filter($texts, static fn (string $s) => trim($s) !== ''));

        return array_sum(array_map(mb_strlen(...), $unique));
    }

    /**
     * One query per table instead of one per record.
     *
     * @param list<array{table: string, id: int}> $targets
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function loadRows(array $targets): array
    {
        $byTable = [];
        foreach ($targets as $target) {
            $byTable[$target['table']][] = $target['id'];
        }

        $rows = [];
        foreach ($byTable as $table => $ids) {
            // Table names come from the TranslatableFields registry, never from
            // caller input — the only reason they can be interpolated here.
            if (!TranslatableFields::knows($table)) {
                continue;
            }

            $result = $this->connection->fetchAllAssociative(
                sprintf('SELECT * FROM %s WHERE id IN (?)', $table),
                [array_values(array_unique($ids))],
                [ArrayParameterType::INTEGER],
            );

            foreach ($result as $row) {
                $rows[$table][(int) $row['id']] = $row;
            }
        }

        return $rows;
    }

    /**
     * Column lookup that tolerates the case MySQL hands back.
     *
     * @param array<string, mixed> $row
     */
    private function valueOf(array $row, string $field): mixed
    {
        if (\array_key_exists($field, $row)) {
            return $row[$field];
        }

        foreach ($row as $key => $value) {
            if (strcasecmp($key, $field) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function labelOf(string $table, array $row): string
    {
        foreach (self::LABEL_COLUMNS as $column) {
            $value = $this->valueOf($row, $column);
            if ($value === null || $value === '') {
                continue;
            }

            $format = TranslatableFields::formatOf($table, $column) ?? TranslatableFields::FORMAT_TEXT;
            $display = ValueCodec::display($format, $value);

            if (\is_string($display) && trim($display) !== '') {
                return mb_substr(strip_tags($display), 0, 120);
            }
        }

        return $table.'.'.(string) ($row['id'] ?? '?');
    }

    /**
     * DeepL's own exceptions carry the useful part (quota, bad language code,
     * auth) in the message — pass it through rather than flattening it.
     *
     * @return array{error: string, message: string, class: string}
     */
    private function apiFailure(\Throwable $e): array
    {
        return [
            'error' => 'deepl_request_failed',
            'message' => $e->getMessage(),
            'class' => $e::class,
        ];
    }
}
