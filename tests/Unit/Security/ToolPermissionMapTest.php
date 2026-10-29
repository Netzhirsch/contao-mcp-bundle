<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Security;

use Netzhirsch\ContaoMcpBundle\Extension\McpToolPermissionProviderInterface;
use Netzhirsch\ContaoMcpBundle\Security\ExtensionPermissionMap;
use Netzhirsch\ContaoMcpBundle\Security\ToolPermissionMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The map decides which backend permission each of the ~144 tools implies.
 * A wrong entry means either a security hole (too lax) or a restricted user
 * being blocked from something they may do (too strict). The routing logic
 * (entity-prefix → table, verb → op, longest-prefix-wins, specials) is pinned
 * here exhaustively.
 */
#[CoversClass(ToolPermissionMap::class)]
final class ToolPermissionMapTest extends TestCase
{
    private ToolPermissionMap $map;

    protected function setUp(): void
    {
        $this->map = new ToolPermissionMap();
    }

    public function testCrudVerbsMapToDcOperations(): void
    {
        self::assertSame(['kind' => 'dc', 'table' => 'tl_news', 'op' => 'create', 'fields' => ['headline' => 'X']], $this->map->requirement('news_create', ['headline' => 'X']));
        self::assertReq(['kind' => 'dc', 'table' => 'tl_news', 'op' => 'update'], $this->map->requirement('news_update', ['id' => 5, 'headline' => 'Y']));
        self::assertReq(['kind' => 'dc', 'table' => 'tl_news', 'op' => 'delete'], $this->map->requirement('news_delete', ['id' => 5]));
        self::assertReq(['kind' => 'dc', 'table' => 'tl_news', 'op' => 'read'], $this->map->requirement('news_list', []));
        self::assertReq(['kind' => 'dc', 'table' => 'tl_news', 'op' => 'read'], $this->map->requirement('news_get', ['id' => 5]));
    }

    public function testIdIsExtractedForRowLevelOps(): void
    {
        $req = $this->map->requirement('content_update', ['id' => 42, 'text' => 'hi']);
        self::assertSame(42, $req['id'] ?? null);
        $req2 = $this->map->requirement('external_id_set', ['table' => 'tl_news', 'row_id' => 7, 'namespace' => 'x', 'external_key' => 'y']);
        self::assertSame(7, $req2['id'] ?? null);
    }

    public function testLongestPrefixWins(): void
    {
        // news_archive must NOT be swallowed by the 'news' prefix.
        self::assertReq(['kind' => 'dc', 'table' => 'tl_news_archive', 'op' => 'create'], $this->map->requirement('news_archive_create', []));
        // member_group vs member.
        self::assertReq(['kind' => 'dc', 'table' => 'tl_member_group', 'op' => 'create'], $this->map->requirement('member_group_create', []));
        // calendar_event vs calendar.
        self::assertReq(['kind' => 'dc', 'table' => 'tl_calendar_events', 'op' => 'update'], $this->map->requirement('calendar_event_update', ['id' => 1]));
        // newsletter_channel / newsletter_recipient vs newsletter.
        self::assertReq(['kind' => 'dc', 'table' => 'tl_newsletter_channel', 'op' => 'delete'], $this->map->requirement('newsletter_channel_delete', ['id' => 1]));
        self::assertReq(['kind' => 'dc', 'table' => 'tl_newsletter_recipients', 'op' => 'create'], $this->map->requirement('newsletter_recipient_create', []));
        // image_size_item vs image_size.
        self::assertReq(['kind' => 'dc', 'table' => 'tl_image_size_item', 'op' => 'update'], $this->map->requirement('image_size_item_update', ['id' => 1]));
    }

    #[DataProvider('adminOnlyTools')]
    public function testAdminOnlyTools(string $tool): void
    {
        self::assertSame('admin', $this->map->requirement($tool, [])['kind'] ?? null);
    }

    public static function adminOnlyTools(): array
    {
        return [['system_settings_update'], ['maintenance_run'], ['maintenance_jobs_list'], ['page_cache_invalidate'], ['dbafs_sync']];
    }

    #[DataProvider('readOnlyMetaTools')]
    public function testReadOnlyMetaTools(string $tool): void
    {
        self::assertSame('none', $this->map->requirement($tool, [])['kind'] ?? null);
    }

    public static function readOnlyMetaTools(): array
    {
        return [['ping'], ['contao_version'], ['installed_bundles'], ['entity_query_options'], ['insert_tags_list'], ['contao_search_tools'], ['contao_describe_tool'], ['external_id_lookup'], ['external_ids_list']];
    }

    /**
     * These three answer questions about the HOST — absolute paths, the file
     * mode of the OAuth private key, the OS user, adminEmail — and were
     * readable by every authenticated caller (audit F08). None of it is needed
     * to edit a page.
     *
     * `installed_bundles` and `contao_version` stay open on purpose: the
     * bundle's own error messages tell callers to run them, and a non-admin who
     * cannot ask what is installed cannot act on that advice.
     *
     * @return iterable<string, array{string}>
     */
    public static function hostFacingMetaTools(): iterable
    {
        yield 'system_settings' => ['system_settings'];
        yield 'system_health_check' => ['system_health_check'];
        yield 'server_info' => ['server_info'];
    }

    #[DataProvider('hostFacingMetaTools')]
    public function testHostFacingMetaToolsAreAdminOnly(string $tool): void
    {
        self::assertSame(['kind' => 'admin'], $this->map->requirement($tool, []));
    }

    public function testContaoCallIsProxy(): void
    {
        self::assertSame('proxy', $this->map->requirement('contao_call', ['name' => 'news_delete'])['kind'] ?? null);
    }

    public function testModuleTools(): void
    {
        self::assertSame(['kind' => 'module', 'module' => 'tpl_editor'], $this->map->requirement('template_create', ['name' => 'foo']));
        self::assertSame(['kind' => 'module', 'module' => 'user'], $this->map->requirement('users_list', []));
    }

    /**
     * The file manager is not a module gate any more: `module: files` left out
     * the filemounts and the fop rights, so a user confined to one customer's
     * folder could read and delete another's. Each tool now carries the
     * operation, and the paths it touches come from the call.
     */
    public function testFileToolsCarryTheirOperation(): void
    {
        self::assertSame('file', $this->map->requirement('file_upload', [])['kind']);
        self::assertSame('upload', $this->map->requirement('file_upload', [])['op']);
        self::assertSame('read', $this->map->requirement('file_get', [])['op']);
        self::assertSame('delete', $this->map->requirement('file_delete', [])['op']);
        self::assertSame('delete_recursive', $this->map->requirement('folder_delete', [])['op']);
        self::assertSame('edit', $this->map->requirement('file_update_meta', [])['op']);
    }

    /**
     * Both ends of a move have to be checked — landing a file where you may not
     * write is the same problem as taking one from where you may not read.
     */
    public function testAMoveCarriesSourceAndTarget(): void
    {
        $req = $this->map->requirement('file_move', [
            'path' => 'kundeA/x.pdf',
            'new_parent_path' => 'kundeB',
        ]);

        self::assertSame(['kundeA/x.pdf', 'kundeB'], $req['paths']);
    }

    public function testAnUploadCarriesItsTargetFolder(): void
    {
        $req = $this->map->requirement('file_upload', ['parent_path' => 'kundeA/bilder', 'name' => 'x.jpg']);

        self::assertSame(['kundeA/bilder'], $req['paths']);
    }

    /**
     * Publishing a folder changes what the web server hands out and has no fop
     * right of its own. It was admin-only by falling off the map; now it is
     * admin-only on purpose.
     */
    public function testPublishingAFolderIsAdminOnly(): void
    {
        self::assertSame(['kind' => 'admin'], $this->map->requirement('folder_set_public', ['path' => 'x']));
    }

    public function testExternalIdSetResolvesTableFromArgs(): void
    {
        self::assertReq(['kind' => 'dc', 'table' => 'tl_news', 'op' => 'update'], $this->map->requirement('external_id_set', ['table' => 'tl_news', 'row_id' => 1]));
        // Missing/invalid table → admin-only fallback (never silently allowed).
        self::assertSame('admin', $this->map->requirement('external_id_set', ['row_id' => 1])['kind'] ?? null);
        self::assertSame('admin', $this->map->requirement('external_id_set', ['table' => 'evil; DROP', 'row_id' => 1])['kind'] ?? null);
    }

    public function testPageTreeHelpers(): void
    {
        self::assertReq(['kind' => 'dc', 'table' => 'tl_page', 'op' => 'read'], $this->map->requirement('pages_tree', []));
        self::assertReq(['kind' => 'dc', 'table' => 'tl_page', 'op' => 'read'], $this->map->requirement('page_preview', ['page_id' => 3]));
        self::assertReq(['kind' => 'dc', 'table' => 'tl_page', 'op' => 'update'], $this->map->requirement('language_link_pages', []));
        self::assertReq(['kind' => 'dc', 'table' => 'tl_page', 'op' => 'create'], $this->map->requirement('page_create', []));
    }

    #[DataProvider('pluralListTools')]
    public function testPluralListToolsResolveToCorrectTable(string $tool, string $table): void
    {
        // Regression: plural list names (articles_list, members_list, …) don't
        // match the singular entity prefixes and previously fell through to
        // admin-only or resolved to the wrong parent table.
        self::assertReq(['kind' => 'dc', 'table' => $table, 'op' => 'read'], $this->map->requirement($tool, []));
    }

    /** @return array<string, array{string, string}> */
    public static function pluralListTools(): array
    {
        return [
            'articles_list' => ['articles_list', 'tl_article'],
            'calendars_list' => ['calendars_list', 'tl_calendar'],
            'faqs_list' => ['faqs_list', 'tl_faq'],
            'themes_list' => ['themes_list', 'tl_theme'],
            'layouts_list' => ['layouts_list', 'tl_layout'],
            'modules_list' => ['modules_list', 'tl_module'],
            'image_sizes_list' => ['image_sizes_list', 'tl_image_size'],
            'members_list' => ['members_list', 'tl_member'],
            'forms_list' => ['forms_list', 'tl_form'],
            'newsletters_list' => ['newsletters_list', 'tl_newsletter'],
            'comments_list' => ['comments_list', 'tl_comments'],
            'url_rewrites_list' => ['url_rewrites_list', 'tl_url_rewrite'],
            'news_archives_list' => ['news_archives_list', 'tl_news_archive'],
            'calendar_events_list' => ['calendar_events_list', 'tl_calendar_events'],
            'faq_categories_list' => ['faq_categories_list', 'tl_faq_category'],
            'member_groups_list' => ['member_groups_list', 'tl_member_group'],
            'newsletter_channels_list' => ['newsletter_channels_list', 'tl_newsletter_channel'],
            'newsletter_recipients_list' => ['newsletter_recipients_list', 'tl_newsletter_recipients'],
            'image_size_items_list' => ['image_size_items_list', 'tl_image_size_item'],
        ];
    }

    public function testUnknownToolReturnsNull(): void
    {
        self::assertNull($this->map->requirement('definitely_not_a_tool', []));
        self::assertNull($this->map->requirement('', []));
    }

    public function testDeclaredExtensionToolResolvesAndHydrates(): void
    {
        // An extension tool that declared a dc requirement gets the same id +
        // fields hydration as a core tool — so non-admins reach it with parity.
        $map = new ToolPermissionMap(new ExtensionPermissionMap([
            self::permissionProvider(['acme_thing_update' => ['kind' => 'dc', 'table' => 'tl_acme_thing', 'op' => 'update']]),
        ]));

        $req = $map->requirement('acme_thing_update', ['id' => 9, 'label' => 'X']);
        self::assertSame('dc', $req['kind'] ?? null);
        self::assertSame('tl_acme_thing', $req['table'] ?? null);
        self::assertSame('update', $req['op'] ?? null);
        self::assertSame(9, $req['id'] ?? null);
        self::assertSame(['label' => 'X'], $req['fields'] ?? null);
    }

    public function testCoreToolWinsOverExtensionDeclaration(): void
    {
        // An extension must never be able to relax a core tool's requirement.
        $map = new ToolPermissionMap(new ExtensionPermissionMap([
            self::permissionProvider(['news_delete' => ['kind' => 'none']]),
        ]));

        self::assertReq(['kind' => 'dc', 'table' => 'tl_news', 'op' => 'delete'], $map->requirement('news_delete', ['id' => 1]));
    }

    public function testUndeclaredExtensionToolStaysNull(): void
    {
        $map = new ToolPermissionMap(new ExtensionPermissionMap([
            self::permissionProvider(['acme_known' => ['kind' => 'none']]),
        ]));

        self::assertNull($map->requirement('acme_unknown', []));
    }

    /**
     * @param array<string, array<string, mixed>> $permissions
     */
    private static function permissionProvider(array $permissions): McpToolPermissionProviderInterface
    {
        return new class($permissions) implements McpToolPermissionProviderInterface {
            /** @param array<string, array<string, mixed>> $permissions */
            public function __construct(private readonly array $permissions)
            {
            }

            public function getMcpToolPermissions(): array
            {
                return $this->permissions;
            }
        };
    }

    /**
     * The voters read columns: a parent passed as `archive_id` has to reach
     * them as `pid`, an author as `author`. {@see ParentScopedWriteTest} shows
     * what happened when it did not.
     */
    public function testArgumentsArriveUnderTheirColumnName(): void
    {
        $req = $this->map->requirement('news_create', ['archive_id' => 4, 'headline' => 'X', 'author_id' => 3]);
        self::assertSame(['pid' => 4, 'headline' => 'X', 'author' => 3], $req['fields'] ?? null);

        $req = $this->map->requirement('article_update', ['id' => 5, 'page_id' => 8]);
        self::assertSame(['pid' => 8], $req['fields'] ?? null);

        $req = $this->map->requirement('form_field_create', ['form_id' => 2, 'type' => 'text']);
        self::assertSame(['pid' => 2, 'type' => 'text'], $req['fields'] ?? null);

        // The translation is per table: tl_content takes `pid` as it is.
        $req = $this->map->requirement('content_create', ['ptable' => 'tl_article', 'pid' => 3, 'type' => 'text']);
        self::assertSame(['ptable' => 'tl_article', 'pid' => 3, 'type' => 'text'], $req['fields'] ?? null);
    }

    /**
     * Most write tools take their fields as an object, and the snake_case
     * keys in it are translated just like flat arguments.
     */
    public function testKeysInsideAFieldsObjectArriveUnderTheirColumnName(): void
    {
        $req = $this->map->requirement('layout_update', ['id' => 1, 'fields' => ['css_class' => 'x', 'add_jquery' => true, 'name' => 'L']]);
        self::assertSame(['cssClass' => 'x', 'addJQuery' => true, 'name' => 'L'], $req['fields'] ?? null);

        $req = $this->map->requirement('member_update', ['id' => 1, 'fields' => ['home_dir' => 'files/m', 'date_of_birth' => '1990-01-01']]);
        self::assertSame(['homeDir' => 'files/m', 'dateOfBirth' => '1990-01-01'], $req['fields'] ?? null);

        // `form_id` means a different column on each side of the form.
        self::assertSame(['formID' => 'kontakt'], $this->map->requirement('form_update', ['id' => 1, 'fields' => ['form_id' => 'kontakt']])['fields'] ?? null);
        self::assertSame(['pid' => 2, 'type' => 'text'], $this->map->requirement('form_field_create', ['form_id' => 2, 'type' => 'text'])['fields'] ?? null);
    }

    /**
     * `extras` reaches the same mapper as the flat arguments, so its keys are
     * written fields. A flat argument wins over the same key in an object, as
     * it does in the tools.
     */
    public function testExtrasAreWrittenFieldsAndAFlatArgumentWins(): void
    {
        $req = $this->map->requirement('news_update', ['id' => 5, 'headline' => 'Flat', 'extras' => ['published' => true, 'headline' => 'Nested', 'author_id' => 3]]);

        self::assertSame(['published' => true, 'headline' => 'Flat', 'author' => 3], $req['fields'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string, bool, string, bool}>
     */
    public static function negatedArguments(): iterable
    {
        yield 'member, deactivate' => ['member_update', 'fields', false, 'disable', true];
        yield 'member, activate' => ['member_update', 'fields', true, 'disable', false];
        yield 'member group' => ['member_group_update', 'fields', false, 'disable', true];
        yield 'image size item' => ['image_size_item_update', 'fields', false, 'invisible', true];
        yield 'url rewrite' => ['url_rewrite_update', 'flat', true, 'inactive', false];
    }

    /**
     * `active` writes the opposite column; the value is negated with it, or a
     * deactivation would compare as unchanged against the stored row.
     */
    #[DataProvider('negatedArguments')]
    public function testActiveArrivesAsItsNegatedColumn(string $tool, string $shape, bool $active, string $column, bool $expected): void
    {
        $args = $shape === 'fields' ? ['id' => 1, 'fields' => ['active' => $active]] : ['id' => 1, 'active' => $active];

        self::assertSame([$column => $expected], $this->map->requirement($tool, $args)['fields'] ?? null);
    }

    public function testControlArgsAreNotTreatedAsFields(): void
    {
        $req = $this->map->requirement('news_update', ['id' => 5, 'confirm_destructive' => true, 'cascade' => true, 'headline' => 'X']);
        self::assertArrayHasKey('fields', $req);
        self::assertSame(['headline' => 'X'], $req['fields']);
        self::assertArrayNotHasKey('confirm_destructive', $req['fields']);
    }

    /**
     * @param array<string, mixed>      $expectedSubset
     * @param array<string, mixed>|null $actual
     */
    private static function assertReq(array $expectedSubset, ?array $actual): void
    {
        self::assertIsArray($actual);
        foreach ($expectedSubset as $k => $v) {
            self::assertSame($v, $actual[$k] ?? null, "requirement key '$k'");
        }
    }
}
