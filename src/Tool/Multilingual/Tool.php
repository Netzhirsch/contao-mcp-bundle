<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Multilingual;

use Contao\ArticleModel;
use Contao\CalendarEventsModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\FaqModel;
use Contao\Model;
use Contao\NewsModel;
use Contao\PageModel;
use Contao\Versions;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use PhpMcp\Server\Attributes\McpTool;
use Psr\Log\LoggerInterface;

/**
 * Generic multilingual linking for every Contao entity that the
 * `terminal42/contao-changelanguage` extension exposes a `languageMain`
 * column on. Five tables today:
 *
 *   tl_page, tl_news, tl_article, tl_calendar_events, tl_faq
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
 *   - Logs a single tl_log entry that describes the whole linking action
 *     instead of N noisy update entries
 */
final class Tool
{
    /**
     * Supported entity tables → Model FQCN. Keep this list narrow on
     * purpose — exposing arbitrary tables for languageMain writes would
     * silently corrupt rows whose DCAs don't have the column.
     *
     * @var array<string, class-string<Model>>
     */
    private const SUPPORTED = [
        'tl_page' => PageModel::class,
        'tl_news' => NewsModel::class,
        'tl_article' => ArticleModel::class,
        'tl_calendar_events' => CalendarEventsModel::class,
        'tl_faq' => FaqModel::class,
    ];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly McpPermissionGuard $guard,
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
            ships a `languageMain` column via terminal42/contao-changelanguage.
            Supported tables today:

              tl_page, tl_news, tl_article, tl_calendar_events, tl_faq

            Same parameters as `language_link_pages`:
              - table:        one of the five above
              - default_id:   row id all translations point AT (their `languageMain`)
              - translations: object {"de": 4, "fr": 7, "en": 9}. Language code
                              is informational — only tl_page has a `language`
                              column to validate against (warn-only mismatch).
              - reset_first:  optional (default false). Sets languageMain=0
                              before re-binding — useful after a tree restructure.

            Returns {linked: int, table, default_id, translations: {lang: id}, warnings: list<string>}.

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
                    'Table "%s" does not expose `languageMain`. Supported: %s.',
                    $table,
                    implode(', ', array_keys(self::SUPPORTED)),
                ),
                'supported_tables' => array_keys(self::SUPPORTED),
            ];
        }

        $modelClass = self::SUPPORTED[$table];

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
                $actualLang = (string) ($row->language ?? '');
                if ($actualLang !== '' && $actualLang !== $lang) {
                    $warnings[] = sprintf('Row %d has language="%s", expected "%s" — linked anyway.', $rowId, $actualLang, $lang);
                }
            }
            $resolved[$lang] = [$rowId, $row];
        }

        if ($resolved === []) {
            return [
                'linked' => 0,
                'table' => $table,
                'default_id' => $default_id,
                'translations' => [],
                'warnings' => $warnings,
                'message' => 'All translations were skipped (self-references).',
            ];
        }

        $linked = 0;
        foreach ($resolved as $lang => [$rowId, $row]) {
            if ($reset_first) {
                $row->languageMain = 0;
                $row->tstamp = time();
                $this->bootVersions($table, $rowId);
                $row->save();
                $row = $modelClass::findById($rowId);  // refresh
            }
            $row->languageMain = $default_id;
            $row->tstamp = time();
            $this->bootVersions($table, $rowId);
            $row->save();
            ++$linked;
        }

        $this->log(sprintf(
            'entity_language_link[%s]: linked %d row(s) to default_id=%d (%s)',
            $table,
            $linked,
            $default_id,
            implode(', ', array_map(static fn ($lang, $tuple) => sprintf('%s→%d', $lang, $tuple[0]), array_keys($resolved), array_values($resolved))),
        ), __METHOD__);

        return [
            'linked' => $linked,
            'table' => $table,
            'default_id' => $default_id,
            'translations' => array_map(static fn ($t) => $t[0], $resolved),
            'warnings' => $warnings,
        ];
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
