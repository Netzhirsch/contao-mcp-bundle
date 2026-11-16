<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Server;

use Doctrine\DBAL\Connection;
use Mcp\Exception\SessionStoreException;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Handshake-era MCP sessions in the Contao database (`tl_mcp_session`).
 *
 * The SDK keeps sessions in process memory by default, which under PHP-FPM
 * means the next request — on another worker — finds none and the client is
 * told to start over. Files would survive that on one machine, but not behind
 * a load balancer with several nodes, which is exactly how some installations
 * run. The database is the store every node already shares.
 *
 * The lifetime slides: every write moves `expires_at` forward, and the SDK
 * writes the session on every request. Generous on purpose — a conversation
 * left open over a weekend should not come back to "session expired". A
 * client that does find its session gone gets a 404 and opens a new one,
 * which the specification requires it to handle.
 */
final class DatabaseSessionStore implements SessionStoreInterface
{
    private const TABLE = 'tl_mcp_session';

    /** Seven days. */
    public const DEFAULT_TTL = 604800;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly int $ttl = self::DEFAULT_TTL,
    ) {
    }

    public function exists(Uuid $id): bool
    {
        try {
            return false !== $this->connection->fetchOne(
                'SELECT 1 FROM '.self::TABLE.' WHERE session_id = ? AND expires_at > ?',
                [$id->toRfc4122(), time()],
            );
        } catch (\Throwable $e) {
            // Not "no such session": the client would start over for nothing.
            // The SDK answers 503 for this and the client retries.
            throw new SessionStoreException(\sprintf('Session store is unavailable: %s', $e->getMessage()), previous: $e);
        }
    }

    public function read(Uuid $id): string|false
    {
        try {
            $data = $this->connection->fetchOne(
                'SELECT data FROM '.self::TABLE.' WHERE session_id = ? AND expires_at > ?',
                [$id->toRfc4122(), time()],
            );
        } catch (\Throwable $e) {
            $this->logger->error('MCP session could not be read.', ['exception' => $e]);

            return false;
        }

        return \is_string($data) ? $data : false;
    }

    public function write(Uuid $id, string $data): bool
    {
        $now = time();

        try {
            // One statement, so two workers writing the same new session at
            // once cannot both try to insert it.
            $this->connection->executeStatement(
                'INSERT INTO '.self::TABLE.' (tstamp, session_id, data, expires_at) VALUES (?, ?, ?, ?)'
                .' ON DUPLICATE KEY UPDATE tstamp = VALUES(tstamp), data = VALUES(data), expires_at = VALUES(expires_at)',
                [$now, $id->toRfc4122(), $data, $now + $this->ttl],
            );
        } catch (\Throwable $e) {
            $this->logger->error('MCP session could not be written.', ['exception' => $e]);

            return false;
        }

        return true;
    }

    public function destroy(Uuid $id): bool
    {
        try {
            $this->connection->executeStatement('DELETE FROM '.self::TABLE.' WHERE session_id = ?', [$id->toRfc4122()]);
        } catch (\Throwable $e) {
            $this->logger->error('MCP session could not be deleted.', ['exception' => $e]);

            return false;
        }

        return true;
    }

    public function gc(): array
    {
        $now = time();

        try {
            $expired = $this->connection->fetchFirstColumn(
                'SELECT session_id FROM '.self::TABLE.' WHERE expires_at <= ?',
                [$now],
            );

            if ($expired === []) {
                return [];
            }

            $this->connection->executeStatement('DELETE FROM '.self::TABLE.' WHERE expires_at <= ?', [$now]);
        } catch (\Throwable $e) {
            $this->logger->warning('MCP session cleanup failed.', ['exception' => $e]);

            return [];
        }

        $ids = [];
        foreach ($expired as $value) {
            if (\is_string($value) && Uuid::isValid($value)) {
                $ids[] = Uuid::fromString($value);
            }
        }

        return $ids;
    }
}
