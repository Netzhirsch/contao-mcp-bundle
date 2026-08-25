<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Security;

use Netzhirsch\ContaoMcpBundle\Security\ToolPermissionMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * None of the DeepL tool names contains a write verb, yet two of them overwrite
 * records. Without the explicit entries the suffix heuristic reads
 * `deepl_translate_page_tree` as a lookup, and a read-only backend user could
 * rewrite an entire page tree into another language.
 */
#[CoversClass(ToolPermissionMap::class)]
final class DeepLPermissionTest extends TestCase
{
    public function testThePageTreeToolIsCheckedAsAWriteOnPages(): void
    {
        $requirement = (new ToolPermissionMap())->requirement('deepl_translate_page_tree', ['id' => 42]);

        self::assertNotNull($requirement);
        self::assertSame('dc', $requirement['kind']);
        self::assertSame('tl_page', $requirement['table']);
        self::assertSame('update', $requirement['op']);
        self::assertSame(42, $requirement['id']);
    }

    public function testTheRecordToolResolvesTheTableFromTheCall(): void
    {
        $requirement = (new ToolPermissionMap())->requirement('deepl_translate_records', ['table' => 'tl_news']);

        self::assertNotNull($requirement);
        self::assertSame('dc', $requirement['kind']);
        self::assertSame('tl_news', $requirement['table']);
        self::assertSame('update', $requirement['op']);
    }

    /**
     * A table name reaches a SQL identifier position further down, so anything
     * that is not a plain `tl_…` identifier must fall back to admin-only rather
     * than be passed along.
     */
    public function testAnUnusableTableArgumentFallsBackToAdminOnly(): void
    {
        $map = new ToolPermissionMap();

        self::assertSame('admin', $map->requirement('deepl_translate_records', [])['kind']);
        self::assertSame('admin', $map->requirement('deepl_translate_records', ['table' => 'tl_user WHERE 1=1 -- '])['kind']);
    }

    /**
     * These two touch no Contao record: text in, translation out. The host
     * extension gates its button the same way — it appears in every edit mask
     * the user can already open.
     */
    public function testTheRecordFreeToolsNeedNoTableRights(): void
    {
        $map = new ToolPermissionMap();

        self::assertSame('none', $map->requirement('deepl_status', [])['kind']);
        self::assertSame('none', $map->requirement('deepl_translate', [])['kind']);
    }

    /**
     * The regression this file exists for: with the explicit entries removed,
     * the name heuristic would classify both as reads.
     */
    public function testNeitherWriteToolIsClassifiedAsARead(): void
    {
        $map = new ToolPermissionMap();

        foreach (['deepl_translate_records' => ['table' => 'tl_page'], 'deepl_translate_page_tree' => []] as $tool => $args) {
            self::assertNotSame(
                'read',
                $map->requirement($tool, $args)['op'] ?? null,
                sprintf('%s writes records and must not be checked as a lookup.', $tool),
            );
        }
    }
}
