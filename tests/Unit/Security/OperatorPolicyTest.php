<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Security;

use Doctrine\DBAL\Connection;
use Mcp\Capability\Attribute\McpTool;
use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\Security\OperatorPolicy;
use Netzhirsch\ContaoMcpBundle\Security\ToolPermissionMap;
use Netzhirsch\ContaoMcpBundle\Server\ServerInstructions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The operator switches: what they take out of the catalogue, which single
 * calls they refuse, and how they turn a create into an unpublished one.
 *
 * The catalogue tests run over every tool the bundle registers. A new tool
 * that writes without a write verb in its name must not slip through the
 * read-only switch as a "lookup" — that is the failure this pins.
 */
#[CoversClass(OperatorPolicy::class)]
#[CoversClass(ServerInstructions::class)]
final class OperatorPolicyTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'mcp-policy-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $file = $this->dir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'mcp'.\DIRECTORY_SEPARATOR.'config.json';
        @unlink($file);
        @rmdir(\dirname($file));
        @rmdir(\dirname($file, 2));
        @rmdir($this->dir);
    }

    public function testEveryRegisteredToolHasAnOperation(): void
    {
        $map = new ToolPermissionMap();
        $unknown = array_values(array_filter(self::registeredToolNames(), static fn (string $t): bool => $map->operationOf($t) === null));

        self::assertSame([], $unknown, 'These tools have no operation the operator switches could act on.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function operations(): iterable
    {
        yield 'list' => ['news_list', 'read'];
        yield 'get' => ['page_get', 'read'];
        yield 'create' => ['news_create', 'create'];
        yield 'tree create' => ['pages_create_tree', 'create'];
        yield 'delete' => ['page_delete', 'delete'];
        yield 'tree delete' => ['pages_delete_tree', 'delete'];
        yield 'upload' => ['file_upload', 'create'];
        yield 'file read' => ['file_get', 'read'];
        yield 'folder delete' => ['folder_delete', 'delete'];
        yield 'metadata' => ['file_update_meta', 'update'];
        yield 'patch' => ['entity_field_patch', 'update'];
        yield 'duplicate' => ['entity_duplicate', 'create'];
        yield 'rename without a verb' => ['template_rename', 'update'];
        yield 'maintenance' => ['maintenance_run', 'update'];
        yield 'cache' => ['page_cache_invalidate', 'update'];
        yield 'translate text only' => ['deepl_translate', 'read'];
        yield 'translate records' => ['deepl_translate_records', 'update'];
        yield 'discovery' => ['contao_call', 'read'];
    }

    #[DataProvider('operations')]
    public function testOperationOf(string $tool, string $operation): void
    {
        self::assertSame($operation, (new ToolPermissionMap())->operationOf($tool));
    }

    public function testDefaultsBlockNothingAndRefuseNothing(): void
    {
        $policy = $this->policy([]);

        self::assertSame([], $policy->blockedTools(self::registeredToolNames()));
        self::assertSame([], $policy->activeRestrictions());
        self::assertNull($policy->check('page_delete', ['id' => 5, 'confirm_destructive' => true]));
        self::assertNull($policy->check('news_create', ['archive_id' => 1, 'headline' => 'x', 'published' => true]));
        self::assertSame(['archive_id' => 1], $policy->rewrite('news_create', ['archive_id' => 1]));
    }

    public function testReadOnlyLeavesOnlyReadingTools(): void
    {
        $policy = $this->policy(['read_only' => true]);
        $map = new ToolPermissionMap();
        $all = self::registeredToolNames();
        $remaining = array_diff($all, $policy->blockedTools($all));

        foreach ($remaining as $tool) {
            self::assertSame('read', $map->operationOf($tool), "$tool survives read-only although it does not only read.");
        }
        self::assertContains('pages_list', $remaining);
        self::assertSame('read_only', $policy->check('news_update', ['id' => 1, 'headline' => 'x'])['error'] ?? null);
        self::assertNull($policy->check('news_get', ['id' => 1]));
    }

    public function testNoDeleteTakesOutEveryDeletingTool(): void
    {
        $policy = $this->policy(['allow_delete' => false]);
        $map = new ToolPermissionMap();
        $all = self::registeredToolNames();
        $blocked = $policy->blockedTools($all);

        foreach ($all as $tool) {
            self::assertSame($map->operationOf($tool) === 'delete', \in_array($tool, $blocked, true), $tool);
        }
        self::assertSame('delete_not_allowed', $policy->check('file_delete', ['path' => 'a.txt'])['error'] ?? null);
    }

    public function testPrivacyModeKeepsPersonalDataOut(): void
    {
        $policy = $this->policy(['privacy_mode' => true]);
        $blocked = $policy->blockedTools(self::registeredToolNames());

        foreach (['members_list', 'member_get', 'member_create', 'newsletter_recipients_list', 'comments_list', 'users_list', 'leads_list', 'lead_get'] as $tool) {
            if (\in_array($tool, self::registeredToolNames(), true)) {
                self::assertContains($tool, $blocked);
            }
        }
        self::assertNotContains('member_groups_list', $blocked, 'Group names are no personal data.');
        self::assertNotContains('news_list', $blocked);

        // A table named in the call.
        self::assertSame('privacy_mode', $policy->check('entity_field_patch', ['table' => 'tl_member', 'id' => 1, 'field' => 'city', 'old' => 'a', 'new' => 'b'])['error'] ?? null);
        // IBAN on a table that is otherwise fine, as an argument and as a patched column.
        self::assertSame('privacy_mode', $policy->check('news_update', ['id' => 1, 'extras' => ['IBAN' => 'DE00']])['error'] ?? null);
        self::assertSame('privacy_mode', $policy->check('entity_field_patch', ['table' => 'tl_news', 'id' => 1, 'field' => 'bic', 'old' => 'a', 'new' => 'b'])['error'] ?? null);
        self::assertNull($policy->check('news_update', ['id' => 1, 'headline' => 'x']));
    }

    public function testPrivacyModeRemovesIbanAndBicFromResults(): void
    {
        $result = ['items' => [['name' => 'A', 'iban' => 'DE00', 'nested' => ['BIC' => 'X', 'keep' => 1]]], 'count' => 1];

        self::assertSame($result, $this->policy([])->redact($result));

        $policy = $this->policy(['privacy_mode' => true]);
        self::assertTrue($policy->wouldRedact($result));
        self::assertSame(['items' => [['name' => 'A', 'nested' => ['keep' => 1]]], 'count' => 1], $policy->redact($result));
        self::assertFalse($policy->wouldRedact(['items' => []]));
    }

    public function testCreateUnpublishedAddsTheHiddenFlag(): void
    {
        $policy = $this->policy(['create_unpublished' => true]);

        self::assertSame(['archive_id' => 1, 'published' => false], $policy->rewrite('news_create', ['archive_id' => 1]));
        self::assertSame(['pid' => 2, 'title' => 't', 'published' => false], $policy->rewrite('page_create', ['pid' => 2, 'title' => 't']));
        self::assertSame(
            ['ptable' => 'tl_article', 'pid' => 3, 'type' => 'text', 'sorting' => 128, 'fields' => ['text' => 'x', 'invisible' => true]],
            $policy->rewrite('content_create', ['ptable' => 'tl_article', 'pid' => 3, 'type' => 'text', 'sorting' => 128, 'fields' => ['text' => 'x']]),
        );
        self::assertSame(
            ['table' => 'tl_page', 'id' => 4, 'overrides' => ['title' => 'Copy', 'published' => false]],
            $policy->rewrite('entity_duplicate', ['table' => 'tl_page', 'id' => 4, 'overrides' => ['title' => 'Copy']]),
        );
        self::assertSame(
            ['table' => 'tl_content', 'id' => 4, 'overrides' => ['invisible' => true]],
            $policy->rewrite('entity_duplicate', ['table' => 'tl_content', 'id' => 4]),
        );

        // Every page of a tree, only the top level of an element tree.
        $pages = $policy->rewrite('pages_create_tree', ['pid' => 0, 'pages' => [['title' => 'A', 'type' => 'regular', 'children' => [['title' => 'B', 'type' => 'regular']]]]]);
        self::assertFalse($pages['pages'][0]['published']);
        self::assertFalse($pages['pages'][0]['children'][0]['published']);

        $elements = $policy->rewrite('content_create_tree', ['ptable' => 'tl_article', 'pid' => 3, 'elements' => [['type' => 'element_group', 'children' => [['type' => 'text']]]]]);
        self::assertTrue($elements['elements'][0]['fields']['invisible']);
        self::assertArrayNotHasKey('fields', $elements['elements'][0]['children'][0]);

        // An explicit value is the caller's — refused by check(), not turned around here.
        self::assertSame(['archive_id' => 1, 'published' => true], $policy->rewrite('news_create', ['archive_id' => 1, 'published' => true]));
        self::assertSame('create_unpublished', $policy->check('news_create', ['archive_id' => 1, 'headline' => 'x', 'published' => true])['error'] ?? null);
        self::assertSame('create_unpublished', $policy->check('content_create', ['ptable' => 'tl_article', 'pid' => 3, 'type' => 'text', 'sorting' => 1, 'fields' => ['invisible' => false]])['error'] ?? null);
        self::assertSame('create_unpublished', $policy->check('pages_create_tree', ['pid' => 0, 'pages' => [['title' => 'A', 'type' => 'regular', 'children' => [['title' => 'B', 'type' => 'regular', 'published' => true]]]]])['error'] ?? null);
        self::assertNull($policy->check('news_create', ['archive_id' => 1, 'headline' => 'x', 'published' => false]));

        // Publishing itself stays allowed with this switch alone.
        self::assertNull($this->policy(['create_unpublished' => true], '')->check('news_update', ['id' => 1, 'published' => true]));
    }

    public function testNoPublishRefusesMakingAHiddenRecordVisible(): void
    {
        // published = '' is a hidden news entry; invisible = '1' a hidden element.
        $hidden = $this->policy(['allow_publish' => false], '');
        self::assertSame('publish_not_allowed', $hidden->check('news_update', ['id' => 1, 'published' => true])['error'] ?? null);
        $hiddenElement = $this->policy(['allow_publish' => false], '1');
        self::assertSame('publish_not_allowed', $hiddenElement->check('content_update', ['id' => 1, 'fields' => ['invisible' => false]])['error'] ?? null);
        // Hiding is not publishing.
        self::assertNull($hidden->check('news_update', ['id' => 1, 'published' => false]));
        self::assertNull($hidden->check('news_update', ['id' => 1, 'headline' => 'x']));
        // New content starts hidden without a second switch.
        self::assertSame(['archive_id' => 1, 'published' => false], $hidden->rewrite('news_create', ['archive_id' => 1]));
        self::assertSame('publish_not_allowed', $hidden->check('news_create', ['archive_id' => 1, 'headline' => 'x', 'published' => true])['error'] ?? null);

        // Writing the value it already has is not publishing either.
        $visible = $this->policy(['allow_publish' => false], '1');
        self::assertNull($visible->check('news_update', ['id' => 1, 'published' => true]));
    }

    public function testInstructionsCarryHouseRulesAndActiveSwitches(): void
    {
        self::assertNull($this->instructions([])->text());

        $text = (string) $this->instructions(['house_rules' => "Write the company name in lower case.\r\nNo exclamation marks.", 'read_only' => true, 'privacy_mode' => true])->text();

        self::assertStringContainsString("Write the company name in lower case.\nNo exclamation marks.", $text);
        self::assertStringContainsString('Read-only', $text);
        self::assertStringContainsString('Privacy mode', $text);
        self::assertStringNotContainsString('Deleting is switched off', $text, 'Read-only already says it.');
    }

    public function testHouseRulesAreCleanedAndCut(): void
    {
        $storage = new McpServerConfigStorage($this->dir);
        $storage->save([...$storage->defaults(), 'house_rules' => "  Rule\x07 one\r\n".str_repeat('x', McpServerConfigStorage::HOUSE_RULES_MAX_LENGTH)]);
        $rules = $storage->load()['house_rules'];

        self::assertStringStartsWith("Rule one\n", $rules);
        self::assertSame(McpServerConfigStorage::HOUSE_RULES_MAX_LENGTH, mb_strlen($rules));
    }

    public function testSwitchesRoundTripAndDefaultToTheOldBehaviour(): void
    {
        $storage = new McpServerConfigStorage($this->dir);
        $defaults = $storage->defaults();

        self::assertFalse($defaults['read_only']);
        self::assertTrue($defaults['allow_publish']);
        self::assertTrue($defaults['allow_delete']);
        self::assertFalse($defaults['create_unpublished']);
        self::assertFalse($defaults['privacy_mode']);
        self::assertTrue($defaults['admins_allowed']);

        $switched = ['read_only' => true, 'allow_publish' => false, 'allow_delete' => false, 'create_unpublished' => true, 'privacy_mode' => true, 'admins_allowed' => false];
        $storage->save([...$defaults, ...$switched]);

        self::assertSame($switched, array_intersect_key($storage->load(), $switched));
    }

    /**
     * @param array<string, mixed> $switches
     */
    private function policy(array $switches, string|false $storedPublishValue = false): OperatorPolicy
    {
        $storage = new McpServerConfigStorage($this->dir);
        $storage->save([...$storage->defaults(), ...$switches]);

        $connection = $this->createStub(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $id): string => '`'.$id.'`');
        $connection->method('fetchOne')->willReturn($storedPublishValue);

        return new OperatorPolicy($storage, new ToolPermissionMap(), $connection);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function instructions(array $config): ServerInstructions
    {
        $policy = $this->policy($config);

        return new ServerInstructions(new McpServerConfigStorage($this->dir), $policy);
    }

    /**
     * @return list<string>
     */
    private static function registeredToolNames(): array
    {
        static $names = null;
        if ($names !== null) {
            return $names;
        }

        $names = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/src/Tool', \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), '.php') || !str_contains((string) file_get_contents($file->getPathname()), '#[McpTool(')) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen(self::ROOT.'/src/'), -4);
            $class = new \ReflectionClass('Netzhirsch\\ContaoMcpBundle\\'.str_replace(['/', '\\\\'], '\\', $relative));
            if ($class->isAbstract() || $class->isInterface()) {
                continue;
            }

            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(McpTool::class) as $attribute) {
                    $names[] = $attribute->newInstance()->name ?? $method->getName();
                }
            }
        }

        return $names = array_values(array_unique($names));
    }
}
