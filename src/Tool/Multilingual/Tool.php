<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Multilingual;

use Contao\ArticleModel;
use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\FaqCategoryModel;
use Contao\FaqModel;
use Contao\Model;
use Contao\NewsArchiveModel;
use Contao\NewsModel;
use Contao\PageModel;
use Contao\Versions;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\TranslationMaster;
use PhpMcp\Server\Attributes\McpTool;
use Psr\Log\LoggerInterface;

/**
 * Generic multilingual linking for every Contao entity that the
 * `terminal42/contao-changelanguage` extension gives a translation column.
 *
 * There are two such columns, and a working link needs BOTH:
 *
 *   - the record:     `languageMain` on tl_page, tl_news, tl_article,
 *                     tl_calendar_events, tl_faq
 *   - the collection: `master` on tl_news_archive, tl_calendar, tl_faq_category
 *
 * The second half is easy to forget because nothing looks broken without it —
 * the row carries the id it was given and the tool answers `linked: 1`. Only
 * the rendered page tells: the language switcher falls back to the language
 * root and the `hreflang` alternate is missing. So linking records now checks
 * the collection half, completes it where that is unambiguous and legal, and
 * says so plainly where it is not. See {@see TranslationMaster}.
 *
 * Sibling to {@see \Netzhirsch\ContaoMcpBundle\Tool\Page\Tool::languageLinkPages}
 * which is a Page-specific convenience. This tool covers the rest with the
 * same API shape so Skill 2 / Builder code can stay symmetric across
 * entities.
 *
 * Why not just `<entity>_update(id, fields: {languageMain: X})`?
 *
 *   - Saves N-1 round-trips when linking N translations
 *   - Validates all referenced rows EXIST before any write (no half-link)
 *   - Keeps the collection half in step, which no per-record update does
 *   - Logs a single tl_log entry that describes the whole linking action
 *     instead of N noisy update entries
 */
final class Tool
{
    /**
     * Supported tables → [Model FQCN, column written]. Keep this list narrow on
     * purpose — exposing arbitrary tables would silently corrupt rows whose
     * DCAs don't have the column.
     *
     * @var array<string, array{0: class-string<Model>, 1: string}>
     */
    private const SUPPORTED = [
        'tl_page' => [PageModel::class, 'languageMain'],
        'tl_news' => [NewsModel::class, 'languageMain'],
        'tl_article' => [ArticleModel::class, 'languageMain'],
        'tl_calendar_events' => [CalendarEventsModel::class, 'languageMain'],
        'tl_faq' => [FaqModel::class, 'languageMain'],
        'tl_news_archive' => [NewsArchiveModel::class, 'master'],
        'tl_calendar' => [CalendarModel::class, 'master'],
        'tl_faq_category' => [FaqCategoryModel::class, 'master'],
    ];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly McpPermissionGuard $guard,
        private readonly TranslationMaster $translationMaster,
    ) {
    }

    /**
     * @param object|array<string, int> $translations Mapping {language_code: row_id},
     *                                                e.g. {"de": 4, "fr": 7, "en": 9}.
     *                                                Language codes are informational
     *                                                only — they are not enforced
     *                                                against any column (only tl_page
     *                                                has a `language` column; for the
     *                                                others the language is implicit
     *                                                via the parent page).
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'entity_language_link',
        description: <<<'DESC'
            Generic version of `language_link_pages` for every entity that
            terminal42/contao-changelanguage gives a translation column.

            Records (column `languageMain`):
              tl_page, tl_news, tl_article, tl_calendar_events, tl_faq
            Collections (column `master`):
              tl_news_archive, tl_calendar, tl_faq_category

            A news/event/faq translation needs BOTH halves. Without the
            collection half the record column is never evaluated: the language
            switcher falls back to the language root and no hreflang alternate
            is emitted, while the database looks correct. Linking records here
            therefore completes the collection half where that is unambiguous
            and legal, and reports it under `collections_linked`; where it is
            not, `warnings` names what is missing and the call that fixes it.

            Parameters (same as `language_link_pages`):
              - table:        one of the tables above
              - default_id:   row id all translations point AT (the master /
                              default-language row)
              - translations: object {"de": 4, "fr": 7, "en": 9}. Language code
                              is informational — only tl_page has a `language`
                              column to validate against (warn-only mismatch).
              - reset_first:  optional (default false). Clears the column
                              before re-binding — useful after a restructure.

            Returns {linked, table, column, default_id, translations: {lang: id},
            collections_linked: list, warnings: list<string>}.

            Root pages are rejected for tl_page: they use `languageRoot`, not
            `languageMain` — set it with page_update.

            For tl_page specifically, `language_link_pages` exists as a
            convenience alias (same behaviour, narrower table-scope).
        DESC,
    )]
    public function entityLanguageLink(
        string $table,
        int $default_id,
        mixed $translations,
        bool $reset_first = false,
    ): array {
        $this->framework->initialize();

        if (!isset(self::SUPPORTED[$table])) {
            return [
                'error' => 'unsupported_table',
                'message' => sprintf(
                    'Table "%s" has no changelanguage translation column. Supported: %s.',
                    $table,
                    implode(', ', array_keys(self::SUPPORTED)),
                ),
                'supported_tables' => array_keys(self::SUPPORTED),
            ];
        }

        [$modelClass, $column] = self::SUPPORTED[$table];

        // Both columns come from the extension. Without it the write would be
        // filtered out by the model and the tool would still answer `linked: N`
        // — the failure shape this whole tool is being hardened against.
        if (!$this->translationMaster->hasColumn($table, $column)) {
            return [
                'error' => 'extension_not_available',
                'message' => sprintf(
                    '%s has no `%s` column — terminal42/contao-changelanguage is not installed here. '
                    .'Check with installed_bundles.',
                    $table,
                    $column,
                ),
            ];
        }

        // Normalise translations input (stdClass / assoc-array / reject lists).
        $map = match (true) {
            \is_object($translations) => get_object_vars($translations),
            \is_array($translations) && !array_is_list($translations) => $translations,
            default => null,
        };
        if ($map === null || $map === []) {
            return ['error' => 'invalid_input', 'message' => 'translations must be a non-empty JSON object {lang: row_id}.'];
        }

        $defaultRow = $modelClass::findById($default_id);
        if ($defaultRow === null) {
            return ['error' => 'not_found', 'message' => sprintf('Default row %d not found in %s.', $default_id, $table)];
        }

        // Backend parity: only link entities the caller could edit in the
        // backend. mayAccessRecord() applies the right scoping per table —
        // pagemounts for tl_page/tl_article, the ReadAction voter for the
        // archive/calendar/channel-scoped tables.
        if (!$this->guard->mayAccessRecord($table, $defaultRow->row())) {
            return ['error' => 'permission_denied', 'message' => sprintf('You are not allowed to access default row %d in %s.', $default_id, $table)];
        }

        // Validate every translation up-front (all-or-skip semantics).
        $resolved = [];
        $warnings = [];
        $checkLanguageColumn = ($table === 'tl_page');

        foreach ($map as $lang => $rowId) {
            if (!\is_string($lang) || trim($lang) === '') {
                return ['error' => 'invalid_input', 'message' => 'translations keys must be non-empty language strings.'];
            }
            if (!\is_int($rowId) && !(\is_string($rowId) && ctype_digit($rowId))) {
                return ['error' => 'invalid_input', 'message' => sprintf('translations["%s"] must be an integer row id.', $lang)];
            }
            $rowId = (int) $rowId;
            if ($rowId === $default_id) {
                $warnings[] = sprintf('Skipped "%s" → %d: cannot link a row to itself.', $lang, $rowId);
                continue;
            }
            $row = $modelClass::findById($rowId);
            if ($row === null) {
                return ['error' => 'not_found', 'message' => sprintf('Translation row %d (language "%s") not found in %s.', $rowId, $lang, $table)];
            }
            if (!$this->guard->mayAccessRecord($table, $row->row())) {
                return ['error' => 'permission_denied', 'message' => sprintf('You are not allowed to access translation row %d (language "%s") in %s.', $rowId, $lang, $table)];
            }
            if ($checkLanguageColumn) {
                // A root page's translation link lives in `languageRoot`, not
                // `languageMain` — writing the latter would leave the tree
                // looking linked while changelanguage ignores it.
                if ((string) ($row->type ?? '') === 'root') {
                    return [
                        'error' => 'wrong_column',
                        'message' => sprintf(
                            'Page %d is a root page. Root pages link through `languageRoot`, not `languageMain`. '
                            .'Use page_update(id: %d, fields: {languageRoot: %d}).',
                            $rowId,
                            $rowId,
                            $default_id,
                        ),
                    ];
                }
                $actualLang = (string) ($row->language ?? '');
                if ($actualLang !== '' && $actualLang !== $lang) {
                    $warnings[] = sprintf('Row %d has language="%s", expected "%s" — linked anyway.', $rowId, $actualLang, $lang);
                }
            }
            $resolved[$lang] = [$rowId, $row];
        }

        // Linking collections directly: changelanguage's own rules (the target
        // must be a master itself, one translation per master and reader page)
        // are checked here rather than left to the backend save_callback that
        // a write through the model never reaches.
        if (self::SUPPORTED[$table][1] === 'master') {
            foreach ($resolved as $lang => [$rowId]) {
                $blocker = $this->translationMaster->blocker($table, $rowId, $default_id);
                if ($blocker !== null) {
                    return ['error' => 'invalid_link', 'message' => sprintf('translations["%s"] → %d: %s', $lang, $rowId, $blocker)];
                }
            }
        }

        if ($resolved === []) {
            return [
                'linked' => 0,
                'table' => $table,
                'column' => $column,
                'default_id' => $default_id,
                'translations' => [],
                'collections_linked' => [],
                'warnings' => $warnings,
                'message' => 'All translations were skipped (self-references).',
            ];
        }

        $linked = 0;
        foreach ($resolved as $lang => [$rowId, $row]) {
            if ($reset_first) {
                $row->$column = 0;
                $row->tstamp = time();
                $this->bootVersions($table, $rowId);
                $row->save();
                $row = $modelClass::findById($rowId);  // refresh
            }
            $row->$column = $default_id;
            $row->tstamp = time();
            $this->bootVersions($table, $rowId);
            $row->save();
            ++$linked;
        }

        $collectionsLinked = $this->completeCollectionHalf($table, $resolved, $default_id, $warnings);

        $this->log(sprintf(
            'entity_language_link[%s]: set %s=%d on %d row(s) (%s)%s',
            $table,
            $column,
            $default_id,
            $linked,
            implode(', ', array_map(static fn ($lang, $tuple) => sprintf('%s→%d', $lang, $tuple[0]), array_keys($resolved), array_values($resolved))),
            $collectionsLinked === []
                ? ''
                : sprintf(
                    '; completed the collection half: %s',
                    implode(', ', array_map(static fn (array $c) => sprintf('%s.%d→master=%d', $c['table'], $c['id'], $c['master']), $collectionsLinked)),
                ),
        ), __METHOD__);

        return [
            'linked' => $linked,
            'table' => $table,
            'column' => $column,
            'default_id' => $default_id,
            'translations' => array_map(static fn ($t) => $t[0], $resolved),
            'collections_linked' => $collectionsLinked,
            'warnings' => $warnings,
        ];
    }

    /**
     * Bring the collection half of a record link in step.
     *
     * The pair is not a guess: the translated collection is the linked record's
     * `pid`, the master collection is the default record's. So where the
     * translated collection is still unclaimed and changelanguage's rules allow
     * it, the link is completed here; where they don't, the caller is told what
     * is missing instead of being handed a `linked: 1` that does nothing.
     *
     * @param array<string, array{0: int, 1: Model}> $resolved
     * @param list<string>                           $warnings
     *
     * @return list<array{table: string, id: int, master: int}>
     */
    private function completeCollectionHalf(string $recordTable, array $resolved, int $default_id, array &$warnings): array
    {
        if (TranslationMaster::collectionFor($recordTable) === null) {
            return [];
        }

        $linked = [];
        $seen = [];

        foreach ($resolved as [$rowId]) {
            $state = $this->translationMaster->inspect($recordTable, $rowId, $default_id);
            if ($state === null) {
                continue;
            }

            $collectionTable = (string) $state['collection_table'];
            $translated = (int) $state['translated'];
            $master = (int) $state['master'];

            if (isset($seen[$translated])) {
                continue; // several records share one collection
            }
            $seen[$translated] = true;

            if ((int) $state['current'] === $master) {
                continue; // already in step
            }

            if ((int) $state['current'] !== 0 || $state['blocker'] !== null) {
                $warnings[] = TranslationMaster::unlinkedWarning($recordTable, $rowId, $state)
                    .($state['blocker'] !== null ? ' '.$state['blocker'] : '');

                continue;
            }

            /** @var class-string<Model> $collectionClass */
            $collectionClass = Model::getClassFromTable($collectionTable);
            $collection = $collectionClass::findById($translated);
            if ($collection === null || !$this->guard->mayAccessRecord($collectionTable, $collection->row())) {
                $warnings[] = sprintf(
                    'Could not complete the collection half: %s.%d is not accessible. %s',
                    $collectionTable,
                    $translated,
                    TranslationMaster::unlinkedWarning($recordTable, $rowId, $state),
                );

                continue;
            }

            $collection->master = $master;
            $collection->tstamp = time();
            $this->bootVersions($collectionTable, $translated);
            $collection->save();

            $linked[] = ['table' => $collectionTable, 'id' => $translated, 'master' => $master];
        }

        return $linked;
    }

    private function bootVersions(string $table, int $id): Versions
    {
        $versions = new Versions($table, $id);
        $versions->setUsername($this->authorResolver->getLogUsername());
        $versions->setUserId($this->authorResolver->resolve());
        $versions->initialize();

        return $versions;
    }

    private function log(string $message, string $caller): void
    {
        $this->logger->info($message, [
            'contao' => new ContaoContext(
                $caller,
                ContaoContext::GENERAL,
                $this->authorResolver->getLogUsername(),
                null,
                null,
                $this->authorResolver->getLogSource(),
            ),
        ]);
    }
}
