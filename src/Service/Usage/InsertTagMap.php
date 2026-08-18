<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service\Usage;

/**
 * Which insert tags reference which table.
 *
 * Hand-maintained on purpose. Contao's insert-tag registry knows the tag
 * NAMES (`insert_tags_list` exposes them) but not what a tag's first argument
 * points at — `{{link::42}}` is a page, `{{insert_content::42}}` is a content
 * element, and nothing in the registry says so. Getting that wrong in either
 * direction is bad: a missing entry lets a real reference slip past the delete
 * guard, a wrong one blocks a harmless deletion.
 *
 * Verified against the `#[AsInsertTag]` declarations in contao/core-bundle,
 * news-bundle, calendar-bundle and faq-bundle (Contao 5.3–5.7).
 */
final class InsertTagMap
{
    /**
     * Table => tags whose FIRST argument is a record of that table.
     *
     * Tags whose argument is something else (`{{page::title}}` reads a field of
     * the *current* page, `{{link_close}}` takes no argument) are absent by
     * design.
     *
     * @var array<string, list<string>>
     */
    private const TAGS = [
        'tl_page' => ['link', 'link_open', 'link_url', 'link_title', 'link_name', 'link_target'],
        'tl_article' => ['insert_article', 'article', 'article_open', 'article_url', 'article_title', 'article_teaser'],
        'tl_content' => ['insert_content'],
        'tl_module' => ['insert_module'],
        'tl_form' => ['insert_form'],
        'tl_news' => ['news', 'news_open', 'news_url', 'news_title', 'news_teaser'],
        'tl_calendar_events' => ['event', 'event_open', 'event_url', 'event_title', 'event_teaser'],
        'tl_faq' => ['faq', 'faq_open', 'faq_url', 'faq_title'],
        'tl_files' => ['file', 'picture', 'image', 'figure'],
    ];

    /**
     * @return list<string>
     */
    public static function tagsFor(string $table): array
    {
        return self::TAGS[$table] ?? [];
    }

    /**
     * Matches `{{<tag>::<needle>}}` and `{{<tag>::<needle>|params}}`, and the
     * `{{<tag>::<needle>?query}}` form Contao allows for link tags.
     *
     * Anchored on the delimiter after the needle so that looking for id 4
     * cannot match `{{link::42}}`.
     *
     * Group 1 is the tag name — callers report which tag matched, so this
     * must stay a CAPTURING group.
     *
     * `$allowPathSuffix` additionally accepts a `/` after the needle, for
     * folder targets: `{{file::files/theme/logo.svg}}` IS a reference to the
     * folder `files/theme`, because deleting or renaming the folder takes that
     * path with it. Off by default — for anything else a trailing `/` would
     * mean a different record.
     *
     * @param list<string> $tags
     */
    public static function pattern(array $tags, string $needle, bool $allowPathSuffix = false): string
    {
        return '/\{\{\s*('.implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $tags))
            .')::'.preg_quote($needle, '/').'(?=['.($allowPathSuffix ? '\/' : '').'|?}\s])/i';
    }
}
