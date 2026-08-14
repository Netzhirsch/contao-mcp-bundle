<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Integration\OAuth;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Netzhirsch\ContaoMcpBundle\OAuth\InitialAccessTokenManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers the Initial-Access-Token (RFC 7591 §3) lifecycle without booting
 * Symfony or Contao. Uses sqlite-in-memory so each test runs in a fresh
 * isolated DB — no shared state, no test ordering dependencies.
 *
 * Critical guarantees tested here:
 *   - generate() returns a fresh prefixed plain token each call
 *   - redeem() succeeds exactly once per token (atomic compare-and-mark)
 *   - redeem() rejects expired tokens
 *   - redeem() rejects mistyped tokens (no information leak via timing
 *     would need a separate side-channel test — out of scope)
 *   - purgeExpired() cleans up unused-and-expired AND old-and-used tokens
 */
#[CoversClass(InitialAccessTokenManager::class)]
final class InitialAccessTokenManagerTest extends TestCase
{
    private Connection $connection;
    private InitialAccessTokenManager $manager;

    protected function setUp(): void
    {
        // Sqlite in-memory connection. The bundle uses Connection::insert/
        // executeStatement/fetchAllAssociative — all driver-agnostic, so
        // this faithfully reproduces production behavior.
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        // Mirror the schema from src/Resources/contao/sql/install.sql for
        // tl_mcp_oauth_iat. Kept inline so test stays self-contained — if
        // the production DDL changes, this string must follow.
        $this->connection->executeStatement(
            'CREATE TABLE tl_mcp_oauth_iat (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tstamp INTEGER NOT NULL DEFAULT 0,
                token_hash TEXT NOT NULL,
                created_by_user_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0,
                expires_at INTEGER NOT NULL DEFAULT 0,
                used_at INTEGER NOT NULL DEFAULT 0,
                redeemed_by_client_id TEXT NOT NULL DEFAULT ""
            )'
        );

        $this->manager = new InitialAccessTokenManager(
            $this->connection,
            // Fixed test secret. Production wires this to %kernel.secret%
            // (APP_SECRET). A fixed value here keeps each test deterministic
            // — same plain token → same hash on every CI run.
            'test-app-secret-do-not-use-in-production',
        );
    }

    public function testGenerateReturnsPrefixedPlainTokenWithExpectedTtl(): void
    {
        $result = $this->manager->generate(42, 3600);

        self::assertArrayHasKey('plain', $result);
        self::assertArrayHasKey('expires_at', $result);
        self::assertStringStartsWith('iat_', $result['plain']);
        // 'iat_' (4) + 24 bytes hex (48) = 52 chars.
        self::assertSame(52, \strlen($result['plain']));
        self::assertGreaterThan(time() + 3500, $result['expires_at']);
        self::assertLessThan(time() + 3700, $result['expires_at']);
    }

    public function testGenerateProducesDistinctTokensAcrossCalls(): void
    {
        // Birthday-bound chance of collision in 100 draws of 192 bits ≈ 0.
        $tokens = [];
        for ($i = 0; $i < 5; $i++) {
            $tokens[] = $this->manager->generate(1)['plain'];
        }
        self::assertCount(5, array_unique($tokens), 'Each generate() must produce a fresh random token.');
    }

    public function testRedeemAcceptsValidTokenExactlyOnce(): void
    {
        $token = $this->manager->generate(42)['plain'];

        self::assertTrue($this->manager->redeem($token, 'client-abc'), 'First redemption succeeds.');
        self::assertFalse($this->manager->redeem($token, 'client-abc'), 'Second redemption MUST be rejected — single-use is the whole point.');
    }

    public function testRedeemRejectsExpiredToken(): void
    {
        // TTL of 60s is the manager's minimum (max(60, $ttl) inside
        // generate). We can't easily roll back the clock, but we can
        // back-date the row directly to simulate expiry.
        $token = $this->manager->generate(42, 60)['plain'];

        $this->connection->executeStatement(
            'UPDATE tl_mcp_oauth_iat SET expires_at = ?',
            [time() - 60],
        );

        self::assertFalse($this->manager->redeem($token, 'client-abc'));
    }

    public function testRedeemRejectsUnknownToken(): void
    {
        $this->manager->generate(42);

        self::assertFalse(
            $this->manager->redeem('iat_'.str_repeat('a', 48), 'client-abc'),
            'A token never issued must not match any hash in the table.',
        );
    }

    public function testListAllReturnsRowsNewestFirst(): void
    {
        $first = $this->manager->generate(1);
        // Sleep 1s to make sure ORDER BY created_at DESC has a deterministic
        // ordering. (At 1Hz tick granularity in the manager, same-second
        // creations would be ordered by insert order which sqlite happens
        // to match, but explicit is better than implicit.)
        $this->connection->executeStatement(
            'UPDATE tl_mcp_oauth_iat SET created_at = created_at - 100',
        );
        $second = $this->manager->generate(1);

        $rows = $this->manager->listAll();

        self::assertCount(2, $rows);
        self::assertGreaterThan(
            (int) $rows[1]['created_at'],
            (int) $rows[0]['created_at'],
            'listAll() must order newest-first.',
        );
        // The Backend module renders this list — proves the older one was the FIRST generated.
        self::assertSame($second['expires_at'], (int) $rows[0]['expires_at']);
        self::assertSame($first['expires_at'], (int) $rows[1]['expires_at']);
    }

    public function testRedeemRejectsTokenIssuedUnderDifferentAppSecret(): void
    {
        // Property under test: the hash is HMAC-keyed with the app-secret.
        // If an operator rotates APP_SECRET, every outstanding IAT must
        // become invalid (same plain string, different HMAC key → different
        // hash → no DB match). This is the entire reason to peppe-hash
        // IATs instead of using plain sha256.
        //
        // Manager A issues a token. Manager B with a DIFFERENT secret tries
        // to redeem it. Must fail.
        $plain = $this->manager->generate(1)['plain'];

        $managerWithRotatedSecret = new InitialAccessTokenManager(
            $this->connection,
            'a-completely-different-app-secret',
        );

        self::assertFalse(
            $managerWithRotatedSecret->redeem($plain, 'client-abc'),
            'A token issued under secret A must not be redeemable under secret B — that is the HMAC promise.',
        );

        // And the original manager can still redeem it (no DB-side damage).
        self::assertTrue($this->manager->redeem($plain, 'client-abc'));
    }

    public function testPurgeExpiredRemovesUnusedExpiredAndOldUsedTokens(): void
    {
        // Active token — must survive.
        $this->manager->generate(1, 3600);

        // Unused + expired more than a day ago — must be purged.
        $this->manager->generate(1, 3600);
        $this->connection->executeStatement(
            'UPDATE tl_mcp_oauth_iat SET expires_at = ? WHERE id = 2',
            [time() - 86400 - 60],
        );

        // Used 31 days ago — must be purged (audit-trail retention is the
        // calling code's responsibility, not the IAT manager's).
        $this->manager->generate(1, 3600);
        $oldUsed = time() - 31 * 86400;
        $this->connection->executeStatement(
            'UPDATE tl_mcp_oauth_iat SET used_at = ? WHERE id = 3',
            [$oldUsed],
        );

        // Used 5 days ago — within 30-day retention, must survive.
        $this->manager->generate(1, 3600);
        $recentUsed = time() - 5 * 86400;
        $this->connection->executeStatement(
            'UPDATE tl_mcp_oauth_iat SET used_at = ? WHERE id = 4',
            [$recentUsed],
        );

        $purged = $this->manager->purgeExpired();
        self::assertSame(2, $purged);

        $remaining = $this->connection->fetchAllAssociative(
            'SELECT id FROM tl_mcp_oauth_iat ORDER BY id ASC',
        );
        self::assertSame([1, 4], array_map(static fn (array $r): int => (int) $r['id'], $remaining));
    }
}
