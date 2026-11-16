<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Server;

use Doctrine\DBAL\Connection;
use Mcp\Exception\SessionStoreException;
use Netzhirsch\ContaoMcpBundle\Server\DatabaseSessionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * The store is what lets a session opened on one PHP worker (or one node)
 * be found by the next request on another. What matters beyond the SQL: an
 * unreachable database must not read as "no such session" — the client would
 * throw its session away and start over for nothing.
 */
#[CoversClass(DatabaseSessionStore::class)]
final class DatabaseSessionStoreTest extends TestCase
{
    public function testAnExistingSessionIsFound(): void
    {
        $id = Uuid::v4();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with(self::stringContains('expires_at > ?'), self::callback(static fn (array $p): bool => $p[0] === $id->toRfc4122()))
            ->willReturn(1);

        self::assertTrue((new DatabaseSessionStore($connection, new NullLogger()))->exists($id));
    }

    public function testAMissingSessionIsNotFound(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        self::assertFalse((new DatabaseSessionStore($connection, new NullLogger()))->exists(Uuid::v4()));
    }

    public function testAnUnreachableDatabaseIsNotANotFound(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('gone away'));

        $this->expectException(SessionStoreException::class);

        (new DatabaseSessionStore($connection, new NullLogger()))->exists(Uuid::v4());
    }

    public function testReadReturnsTheStoredDataOrFalse(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls('{"a":1}', false);
        $store = new DatabaseSessionStore($connection, new NullLogger());

        self::assertSame('{"a":1}', $store->read(Uuid::v4()));
        self::assertFalse($store->read(Uuid::v4()));
    }

    public function testWriteMovesTheExpiryForward(): void
    {
        $id = Uuid::v4();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('ON DUPLICATE KEY UPDATE'),
                self::callback(static fn (array $p): bool => $p[1] === $id->toRfc4122()
                    && $p[2] === '{}'
                    && $p[3] >= time() + 3600 - 5
                    && $p[3] <= time() + 3600),
            )
            ->willReturn(1);

        self::assertTrue((new DatabaseSessionStore($connection, new NullLogger(), 3600))->write($id, '{}'));
    }

    public function testAFailedWriteReportsFalse(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('read only'));

        self::assertFalse((new DatabaseSessionStore($connection, new NullLogger()))->write(Uuid::v4(), '{}'));
    }

    public function testGcReturnsTheExpiredIds(): void
    {
        $expired = Uuid::v4();
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([$expired->toRfc4122(), 'not-a-uuid']);
        $connection->expects(self::once())->method('executeStatement')->with(self::stringContains('DELETE'));

        $ids = (new DatabaseSessionStore($connection, new NullLogger()))->gc();

        self::assertCount(1, $ids);
        self::assertTrue($ids[0]->equals($expired));
    }

    public function testGcWithNothingExpiredDeletesNothing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([]);
        $connection->expects(self::never())->method('executeStatement');

        self::assertSame([], (new DatabaseSessionStore($connection, new NullLogger()))->gc());
    }
}
