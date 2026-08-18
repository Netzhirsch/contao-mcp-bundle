<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\OAuth\Repository;

use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\OAuth\Repository\RefreshTokenRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Refresh-token rotation decides whether a connector keeps working quietly
 * or throws the user back into a browser login.
 *
 * Both directions cost something real. Accept a revoked token for too long
 * and a leaked one stays useful; reject it a moment too eagerly and an
 * honest client that merely retried loses its session — the failure mode
 * users actually report, because it looks like the server "randomly" wants
 * a new login.
 */
#[CoversClass(RefreshTokenRepository::class)]
final class RefreshTokenRepositoryTest extends TestCase
{
    /**
     * @param array<string, mixed>|false $row
     */
    private function repositoryReturning(array|false $row): RefreshTokenRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($row);

        return new RefreshTokenRepository($connection);
    }

    public function testALiveTokenIsNotRevoked(): void
    {
        $repo = $this->repositoryReturning(['is_revoked' => 0, 'tstamp' => time() - 86400]);

        self::assertFalse($repo->isRefreshTokenRevoked('live'));
    }

    /**
     * A token we have never seen — or that the cleanup command already
     * purged — must stay rejected. This is what makes revoking a client
     * take effect, so the grace window must not soften it.
     */
    public function testAnUnknownTokenCountsAsRevoked(): void
    {
        $repo = $this->repositoryReturning(false);

        self::assertTrue($repo->isRefreshTokenRevoked('never-issued'));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function rotationAges(): iterable
    {
        // seconds since the row was revoked → expected "is revoked"
        yield 'just rotated, client retried immediately' => [0, false];
        yield 'rotated half a minute ago' => [30, false];
        yield 'rotated 59 seconds ago' => [59, false];
        yield 'grace expired' => [61, true];
        yield 'rotated an hour ago' => [3600, true];
        yield 'revoked yesterday' => [86400, true];
    }

    #[DataProvider('rotationAges')]
    public function testRevokedTokensAreHonouredOnlyInsideTheGraceWindow(int $ageSeconds, bool $expected): void
    {
        $repo = $this->repositoryReturning([
            'is_revoked' => 1,
            'tstamp' => time() - $ageSeconds,
        ]);

        self::assertSame($expected, $repo->isRefreshTokenRevoked('rotated'));
    }
}
