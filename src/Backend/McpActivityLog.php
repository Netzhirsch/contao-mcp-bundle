<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Backend;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Reads recent MCP-attributed entries from tl_log for the Backend module
 * "MCP Activity" panel.
 *
 * Tools that mutate Contao state call System::log() (or have their action
 * logged via {@see \Netzhirsch\ContaoMcpBundle\Service\AuthorResolver}'s
 * ContaoContext.source = 'mcp' / 'mcp_oauth'). That gives operators a
 * single place — the Backend module page — to see "what did the AI do
 * to my Contao instance today" without having to know about tl_log,
 * unfilter the global log, or pop open the database.
 *
 * Two query modes:
 *   - recent(int $limit)          — last N entries across all MCP traffic
 *   - recentForClient($name, $n)  — last N entries by display name
 *
 * Both filter on `source LIKE 'mcp%'` so we catch BOTH 'mcp' (anonymous /
 * shared-secret) AND 'mcp_oauth' (per-user attributed) without having to
 * list source values explicitly. If a future auth mode adds another
 * source value with the mcp* prefix, it'll show up here automatically.
 *
 * Read-only — no writes, no mutations, safe to call from anywhere.
 */
final class McpActivityLog
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Returns the most-recent MCP-attributed log entries, newest first.
     *
     * @return list<array{id: int, tstamp: int, source: string, action: string, username: string, text: string}>
     */
    public function recent(int $limit = 100): array
    {
        $limit = max(1, min($limit, 1000));

        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->connection->fetchAllAssociative(
                "SELECT id, tstamp, source, action, username, text
                 FROM tl_log
                 WHERE source LIKE 'mcp%'
                 ORDER BY tstamp DESC, id DESC
                 LIMIT ".$limit
            );
        } catch (\Throwable $e) {
            // tl_log may not exist in a brand-new install before the first
            // migration — the Backend module should still render its other
            // panels. Logging is intentionally info-level: an empty activity
            // panel during the first 30 seconds of a new install is normal,
            // not a problem worth flagging on the error channel.
            $this->logger->info('MCP activity log query failed (tl_log missing?).', [
                'reason' => $e->getMessage(),
            ]);
            return [];
        }

        $normalised = [];
        foreach ($rows as $row) {
            $normalised[] = [
                'id' => (int) $row['id'],
                'tstamp' => (int) $row['tstamp'],
                'source' => (string) ($row['source'] ?? ''),
                'action' => (string) ($row['action'] ?? ''),
                'username' => (string) ($row['username'] ?? ''),
                'text' => (string) ($row['text'] ?? ''),
            ];
        }
        return $normalised;
    }
}
