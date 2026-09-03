<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Security;

/**
 * Maps an MCP tool call (name + arguments) to the backend permission it
 * implies, so {@see McpPermissionEnforcer} can ask the right voter.
 *
 * A requirement is one of:
 *   ['kind' => 'none']                                   — read-only meta, no check
 *   ['kind' => 'dc', 'table' => 'tl_x', 'op' => '…',     — Contao DataContainer voter
 *    'id' => ?int, 'fields' => array<string,mixed>|null]
 *   ['kind' => 'module', 'module' => 'files']            — backend module access
 *   ['kind' => 'admin']                                  — admin only
 *   ['kind' => 'proxy']                                  — contao_call, re-check target
 *
 * Design: most tools follow `<entity>_<verb>` and map by convention
 * (entity → table, verb → CRUD op). Everything that doesn't fit is listed
 * explicitly. Unknown tools resolve to {@see requirement()} = null, which the
 * enforcer treats as admin-only (secure default — an unmapped write never
 * silently slips through for a restricted user).
 *
 * Third-party extension tools can opt INTO non-admin parity by declaring their
 * requirement via {@see \Netzhirsch\ContaoMcpBundle\Extension\McpToolPermissionProviderInterface};
 * those declarations are consulted AFTER the core convention, so a core tool
 * can never be overridden by an extension (see {@see ExtensionPermissionMap}).
 */
final class ToolPermissionMap
{
    /** Entity-prefix → Contao table. Longest prefix wins (checked in order). */
    private const ENTITY_TABLES = [
        'news_archive' => 'tl_news_archive',
        'news' => 'tl_news',
        'calendar_event' => 'tl_calendar_events',
        'calendar' => 'tl_calendar',
        'faq_category' => 'tl_faq_category',
        'faq' => 'tl_faq',
        'article' => 'tl_article',
        'content' => 'tl_content',
        'module' => 'tl_module',
        'layout' => 'tl_layout',
        'theme' => 'tl_theme',
        'image_size_item' => 'tl_image_size_item',
        'image_size' => 'tl_image_size',
        'member_group' => 'tl_member_group',
        'member' => 'tl_member',
        'form_field' => 'tl_form_field',
        'form' => 'tl_form',
        'newsletter_channel' => 'tl_newsletter_channel',
        'newsletter_recipient' => 'tl_newsletter_recipients',
        'newsletter' => 'tl_newsletter',
        'comment' => 'tl_comments',
        'url_rewrite' => 'tl_url_rewrite',
        'page' => 'tl_page',
    ];

    /**
     * Explicit per-tool requirements that don't follow the entity_verb
     * convention. Value is a requirement WITHOUT the runtime id/fields (those
     * are filled from args).
     *
     * @var array<string, array<string, mixed>>
     */
    private const SPECIAL = [
        // Discovery / meta — read-only, harmless.
        'contao_search_tools' => ['kind' => 'none'],
        'contao_describe_tool' => ['kind' => 'none'],
        'contao_call' => ['kind' => 'proxy'],
        'ping' => ['kind' => 'none'],
        'contao_version' => ['kind' => 'none'],
        'server_info' => ['kind' => 'none'],
        'installed_bundles' => ['kind' => 'none'],
        'system_health_check' => ['kind' => 'none'],
        'entity_query_options' => ['kind' => 'none'],
        'insert_tags_list' => ['kind' => 'none'],
        'system_settings' => ['kind' => 'none'],

        // Admin-only.
        'system_settings_update' => ['kind' => 'admin'],
        'maintenance_run' => ['kind' => 'admin'],
        'maintenance_jobs_list' => ['kind' => 'admin'],
        'page_cache_invalidate' => ['kind' => 'admin'],
        'dbafs_sync' => ['kind' => 'admin'],

        // Template editor module (file-based, not a DC table).
        'templates_list' => ['kind' => 'module', 'module' => 'tpl_editor'],
        'template_overrides_list' => ['kind' => 'module', 'module' => 'tpl_editor'],
        'template_get' => ['kind' => 'module', 'module' => 'tpl_editor'],
        'template_lookup' => ['kind' => 'module', 'module' => 'tpl_editor'],
        'template_dependencies' => ['kind' => 'module', 'module' => 'tpl_editor'],
        'template_create' => ['kind' => 'module', 'module' => 'tpl_editor'],
        'template_update' => ['kind' => 'module', 'module' => 'tpl_editor'],
        'template_delete' => ['kind' => 'module', 'module' => 'tpl_editor'],
        'template_rename' => ['kind' => 'module', 'module' => 'tpl_editor'],

        // File manager module.
        'files_list' => ['kind' => 'module', 'module' => 'files'],
        'files_search' => ['kind' => 'module', 'module' => 'files'],
        'file_get' => ['kind' => 'module', 'module' => 'files'],
        'file_upload' => ['kind' => 'module', 'module' => 'files'],
        'file_delete' => ['kind' => 'module', 'module' => 'files'],
        'file_rename' => ['kind' => 'module', 'module' => 'files'],
        'file_move' => ['kind' => 'module', 'module' => 'files'],
        'file_update_meta' => ['kind' => 'module', 'module' => 'files'],
        'folder_create' => ['kind' => 'module', 'module' => 'files'],
        'folder_delete' => ['kind' => 'module', 'module' => 'files'],

        // Backend users / groups — reference reads, gated by the user module.
        'users_list' => ['kind' => 'module', 'module' => 'user'],
        'user_groups_list' => ['kind' => 'module', 'module' => 'user'],

        // Leads (terminal42/contao-leads) — read-only form submissions, gated
        // by the "lead" backend module (BE_MOD['leads']['lead']). A user who
        // can open that module in the backend sees every lead, so a flat
        // module gate matches backend parity (no per-row ACL on tl_lead).
        'leads_list' => ['kind' => 'module', 'module' => 'lead'],
        'lead_get' => ['kind' => 'module', 'module' => 'lead'],

        // DeepL (numero2/contao-deepl). None of these names contains a write
        // verb, so the suffix heuristic below would read every one of them as a
        // lookup — including the two that overwrite records.
        //
        // deepl_status and deepl_translate touch no Contao record: text in,
        // translation out. That mirrors the host extension, whose button sits
        // in every edit mask a user can already open.
        'deepl_status' => ['kind' => 'none'],
        'deepl_translate' => ['kind' => 'none'],
        // Table comes from the call, so the gate is resolved per call. The tool
        // additionally checks EVERY record it touches (read for a preview,
        // update for a save) — a page tree writes tl_article and tl_content
        // too, and a gate on the argument table alone would not cover those.
        'deepl_translate_records' => ['kind' => 'dc_arg', 'op' => 'update'],
        'deepl_translate_page_tree' => ['kind' => 'dc', 'table' => 'tl_page', 'op' => 'update'],

        // Read-only introspection of the site-wide output filter. No record is
        // touched and the answer is the same for every caller, so there is no
        // table to gate on.
        'html_filter_info' => ['kind' => 'none'],
        'html_filter_preview' => ['kind' => 'none'],

        // External-id + move + language-link operate on a table given in args.
        'external_id_set' => ['kind' => 'dc_arg', 'op' => 'update'],
        'external_id_unset' => ['kind' => 'dc_arg', 'op' => 'update'],
        'external_id_lookup' => ['kind' => 'none'],
        'external_ids_list' => ['kind' => 'none'],
        'entity_move' => ['kind' => 'dc_arg', 'op' => 'update'],
        // Was missing, so it fell to the enforcer's secure default — the error
        // said as much ("is not permission-mapped"). Its three siblings above
        // and below are all mapped; this one was an oversight, not a decision,
        // and admin-only locked out exactly the editors who copy things. The
        // tool additionally checks read-on-source and create-under-target with
        // the concrete parent, as the *_create tools do.
        'entity_duplicate' => ['kind' => 'dc_arg', 'op' => 'create'],
        // "_patch" is not one of the write verbs the name heuristic knows, and
        // this one replaces text inside a record — it must never be read as a
        // lookup.
        'entity_field_patch' => ['kind' => 'dc_arg', 'op' => 'update'],
        'entity_language_link' => ['kind' => 'dc_arg', 'op' => 'update'],

        // Page-tree helpers (tl_page reads/writes).
        'pages_list' => ['kind' => 'dc', 'table' => 'tl_page', 'op' => 'read'],
        'pages_tree' => ['kind' => 'dc', 'table' => 'tl_page', 'op' => 'read'],
        // Ends in "_tree" but WRITES — the suffix heuristic below would
        // have read it as a lookup and let a read-only user build a page tree.
        'pages_create_tree' => ['kind' => 'dc', 'table' => 'tl_page', 'op' => 'create'],
        'pages_delete_tree' => ['kind' => 'dc', 'table' => 'tl_page', 'op' => 'delete'],
        // Same shape for content elements — explicit for the same reason.
        'content_create_tree' => ['kind' => 'dc', 'table' => 'tl_content', 'op' => 'create'],
        'page_translations_tree' => ['kind' => 'dc', 'table' => 'tl_page', 'op' => 'read'],
        'page_url' => ['kind' => 'dc', 'table' => 'tl_page', 'op' => 'read'],
        'page_preview' => ['kind' => 'dc', 'table' => 'tl_page', 'op' => 'read'],
        'language_link_pages' => ['kind' => 'dc', 'table' => 'tl_page', 'op' => 'update'],

        // Search index: it holds the rendered text of pages, so reading it is
        // a page read. (Protected entries are filtered out in the tool itself —
        // their access depends on frontend member groups, not backend rights.)
        'search_query' => ['kind' => 'dc', 'table' => 'tl_page', 'op' => 'read'],
        'search_index_status' => ['kind' => 'dc', 'table' => 'tl_page', 'op' => 'read'],

        // Reference lookup. It reads across every table to answer "what breaks
        // if I delete this", so it cannot be expressed as one DataContainer
        // permission — and a restricted user seeing that "some tl_module row
        // links here" would still be a disclosure. Admin-only, matching the
        // MCP backend modules.
        'usage_find' => ['kind' => 'admin'],
    ];

    /**
     * Plural list tools whose name does NOT match a singular ENTITY_TABLES
     * prefix (e.g. "articles_list" vs prefix "article", "member_groups_list"
     * vs "member_group_") — they would otherwise fall through to admin-only or
     * resolve to the wrong parent table. All are read-only listings.
     *
     * @var array<string, string>
     */
    private const LIST_TABLES = [
        'news_list' => 'tl_news',
        'news_archives_list' => 'tl_news_archive',
        'articles_list' => 'tl_article',
        'calendars_list' => 'tl_calendar',
        'calendar_events_list' => 'tl_calendar_events',
        'faqs_list' => 'tl_faq',
        'faq_categories_list' => 'tl_faq_category',
        'themes_list' => 'tl_theme',
        'layouts_list' => 'tl_layout',
        'modules_list' => 'tl_module',
        'image_sizes_list' => 'tl_image_size',
        'image_size_items_list' => 'tl_image_size_item',
        'members_list' => 'tl_member',
        'member_groups_list' => 'tl_member_group',
        'forms_list' => 'tl_form',
        'form_fields_list' => 'tl_form_field',
        'newsletters_list' => 'tl_newsletter',
        'newsletter_channels_list' => 'tl_newsletter_channel',
        'newsletter_recipients_list' => 'tl_newsletter_recipients',
        'comments_list' => 'tl_comments',
        'url_rewrites_list' => 'tl_url_rewrite',
    ];

    /** Control args that are never real entity fields (excluded from field-level alexf checks). */
    private const CONTROL_ARGS = ['confirm_destructive', 'cascade', 'id', 'limit', 'offset', 'q', 'filters'];

    /**
     * Defaulted so the unit tests (and any container without extension tools)
     * can `new ToolPermissionMap()` unchanged; the real container injects the
     * tagged-iterator-backed instance via autowiring.
     */
    public function __construct(
        private readonly ExtensionPermissionMap $extensionMap = new ExtensionPermissionMap(),
    ) {
    }

    /**
     * Resolve the permission requirement for a tool call.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>|null null = unknown tool (caller denies for non-admins)
     */
    public function requirement(string $tool, array $args): ?array
    {
        if (isset(self::SPECIAL[$tool])) {
            return $this->hydrate(self::SPECIAL[$tool], $args);
        }

        // Plural list tools that don't match a singular entity prefix.
        if (isset(self::LIST_TABLES[$tool])) {
            return $this->hydrate(['kind' => 'dc', 'table' => self::LIST_TABLES[$tool], 'op' => 'read'], $args);
        }

        // Convention: <entity>_<verb>.
        foreach (self::ENTITY_TABLES as $prefix => $table) {
            if ($tool === $prefix || str_starts_with($tool, $prefix.'_')) {
                $req = ['kind' => 'dc', 'table' => $table, 'op' => $this->inferOperation($tool)];

                return $this->hydrate($req, $args);
            }
        }

        // Third-party extension tool that declared its own requirement. Checked
        // LAST so a core tool always wins; undeclared extension tools stay null
        // → admin-only (the enforcer's secure default).
        $extReq = $this->extensionMap->baseRequirement($tool);
        if ($extReq !== null) {
            return $this->hydrate($extReq, $args);
        }

        return null;
    }

    /**
     * Fill runtime id/fields from the call arguments.
     *
     * @param array<string, mixed> $req
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $req, array $args): array
    {
        $kind = $req['kind'] ?? 'none';

        // Resolve a table given in args (external_id_*, entity_move, …).
        if ($kind === 'dc_arg') {
            $table = \is_string($args['table'] ?? null) ? $args['table'] : null;
            // SECURITY: this value is caller-supplied and reaches a SQL
            // identifier position in McpPermissionGuard::loadRecord(), which the
            // controller runs BEFORE the dispatcher's schema validation and
            // before the tool's own allowlist. A prefix check alone would let
            // `tl_user WHERE …-- ` through, so require a strict identifier.
            if ($table === null || preg_match('/^tl_[a-z0-9_]+$/', $table) !== 1) {
                return ['kind' => 'admin']; // can't resolve table → admin-only
            }
            $req = ['kind' => 'dc', 'table' => $table, 'op' => $req['op'] ?? 'update'];
        }

        if (($req['kind'] ?? null) !== 'dc') {
            return $req;
        }

        $op = $req['op'] ?? 'read';

        // Row id for read/update/delete.
        if (\in_array($op, ['read', 'update', 'delete'], true)) {
            $req['id'] = $this->extractId($args);
        }

        // Written fields for create/update (drives field-level alexf checks).
        if (\in_array($op, ['create', 'update'], true)) {
            $req['fields'] = $this->extractFields($args);
        }

        return $req;
    }

    private function inferOperation(string $tool): string
    {
        if (str_ends_with($tool, '_create')) {
            return 'create';
        }
        if (str_ends_with($tool, '_update')) {
            return 'update';
        }
        if (str_ends_with($tool, '_delete')) {
            return 'delete';
        }
        // A write verb anywhere in the name beats the read default. The
        // suffix checks above miss names like "pages_create_tree", and
        // guessing "read" for something that writes is the one direction
        // this must never fail in.
        foreach (['create', 'update', 'delete'] as $verb) {
            if (str_contains($tool, '_'.$verb)) {
                return $verb;
            }
        }

        // Everything else in an entity group is a read (list/get/tree/types/palette/options/…).
        return 'read';
    }

    /**
     * @param array<string, mixed> $args
     */
    private function extractId(array $args): ?int
    {
        foreach (['id', 'row_id'] as $key) {
            if (isset($args[$key]) && is_numeric($args[$key])) {
                return (int) $args[$key];
            }
        }

        return null;
    }

    /**
     * Field map for alexf checks: the args minus control args + a possible
     * nested "fields" map (the bundle's update tools accept either flat args
     * or a `fields` object).
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function extractFields(array $args): array
    {
        $fields = [];
        if (isset($args['fields']) && \is_array($args['fields'])) {
            $fields = $args['fields'];
        }
        foreach ($args as $key => $value) {
            if (!\in_array($key, self::CONTROL_ARGS, true)) {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }
}
