<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\OAuth\Repository;

use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\OAuth\Repository\RefreshTokenRepository;
use Netzhirsch\ContaoMcpBundle\OAuth\TokenFamilyRevoker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Refresh-token rotation decides whether a connector keeps working quietly
 * or throws the user back into a browser login.
 *
 * Both directions cost something real. Accept a revoked token for too long
 * and a leaked one stays useful; reject it a moment too eagerly and an
 * honest client that merely retried loses its session — the failure mode
 * users actually report, because it looks like the server "randomly" wants
 * a new login.
 *
 * What the cascade then does to the rest of the session is tested against a
 * real database in {@see \Netzhirsch\ContaoMcpBundle\Tests\Integration\OAuth\TokenFamilyRevokerTest},
 * because that part is SQL across two tables.
 */
#[CoversClass(RefreshTokenRepository::class)]
final class RefreshTokenRepositoryTest extends TestCase
{
    /**
     * @param array<string, mixed>|false $row   the refresh-token row
     * @param array<string, mixed>|false $owner the access-token row the reuse path looks up
     */
    private function repositoryReturning(
        array|false $row,
        array|false $owner = false,
        ?\Throwable $cascadeFails = null,
    ): RefreshTokenRepository {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnOnConsecutiveCalls($row, $owner);

        if ($cascadeFails !== null) {
            $connection->method('executeStatement')->willThrowException($cascadeFails);
        }

        return new RefreshTokenRepository(
            $connection,
            new TokenFamilyRevoker($connection, new NullLogger()),
            new NullLogger(),
        );
    }

    public function testALiveTokenIsNotRevoked(): void
    {
        $repo = $this->repositoryReturning(['is_revoked' => 0, 'tstamp' => time() - 86400, 'access_token_identifier' => 'at']);

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
        $repo = $this->repositoryReturning(
            ['is_revoked' => 1, 'tstamp' => time() - $ageSeconds, 'access_token_identifier' => 'at'],
            ['client_id' => 'c', 'user_id' => 1],
        );

        self::assertSame($expected, $repo->isRefreshTokenRevoked('rotated'));
    }

    /**
     * The cleanup command deletes revoked access tokens, so the owner lookup
     * can come back empty. The token still has to be rejected — losing the
     * trail is not a reason to let it through.
     */
    public function testAReplayWhoseOwnerIsGoneIsStillRejected(): void
    {
        $repo = $this->repositoryReturning(
            ['is_revoked' => 1, 'tstamp' => time() - 3600, 'access_token_identifier' => 'purged'],
            false,
        );

        self::assertTrue($repo->isRefreshTokenRevoked('replayed'));
    }

    /**
     * This runs inside the token endpoint. The decision that matters — reject —
     * is already made by the time the cascade runs, so a database problem
     * during cleanup must not turn a clean rejection into a 500.
     */
    public function testACascadeFailureDoesNotEscape(): void
    {
        $repo = $this->repositoryReturning(
            ['is_revoked' => 1, 'tstamp' => time() - 3600, 'access_token_identifier' => 'at-1'],
            ['client_id' => 'c', 'user_id' => 1],
            new \RuntimeException('deadlock'),
        );

        self::assertTrue($repo->isRefreshTokenRevoked('replayed'));
    }
}
