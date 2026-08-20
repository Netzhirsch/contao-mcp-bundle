<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Security;

use Netzhirsch\ContaoMcpBundle\Security\ToolPermissionMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A tool whose name does not END in a write verb must still be checked as a
 * write.
 *
 * `pages_create_tree` creates up to 200 pages and ends in "_tree", which the
 * suffix heuristic read as a lookup — a read-only backend user would have been
 * allowed to build a page tree. Guessing "read" for something that writes is
 * the one direction this must never fail in, so the check errs the other way.
 */
#[CoversClass(ToolPermissionMap::class)]
final class WriteVerbInferenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function toolNames(): iterable
    {
        yield 'suffix create' => ['page_create', 'create'];
        yield 'suffix update' => ['page_update', 'update'];
        yield 'suffix delete' => ['page_delete', 'delete'];
        yield 'verb in the middle' => ['pages_create_tree', 'create'];
        yield 'plain lookup stays read' => ['pages_tree', 'read'];
        yield 'list stays read' => ['pages_list', 'read'];
        yield 'get stays read' => ['page_get', 'read'];
        // "preview" contains no write verb; a substring match on the bare word
        // would be too eager, hence the underscore in the check.
        yield 'preview stays read' => ['page_preview', 'read'];
    }

    #[DataProvider('toolNames')]
    public function testTheOperationIsInferredFromTheName(string $tool, string $expected): void
    {
        $requirement = (new ToolPermissionMap())->requirement($tool, []);

        self::assertNotNull($requirement, "$tool resolved to no requirement at all.");
        self::assertSame($expected, $requirement['op'] ?? null, "$tool was classified as the wrong operation.");
    }

    /**
     * The explicit entry must win over the heuristic — it is what documents
     * the intent, the heuristic is only the safety net beneath it.
     */
    public function testTheTreeToolIsMappedToThePageTable(): void
    {
        $requirement = (new ToolPermissionMap())->requirement('pages_create_tree', []);

        self::assertSame('dc', $requirement['kind']);
        self::assertSame('tl_page', $requirement['table']);
    }
}
