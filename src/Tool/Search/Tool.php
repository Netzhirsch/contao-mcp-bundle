<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Search;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Search;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use PhpMcp\Server\Attributes\McpTool;

/**
 * Full-text search over Contao's own search index (`tl_search`).
 *
 * Lets the LLM FIND content instead of paging through listings: "which page
 * mentions the cancellation policy?" is one call here, versus walking
 * pages_list → article → content for every candidate.
 *
 * Read-only, and it searches the *rendered frontend* text, so it also finds
 * text that lives in modules, includes or extensions — things the CRUD tools
 * cannot see. The flip side: it only knows what the crawler has indexed
 * (`contao:crawl`), which is why {@see status()} exists.
 *
 * Protected pages are never returned. Their visibility depends on FRONTEND
 * member groups, which say nothing about what the calling backend user may
 * see — so the safe answer is to leave them out and report how many were
 * suppressed.
 */
final class Tool
{
    /** Characters of context kept around a match in the snippet. */
    private const SNIPPET_CONTEXT = 90;

    /** Hard cap on results per call, so a broad query can't flood the context. */
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param list<int>|null $page_ids
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'search_query',
        description: <<<'DESC'
            Full-text search over Contao's search index (tl_search) — the same index the
            frontend search module uses.

            Use this to LOCATE content by wording instead of listing entities: "which page
            mentions our cancellation policy", "where do we still write 'Preisliste 2024'".
            It searches the RENDERED page text, so it also finds content coming from modules,
            includes or extensions, which the CRUD tools cannot see.

            Parameters: `keywords` (space-separated; use "quoted phrases" for exact phrases,
            +required/-excluded, * as wildcard), `or_search=true` to match ANY word instead of
            all, `fuzzy=true` for partial-word matching, `page_ids` to restrict to certain
            pages, `limit`/`offset` for paging (limit is capped at 50).

            Returns {total, returned, offset, protected_skipped, results:[{title, url, page_id,
            language, relevance, snippet}]}. `relevance` is Contao's own score, comparable only
            within one result set. `snippet` shows the text around the first match.

            Protected pages are ALWAYS excluded (their access depends on frontend member
            groups) — `protected_skipped` tells you how many were dropped. If results are
            empty, check `search_index_status`: an index that was never crawled is empty.
        DESC,
    )]
    public function query(
        string $keywords,
        bool $or_search = false,
        bool $fuzzy = false,
        ?array $page_ids = null,
        int $limit = 20,
        int $offset = 0,
        int $min_keyword_length = 0,
    ): array {
        $this->framework->initialize();

        $keywords = trim($keywords);
        if ('' === $keywords) {
            return ['error' => 'invalid_input', 'message' => 'Provide at least one keyword.'];
        }

        $limit = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);
        $pages = array_values(array_filter(array_map('intval', $page_ids ?? [])));

        try {
            $result = Search::query($keywords, $or_search, $pages, $fuzzy, max(0, $min_keyword_length));
        } catch (\Throwable $e) {
            // An unparsable query (stray quotes, only stopwords, …) must not
            // surface as a protocol error — report it like any tool error.
            return ['error' => 'search_failed', 'message' => $e->getMessage()];
        }

        // Drop protected hits and count them, so the caller knows the result is
        // incomplete rather than silently missing pages.
        $skipped = 0;
        $result->applyFilter(static function (array $row) use (&$skipped): bool {
            if (empty($row['protected'])) {
                return true;
            }
            ++$skipped;

            return false;
        });

        $rows = $result->getResults($limit, $offset);

        return [
            'total' => $result->getCount(),
            'returned' => \count($rows),
            'offset' => $offset,
            'limit' => $limit,
            'protected_skipped' => $skipped,
            'results' => array_map(fn (array $row): array => $this->serialize($row), $rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'search_index_status',
        description: <<<'DESC'
            Reports the state of Contao's search index (tl_search): how many documents are
            indexed, how they split by language, how many are protected, and when the index was
            last written.

            Call this when `search_query` returns nothing: the index is populated by the
            crawler, so a site that has never run `contao:crawl` (or has search indexing
            disabled in the root page settings) simply has an empty index — the content is
            fine, it was just never indexed.

            Returns {documents, protected, languages:[{language, documents}], terms,
            last_indexed, hint}.
        DESC,
    )]
    public function status(): array
    {
        $this->framework->initialize();

        $documents = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_search');
        $protected = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_search WHERE protected = 1');
        $lastIndexed = (int) $this->connection->fetchOne('SELECT MAX(tstamp) FROM tl_search');

        /** @var list<array{language: string, documents: int}> $languages */
        $languages = array_map(
            static fn (array $r): array => ['language' => (string) $r['language'], 'documents' => (int) $r['documents']],
            $this->connection->fetchAllAssociative(
                'SELECT language, COUNT(*) AS documents FROM tl_search GROUP BY language ORDER BY documents DESC',
            ),
        );

        // The term table only exists once something was indexed; treat a missing
        // table as "no terms" instead of failing the whole status call.
        try {
            $terms = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_search_term');
        } catch (\Throwable) {
            $terms = 0;
        }

        return [
            'documents' => $documents,
            'protected' => $protected,
            'languages' => $languages,
            'terms' => $terms,
            'last_indexed' => $lastIndexed > 0 ? date('c', $lastIndexed) : null,
            'hint' => $documents > 0
                ? 'Index is populated. It is refreshed by the crawler (contao:crawl) and on page updates.'
                : 'Index is EMPTY — run `vendor/bin/contao-console contao:crawl` and make sure search indexing is enabled in the root page settings.',
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        return [
            'title' => StringUtil::stripInsertTags((string) ($row['title'] ?? '')),
            'url' => (string) ($row['url'] ?? ''),
            'page_id' => (int) ($row['pid'] ?? 0),
            'language' => (string) ($row['language'] ?? ''),
            'relevance' => round((float) ($row['relevance'] ?? 0), 4),
            'snippet' => $this->snippet((string) ($row['text'] ?? ''), (string) ($row['matches'] ?? '')),
        ];
    }

    /**
     * Text around the first matching word. The indexed text starts with the
     * meta description line, so a naive "first N characters" would return the
     * same boilerplate for every hit — showing the match in context is the
     * whole point.
     */
    private function snippet(string $text, string $matches): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', StringUtil::stripInsertTags($text)) ?? '');
        if ('' === $text) {
            return '';
        }

        $position = false;
        foreach (StringUtil::trimsplit(',', $matches) as $match) {
            if ('' !== $match && false !== ($found = mb_stripos($text, $match))) {
                $position = $found;
                break;
            }
        }

        if (false === $position) {
            return mb_substr($text, 0, self::SNIPPET_CONTEXT * 2).(mb_strlen($text) > self::SNIPPET_CONTEXT * 2 ? '…' : '');
        }

        $start = max(0, $position - self::SNIPPET_CONTEXT);
        $snippet = mb_substr($text, $start, self::SNIPPET_CONTEXT * 2);

        return ($start > 0 ? '…' : '').trim($snippet).($start + self::SNIPPET_CONTEXT * 2 < mb_strlen($text) ? '…' : '');
    }
}
