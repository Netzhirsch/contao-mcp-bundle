<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Server;

use PhpMcp\Server\Registry;

/**
 * Per-tool enable/disable for the MCP catalogue.
 *
 * Three responsibilities, all around the `disabled_tools` config list:
 *
 *  1. {@see prune()} — remove operator-disabled tools from a php-mcp Registry.
 *     Called by {@see HttpDispatcherFactory::getDispatcher()} on the
 *     MCP-SERVING path only: a pruned tool is gone from `tools/list`,
 *     `tools/call` ("tool not found"), `contao_search_tools`,
 *     `contao_describe_tool` AND `contao_call` — one mechanism, no per-layer
 *     checks. The Backend module uses the UNPRUNED registry (via
 *     `getServer()`) so its panel can still render disabled tools with their
 *     descriptions.
 *
 *  2. {@see catalogue()} — the Backend panel's data: every known tool
 *     (core registry + extension candidates that are not even registered yet)
 *     grouped by {@see ToolGroups} taxonomy, with enabled/source/protected
 *     flags.
 *
 *  3. {@see mergeSelection()} — translate a submitted checkbox selection back
 *     into the two config lists. Core tools use OPT-OUT semantics
 *     (`disabled_tools`), extension tools keep their OPT-IN security gate
 *     (`extension_tools_enabled`). Names that were not rendered in the
 *     submitting form (temporarily uninstalled bundle, missing extension)
 *     keep their previous state instead of being silently wiped.
 *
 * The Registry keeps its tool map private and offers no removal API, so
 * prune() reflects into it — the same pragmatic pattern HttpDispatcherFactory
 * already uses for the Dispatcher's schemaValidator swap.
 */
final class ToolCatalog
{
    /**
     * Tools that can never be disabled. The three discovery tools are the
     * only path to everything else under Lazy-Mode (disabling contao_call
     * would brick the whole catalogue with one click), and `ping` is the
     * connection health probe both the docs and monitoring rely on.
     *
     * @var list<string>
     */
    public const PROTECTED_TOOLS = [
        'contao_search_tools',
        'contao_describe_tool',
        'contao_call',
        'ping',
    ];

    /**
     * Remove disabled tools from the registry. Protected tools are skipped
     * even if someone hand-edits them into config.json.
     *
     * @param list<string> $disabledNames
     *
     * @return list<string> the names actually removed
     */
    public function prune(Registry $registry, array $disabledNames): array
    {
        if ($disabledNames === []) {
            return [];
        }

        $disabled = array_diff($disabledNames, self::PROTECTED_TOOLS);
        if ($disabled === []) {
            return [];
        }

        $prop = (new \ReflectionClass(Registry::class))->getProperty('tools');
        $prop->setAccessible(true);
        /** @var array<string, object> $tools */
        $tools = $prop->getValue($registry);

        $removed = [];
        foreach ($disabled as $name) {
            if (isset($tools[$name])) {
                unset($tools[$name]);
                $removed[] = $name;
            }
        }

        if ($removed !== []) {
            $prop->setValue($registry, $tools);
        }

        return $removed;
    }

    /**
     * Builds the Backend panel data. Groups are sorted alphabetically with
     * `discovery` and `system` first (they carry the protected tools), tools
     * alphabetically within their group.
     *
     * @param array<string, string> $registryTools name => description, from the
     *        UNPRUNED registry (core + already-enabled extension tools)
     * @param list<array{name: string, description: string, class: string}> $extensionCandidates
     *        ALL extension tools offered by installed bundles (enabled or not)
     * @param list<string> $disabledTools  config: disabled_tools
     * @param list<string> $enabledExtensions config: extension_tools_enabled
     *
     * @return list<array{
     *     group: string,
     *     tools: list<array{name: string, description: string, enabled: bool, source: string, protected: bool}>,
     *     enabled_count: int,
     *     total: int
     * }>
     */
    public function catalogue(
        array $registryTools,
        array $extensionCandidates,
        array $disabledTools,
        array $enabledExtensions,
    ): array {
        $extensionNames = [];
        foreach ($extensionCandidates as $candidate) {
            $extensionNames[$candidate['name']] = $candidate['description'];
        }

        $rows = [];

        foreach ($registryTools as $name => $description) {
            $isExtension = isset($extensionNames[$name]);
            $isProtected = \in_array($name, self::PROTECTED_TOOLS, true);
            $rows[$name] = [
                'name' => $name,
                'description' => self::tidyDescription($description),
                // Protected tools are always on — prune() ignores them even
                // when a hand-edited config lists them, so the panel must not
                // show them as disabled either.
                'enabled' => $isProtected
                    || ($isExtension
                        ? \in_array($name, $enabledExtensions, true)
                        : !\in_array($name, $disabledTools, true)),
                'source' => $isExtension ? 'extension' : 'core',
                'protected' => $isProtected,
            ];
        }

        // Extension tools that are NOT in the registry (not enabled yet, or
        // name-collision losers — the latter stay visible so the operator can
        // see them; enabling has no effect until the collision is resolved).
        foreach ($extensionNames as $name => $description) {
            if (isset($rows[$name])) {
                continue;
            }
            $rows[$name] = [
                'name' => $name,
                'description' => self::tidyDescription($description),
                'enabled' => \in_array($name, $enabledExtensions, true),
                'source' => 'extension',
                'protected' => false,
            ];
        }

        $groups = [];
        foreach ($rows as $row) {
            $groups[self::panelGroupOf($row['name'])][] = $row;
        }

        uksort($groups, static function (string $a, string $b): int {
            $rank = static fn (string $g): int => match ($g) {
                'discovery' => 0,
                'system' => 1,
                default => 2,
            };

            return [$rank($a), $a] <=> [$rank($b), $b];
        });

        $out = [];
        foreach ($groups as $group => $tools) {
            usort($tools, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);
            $out[] = [
                'group' => $group,
                'tools' => $tools,
                'enabled_count' => \count(array_filter($tools, static fn (array $t): bool => $t['enabled'])),
                'total' => \count($tools),
            ];
        }

        return $out;
    }

    /**
     * Translates a checkbox submission into the next config state.
     *
     * @param list<string> $renderedCore      core tool names the form showed
     * @param list<string> $renderedExtension extension tool names the form showed
     * @param list<string> $checked           names submitted as enabled
     * @param list<string> $previousDisabled  config: disabled_tools before save
     * @param list<string> $previousEnabledExtensions config: extension_tools_enabled before save
     *
     * @return array{disabled_tools: list<string>, extension_tools_enabled: list<string>}
     */
    public static function mergeSelection(
        array $renderedCore,
        array $renderedExtension,
        array $checked,
        array $previousDisabled,
        array $previousEnabledExtensions,
    ): array {
        $checkedSet = array_fill_keys($checked, true);

        // Core: opt-out. Start from entries the form did NOT render (keep
        // their state), then add every rendered-but-unchecked core tool.
        $disabled = array_values(array_diff($previousDisabled, $renderedCore));
        foreach ($renderedCore as $name) {
            if (!isset($checkedSet[$name]) && !\in_array($name, ToolCatalog::PROTECTED_TOOLS, true)) {
                $disabled[] = $name;
            }
        }

        // Extension: opt-in. Same keep-unrendered rule, then add every
        // rendered-and-checked extension tool.
        $enabledExtensions = array_values(array_diff($previousEnabledExtensions, $renderedExtension));
        foreach ($renderedExtension as $name) {
            if (isset($checkedSet[$name])) {
                $enabledExtensions[] = $name;
            }
        }

        sort($disabled);
        sort($enabledExtensions);

        return [
            'disabled_tools' => $disabled,
            'extension_tools_enabled' => $enabledExtensions,
        ];
    }

    /**
     * Panel-friendly grouping on top of {@see ToolGroups::groupOf()}.
     *
     * The search taxonomy intentionally mirrors name prefixes 1:1, which
     * splits plural list tools from their singular CRUD siblings
     * (`news_archives` vs `news_archive`) and gives one-tool helper groups
     * (`content_palette`, `dbafs`, …). Fine for search facets, noisy as UI
     * boxes — so the panel folds those into their parent entity. The
     * changelanguage helpers get their own `multilingual` box and
     * terminal42's url_rewrite keeps its own, matching how operators think
     * about the two third-party integrations.
     */
    private static function panelGroupOf(string $name): string
    {
        // Tool-specific assignments that no prefix rule can derive.
        $byName = [
            'entity_language_link' => 'multilingual',
            'language_link_pages' => 'multilingual',
            'page_translations_tree' => 'multilingual',
        ];
        if (isset($byName[$name])) {
            return $byName[$name];
        }

        $byGroup = [
            // Plural list groups → their singular CRUD group.
            'news_archives' => 'news_archive',
            'calendar_events' => 'calendar_event',
            'faq_categories' => 'faq_category',
            'image_sizes' => 'image_size',
            'image_size_items' => 'image_size_item',
            'url_rewrites' => 'url_rewrite',
            'comments' => 'comment',
            'forms' => 'form',
            'newsletters' => 'newsletter',
            // Helper groups → parent entity.
            'content_types' => 'content',
            'content_palette' => 'content',
            'module_types' => 'module',
            'module_palette' => 'module',
            'template_overrides' => 'template',
            'pages_tree' => 'page',
            'folder' => 'file',
            'member_groups' => 'member',
            'user_groups' => 'user',
            'external' => 'external_id',
            'dbafs' => 'maintenance',
            'insert' => 'system',
            'html_filter' => 'system',
            'server' => 'system',
            'language' => 'multilingual',
        ];

        $group = ToolGroups::groupOf($name);

        return $byGroup[$group] ?? $group;
    }

    /**
     * Tool descriptions are heredocs with deep indentation; collapse the
     * whitespace and cut at a sane tooltip length.
     */
    private static function tidyDescription(string $description): string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', $description));
        if (mb_strlen($clean) <= 240) {
            return $clean;
        }

        return rtrim(mb_substr($clean, 0, 239), '.,; ').'…';
    }
}
