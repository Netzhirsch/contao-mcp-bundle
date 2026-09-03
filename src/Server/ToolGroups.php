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

        // Multi-word prefixes → group (sort longest first). The group is the
        // prefix without its trailing underscore unless stated otherwise; the
        // exceptions are vendor-namespaced tools, where the vendor segment
        // carries no meaning for a panel heading.
        static $prefixes = [
            'news_archive_' => null, 'news_archives_' => null,
            'calendar_events_' => null, 'calendar_event_' => null,
            'faq_categories_' => null, 'faq_category_' => null,
            'image_size_items_' => null, 'image_size_item_' => null,
            'image_sizes_' => null, 'image_size_' => null,
            'url_rewrites_' => null, 'url_rewrite_' => null,
            'user_groups_' => null,
            'member_groups_' => null,
            'content_types_' => null, 'content_palette_' => null,
            'module_types_' => null, 'module_palette_' => null,
            'template_overrides_' => null,
            'pages_tree' => null,
            'folder_' => null,
            'html_filter_' => null,
            // Vendor-namespaced extension tools. The house convention wants the
            // vendor prefix on the tool name; a panel box called
            // "netzhirsch_pagestate" would be the price, and it is not one
            // worth paying — so the group drops the vendor segment and the
            // heading stays what operators recognise.
            'netzhirsch_pagestate_' => 'pagestate',
        ];

        foreach ($prefixes as $prefix => $group) {
            if (str_starts_with($name, $prefix)) {
                return $group ?? rtrim($prefix, '_');
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
