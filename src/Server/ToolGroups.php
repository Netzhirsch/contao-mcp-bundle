<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Server;

/**
 * Single source of truth for the tool-name → group taxonomy. Used by the
 * Discovery tools (contao_search_tools group facets), the Backend tool panel
 * (grouped enable/disable toggles) and anything else that needs to bucket the
 * catalogue without a side-channel registry.
 *
 * Extracted from Discovery\Tool::groupOf() so the Backend module does not have
 * to instantiate a tool class just to label its panel rows.
 */
final class ToolGroups
{
    /**
     * Derives the group name from the tool name. Convention:
     *   - news_*           → news
     *   - news_archive_*   → news_archive (special case: two-word prefix)
     *   - url_rewrite_*    → url_rewrite
     *   - contao_*         → discovery
     *   - everything else: first underscore segment (plurals singularised)
     */
    public static function groupOf(string $toolName): string
    {
        $name = mb_strtolower($toolName);

        // Hardcoded multi-word prefixes (sort longest first).
        static $prefixes = [
            'news_archive_', 'news_archives_',
            'calendar_events_', 'calendar_event_',
            'faq_categories_', 'faq_category_',
            'image_size_items_', 'image_size_item_',
            'image_sizes_', 'image_size_',
            'url_rewrites_', 'url_rewrite_',
            'user_groups_',
            'member_groups_',
            'content_types_', 'content_palette_',
            'module_types_', 'module_palette_',
            'template_overrides_',
            'pages_tree',
            'folder_',
            'html_filter_',
        ];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return rtrim($prefix, '_');
            }
        }

        if (str_starts_with($name, 'contao_')) {
            return 'discovery';
        }
        if ($name === 'ping' || $name === 'system_settings' || $name === 'installed_bundles') {
            return 'system';
        }

        // Plurals → singular for grouping consistency.
        $firstSegment = explode('_', $name)[0] ?? $name;

        return match ($firstSegment) {
            'themes' => 'theme',
            'layouts' => 'layout',
            'modules' => 'module',
            'pages' => 'page',
            'articles' => 'article',
            'news' => 'news',
            'calendars' => 'calendar',
            'faqs' => 'faq',
            'templates' => 'template',
            'files' => 'file',
            'users' => 'user',
            'members' => 'member',
            // terminal42/contao-leads: plural list tool → singular group, so
            // leads_list and lead_get land in the same bucket.
            'leads' => 'lead',
            default => $firstSegment,
        };
    }
}
