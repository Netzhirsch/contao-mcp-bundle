<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service\Usage;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Service\Usage\ReferenceFieldMap;
use Netzhirsch\ContaoMcpBundle\Service\Usage\SchemaIndex;
use Netzhirsch\ContaoMcpBundle\Service\Usage\UsageTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Which columns can hold a reference to a template.
 *
 * Contao gives no metadata for this — a template selector is a plain `select`
 * whose options come from a callback — so the map goes by naming convention.
 * A convention is only safe while it is pinned down, which is what this does.
 */
#[CoversClass(ReferenceFieldMap::class)]
#[CoversClass(SchemaIndex::class)]
final class ReferenceFieldMapTest extends TestCase
{
    #[DataProvider('templateColumns')]
    public function testRecognisesTemplateSelectors(string $column): void
    {
        $fields = $this->columnsFor(['tl_demo' => [$column => 'varchar']]);

        self::assertSame([['field' => $column, 'encoding' => ReferenceFieldMap::ENC_TEMPLATE_NAME]], $fields['tl_demo'] ?? []);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function templateColumns(): iterable
    {
        // Every spelling core actually ships.
        yield 'customTpl' => ['customTpl'];
        yield 'template' => ['template'];
        yield 'navigationTpl' => ['navigationTpl'];
        yield 'memberTpl' => ['memberTpl'];
        yield 'searchTpl' => ['searchTpl'];
        yield 'galleryTpl' => ['galleryTpl'];
        yield 'new_tpl' => ['new_tpl'];
        yield 'rss_template' => ['rss_template'];
    }

    #[DataProvider('nonTemplateColumns')]
    public function testIgnoresEverythingElse(string $column, string $type): void
    {
        self::assertSame([], $this->columnsFor(['tl_demo' => [$column => $type]]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonTemplateColumns(): iterable
    {
        yield 'plain column' => ['headline', 'varchar'];
        yield 'similar prefix' => ['templateOfSomething', 'varchar'];
        yield 'id column' => ['id', 'int'];
        // A template name is text; an int column named "template" would be
        // something else entirely and must not be compared against a name.
        yield 'template as int' => ['template', 'int'];
    }

    public function testResultIsCachedAcrossCalls(): void
    {
        $connection = $this->connectionReturning(['tl_demo' => ['customTpl' => 'varchar']]);
        // Exactly one information_schema round-trip, however often we ask.
        $connection->expects(self::once())->method('fetchAllAssociative');

        $map = new ReferenceFieldMap(
            $this->createMock(ContaoFramework::class),
            new SchemaIndex($connection),
            new ArrayAdapter(),
        );

        $first = $map->columnsPointingAt(UsageTarget::TABLE_TEMPLATES, ['tl_demo']);

        self::assertSame($first, $map->columnsPointingAt(UsageTarget::TABLE_TEMPLATES, ['tl_demo']));
    }

    /**
     * Templates resolve without touching a single DCA — that is why a template
     * lookup costs milliseconds while a page lookup has to load them all.
     */
    public function testTemplateLookupNeverLoadsTheContaoFramework(): void
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects(self::never())->method('initialize');
        $framework->expects(self::never())->method('getAdapter');

        $map = new ReferenceFieldMap(
            $framework,
            new SchemaIndex($this->connectionReturning(['tl_demo' => ['customTpl' => 'varchar']])),
            new ArrayAdapter(),
        );

        $map->columnsPointingAt(UsageTarget::TABLE_TEMPLATES, ['tl_demo']);
    }

    /**
     * @param array<string, array<string, string>> $schema
     *
     * @return array<string, list<array{field: string, encoding: string}>>
     */
    private function columnsFor(array $schema): array
    {
        $map = new ReferenceFieldMap(
            $this->createMock(ContaoFramework::class),
            new SchemaIndex($this->connectionReturning($schema)),
            new ArrayAdapter(),
        );

        return $map->columnsPointingAt(UsageTarget::TABLE_TEMPLATES, array_keys($schema));
    }

    /**
     * @param array<string, array<string, string>> $schema table => column => type
     *
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function connectionReturning(array $schema): Connection
    {
        $rows = [];

        foreach ($schema as $table => $columns) {
            foreach ($columns as $column => $type) {
                $rows[] = ['TABLE_NAME' => $table, 'COLUMN_NAME' => $column, 'DATA_TYPE' => $type];
            }
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        return $connection;
    }
}
