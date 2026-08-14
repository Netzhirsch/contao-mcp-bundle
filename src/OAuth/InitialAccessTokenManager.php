<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth;

use Doctrine\DBAL\Connection;

/**
 * Manages Initial Access Tokens (RFC 7591 §3) — single-use credentials
 * a Backend admin generates so a client can register at /_mcp_oauth/register
 * without the endpoint being open to the public internet.
 *
 * Lifecycle:
 *   generate()  → plain shown ONCE, hash stored
 *   redeem()    → atomic compare-and-mark; second use returns false
 *   purgeExpired() → housekeeping, called from contao:mcp:oauth:cleanup
 */
final class InitialAccessTokenManager
{
    private const PREFIX = 'iat_';

    public function __construct(
        private readonly Connection $connection,
        /**
         * App-secret used as the HMAC pepper. Plain sha256($token) would
         * be vulnerable to a rainbow-table attack if the `tl_mcp_oauth_iat`
         * table ever leaked — an attacker who knew the iat_ prefix could
         * pre-compute hashes for every 24-byte hex string in their budget.
         *
         * HMAC-SHA256 with the application secret as the key forces an
         * attacker to *also* hold the secret, which lives in `.env.local`
         * and never touches the DB. Wired to `%kernel.secret%` in
         * services.yaml — that is Symfony's standard "secret" parameter
         * sourced from `APP_SECRET` env.
         *
         * Rotating APP_SECRET invalidates every outstanding IAT
         * (they'd hash differently). Acceptable: IATs are single-use,
         * max 1h TTL — at worst an operator regenerates one.
         */
        private readonly string $appSecret,
    ) {
    }

    /**
     * @return array{plain: string, expires_at: int}
     */
    public function generate(int $createdByUserId, int $ttlSeconds = 3600): array
    {
        $plain = self::PREFIX.bin2hex(random_bytes(24));
        $hash = $this->hash($plain);
        $now = time();
        $expiresAt = $now + max(60, $ttlSeconds);

        $this->connection->insert('tl_mcp_oauth_iat', [
            'tstamp' => $now,
            'token_hash' => $hash,
            'created_by_user_id' => $createdByUserId,
            'created_at' => $now,
            'expires_at' => $expiresAt,
            'used_at' => 0,
            'redeemed_by_client_id' => '',
        ]);

        return ['plain' => $plain, 'expires_at' => $expiresAt];
    }

    /**
     * Atomically marks the token as used. Returns true on success (caller
     * may proceed), false if missing / expired / already used.
     */
    public function redeem(string $plain, string $clientId): bool
    {
        $hash = $this->hash($plain);
        $now = time();

        // UPDATE ... WHERE used_at=0 AND expires_at>now → affected_rows=1
        // is the atomic check. Race-safe vs concurrent /register requests.
        $affected = $this->connection->executeStatement(
            'UPDATE tl_mcp_oauth_iat
             SET used_at = ?, redeemed_by_client_id = ?, tstamp = ?
             WHERE token_hash = ? AND used_at = 0 AND expires_at > ?',
            [$now, $clientId, $now, $hash, $now],
        );

        return $affected === 1;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, created_by_user_id, created_at, expires_at, used_at, redeemed_by_client_id
             FROM tl_mcp_oauth_iat
             ORDER BY created_at DESC',
        );
    }

    public function purgeExpired(): int
    {
        // Drop tokens that expired more than a day ago OR have been used
        // and are more than 30 days old.
        $cutoff = time() - 86400;
        $usedCutoff = time() - 30 * 86400;

        return $this->connection->executeStatement(
            'DELETE FROM tl_mcp_oauth_iat
             WHERE (used_at = 0 AND expires_at < ?)
                OR (used_at > 0 AND used_at < ?)',
            [$cutoff, $usedCutoff],
        );
    }

    /**
     * HMAC-SHA256 with the app-secret as key. Bound to a non-static method
     * because $this->appSecret is per-instance. Constant-time comparison
     * is not needed at the hash() call site — the security boundary is
     * the WHERE-clause in `redeem()`, which compares the precomputed hash
     * against the indexed `token_hash` column. SQL equality is naturally
     * constant-time relative to the user-controlled plain input (it
     * compares two server-controlled byte strings of identical length).
     */
    private function hash(string $plain): string
    {
        return hash_hmac('sha256', $plain, $this->appSecret);
    }
}
