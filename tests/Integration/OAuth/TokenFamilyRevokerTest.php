<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Integration\OAuth;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Netzhirsch\ContaoMcpBundle\OAuth\Repository\RefreshTokenRepository;
use Netzhirsch\ContaoMcpBundle\OAuth\TokenFamilyRevoker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The cascade that answers a replayed single-use credential, against a real
 * database — the interesting part is SQL across two tables, which a mock
 * cannot tell you anything about.
 *
 * Sqlite in-memory, schema mirrored from the DCA. If the production DDL
 * changes, these CREATE statements must follow.
 */
#[CoversClass(TokenFamilyRevoker::class)]
#[CoversClass(RefreshTokenRepository::class)]
final class TokenFamilyRevokerTest extends TestCase
{
    private Connection $connection;
    private TokenFamilyRevoker $revoker;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement(
            'CREATE TABLE tl_mcp_oauth_access_token (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tstamp INTEGER NOT NULL DEFAULT 0,
                identifier TEXT NOT NULL DEFAULT "",
                client_id TEXT NOT NULL DEFAULT "",
                user_id INTEGER NOT NULL DEFAULT 0,
                scopes TEXT NOT NULL DEFAULT "[]",
                expires_at INTEGER NOT NULL DEFAULT 0,
                is_revoked INTEGER NOT NULL DEFAULT 0
            )',
        );
        $this->connection->executeStatement(
            'CREATE TABLE tl_mcp_oauth_refresh_token (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tstamp INTEGER NOT NULL DEFAULT 0,
                identifier TEXT NOT NULL DEFAULT "",
                access_token_identifier TEXT NOT NULL DEFAULT "",
                expires_at INTEGER NOT NULL DEFAULT 0,
                is_revoked INTEGER NOT NULL DEFAULT 0
            )',
        );

        $this->revoker = new TokenFamilyRevoker($this->connection, new NullLogger());
    }

    private function seedSession(string $clientId, int $userId, string $suffix, int $issuedAt): void
    {
        $this->connection->insert('tl_mcp_oauth_access_token', [
            'tstamp' => $issuedAt,
            'identifier' => 'at-'.$suffix,
            'client_id' => $clientId,
            'user_id' => $userId,
            'expires_at' => time() + 3600,
            'is_revoked' => 0,
        ]);
        $this->connection->insert('tl_mcp_oauth_refresh_token', [
            'tstamp' => $issuedAt,
            'identifier' => 'rt-'.$suffix,
            'access_token_identifier' => 'at-'.$suffix,
            'expires_at' => time() + 86400,
            'is_revoked' => 0,
        ]);
    }

    private function isRevoked(string $table, string $identifier): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT is_revoked FROM $table WHERE identifier = ?",
            [$identifier],
        );
    }

    public function testEveryTokenOfThatClientAndUserIsRevoked(): void
    {
        $this->seedSession('connector-7', 42, 'a', time() - 7200);
        $this->seedSession('connector-7', 42, 'b', time() - 600);

        $counts = $this->revoker->revoke('connector-7', 42, TokenFamilyRevoker::TRIGGER_REFRESH_TOKEN_REUSE);

        self::assertSame(['access' => 2, 'refresh' => 2], $counts);
        self::assertTrue($this->isRevoked('tl_mcp_oauth_access_token', 'at-a'));
        self::assertTrue($this->isRevoked('tl_mcp_oauth_refresh_token', 'rt-b'));
    }

    /**
     * A user's other connector is a different client and a different
     * credential. Logging it out would punish a session nobody suspects.
     */
    public function testOtherClientsAndOtherUsersAreUntouched(): void
    {
        $this->seedSession('connector-7', 42, 'target', time() - 600);
        $this->seedSession('connector-9', 42, 'other-client', time() - 600);
        $this->seedSession('connector-7', 43, 'other-user', time() - 600);

        $this->revoker->revoke('connector-7', 42, TokenFamilyRevoker::TRIGGER_AUTH_CODE_REUSE);

        self::assertTrue($this->isRevoked('tl_mcp_oauth_refresh_token', 'rt-target'));
        self::assertFalse($this->isRevoked('tl_mcp_oauth_refresh_token', 'rt-other-client'));
        self::assertFalse($this->isRevoked('tl_mcp_oauth_refresh_token', 'rt-other-user'));
    }

    /**
     * The point of the whole exercise, and the one an earlier version got
     * wrong: RefreshTokenRepository honours a revoked token for 60 seconds
     * because that usually means "just rotated". A cascade revokes at exactly
     * that moment — so if it stamped the rows with the current time, every
     * token it had just killed would stay usable for another minute, and the
     * holder could rotate into a fresh, unrevoked one and keep the session.
     */
    public function testACascadedTokenIsRejectedImmediatelyDespiteTheGraceWindow(): void
    {
        $this->seedSession('connector-7', 42, 'fresh', time());

        $repository = new RefreshTokenRepository($this->connection, $this->revoker, new NullLogger());

        // Precondition: seconds old and live, so nothing else explains the result.
        self::assertFalse($repository->isRefreshTokenRevoked('rt-fresh'));

        $this->revoker->revoke('connector-7', 42, TokenFamilyRevoker::TRIGGER_AUTH_CODE_REUSE);

        self::assertTrue(
            $repository->isRefreshTokenRevoked('rt-fresh'),
            'a token killed by the cascade must not fall into the rotation grace window',
        );
    }

    /**
     * End to end through the repository: a token rotated away long ago is
     * replayed, and that alone takes the rest of the session down with it.
     */
    public function testReplayingARotatedTokenTakesDownTheRestOfTheSession(): void
    {
        $this->seedSession('connector-7', 42, 'old', time() - 7200);
        $this->seedSession('connector-7', 42, 'current', time() - 120);

        // The old one was rotated away two hours ago.
        $this->connection->update(
            'tl_mcp_oauth_refresh_token',
            ['is_revoked' => 1, 'tstamp' => time() - 7200],
            ['identifier' => 'rt-old'],
        );

        $repository = new RefreshTokenRepository($this->connection, $this->revoker, new NullLogger());

        self::assertTrue($repository->isRefreshTokenRevoked('rt-old'));
        self::assertTrue(
            $this->isRevoked('tl_mcp_oauth_refresh_token', 'rt-current'),
            'the session the thief could have pivoted to must be gone as well',
        );
        self::assertTrue($this->isRevoked('tl_mcp_oauth_access_token', 'at-current'));
    }

    /**
     * A retry inside the window is an accident, not an attack. Cascading there
     * would log the user out for the very network hiccup the window exists to
     * absorb — the grace window would then be worse than not having one.
     */
    public function testARetryInsideTheWindowLeavesTheSessionAlone(): void
    {
        $this->seedSession('connector-7', 42, 'rotated', time());
        $this->seedSession('connector-7', 42, 'current', time());

        // Just rotated away, a moment ago.
        $this->connection->update(
            'tl_mcp_oauth_refresh_token',
            ['is_revoked' => 1, 'tstamp' => time() - 5],
            ['identifier' => 'rt-rotated'],
        );

        $repository = new RefreshTokenRepository($this->connection, $this->revoker, new NullLogger());

        self::assertFalse($repository->isRefreshTokenRevoked('rt-rotated'), 'the retry itself must be honoured');
        self::assertFalse($this->isRevoked('tl_mcp_oauth_refresh_token', 'rt-current'));
        self::assertFalse($this->isRevoked('tl_mcp_oauth_access_token', 'at-current'));
    }

    public function testRevokingTwiceReportsNothingLeftToDo(): void
    {
        $this->seedSession('connector-7', 42, 'a', time() - 600);

        $this->revoker->revoke('connector-7', 42, TokenFamilyRevoker::TRIGGER_REFRESH_TOKEN_REUSE);
        $second = $this->revoker->revoke('connector-7', 42, TokenFamilyRevoker::TRIGGER_REFRESH_TOKEN_REUSE);

        self::assertSame(['access' => 0, 'refresh' => 0], $second);
    }
}
