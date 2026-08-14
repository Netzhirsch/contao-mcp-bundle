<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\UserModel;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Identity facade for MCP-driven write operations.
 *
 * Combines three responsibilities so individual Tools only have to pull in one
 * collaborator for "who am I writing as":
 *   - resolveUserId(): the tl_user.id to attribute changes to
 *   - getLogUsername(): the display string for tl_log + tl_version.username
 *   - getLogSource():   short marker so tl_log entries can be filtered by source
 *
 * Resolution prefers the McpCallContext identity (set by the OAuth auth-
 * validator) and falls back to the configured default_author_id, then the
 * lowest-id admin, then 0.
 */
final class AuthorResolver
{
    private const SOURCE_AUTHENTICATED = 'mcp_oauth';
    private const SOURCE_ANONYMOUS = 'mcp';

    private LoggerInterface $logger;

    /**
     * Per-process cache: tl_user.id → username. The daemon is long-running,
     * but the relevant set of users is tiny (one per OAuth client at most),
     * so an unbounded in-memory map is fine.
     *
     * @var array<int, ?string>
     */
    private array $usernameCache = [];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly McpCallContext $callContext,
        private readonly Connection $connection,
        private readonly ?int $defaultAuthorId,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Returns the tl_user.id to use as author for MCP write operations.
     *
     * Resolution order:
     *   1. OAuth-authenticated user (if their tl_user row still exists)
     *   2. explicit bundle-config netzhirsch_contao_mcp.write.default_author_id
     *   3. lowest-id admin user in tl_user
     *   4. 0 (no author)
     */
    public function resolve(): int
    {
        // 1. Honour the OAuth identity if it points at a real user.
        $oauthUserId = $this->callContext->getUserId();
        if ($oauthUserId !== null && $oauthUserId > 0) {
            if ($this->lookupUsername($oauthUserId) !== null) {
                return $oauthUserId;
            }
            // User row was deleted between auth and tool call — fall through
            // to default, but leave a breadcrumb so the operator notices.
            $this->logger->warning('MCP: OAuth-authenticated user no longer exists in tl_user — falling back to default author.', [
                'user_id' => $oauthUserId,
                'client_id' => $this->callContext->getClientId(),
            ]);
        }

        // 2. Bundle config.
        if ($this->defaultAuthorId !== null) {
            return $this->defaultAuthorId;
        }

        // 3. Lowest-id admin.
        $this->framework->initialize();
        $admin = UserModel::findOneBy(['admin = ?'], ['1'], ['order' => 'id ASC']);

        return $admin !== null ? (int) $admin->id : 0;
    }

    /**
     * Display string for tl_log.username and Versions::setUsername().
     *
     *   OAuth-authenticated:  "kalus (mcp:Claude Desktop)"
     *   no client name known: "kalus (mcp:<client_id>)"
     *   auth_mode=none / fall-back: "<default_username> (mcp)" — or just "(mcp)"
     *                                when there is no resolvable user at all.
     */
    public function getLogUsername(): string
    {
        $username = $this->lookupUsername($this->resolve());

        $marker = 'mcp';
        if ($this->callContext->isAuthenticated()) {
            $client = $this->callContext->getClientName()
                ?? $this->callContext->getClientId()
                ?? '';
            if ($client !== '') {
                $marker = 'mcp:'.$client;
            }
        }

        return ($username !== null ? $username : '').' ('.$marker.')';
    }

    /**
     * Marker for ContaoContext.source / tl_log.source so the backend log
     * filter can split MCP traffic from regular backend actions.
     */
    public function getLogSource(): string
    {
        return $this->callContext->isAuthenticated()
            ? self::SOURCE_AUTHENTICATED
            : self::SOURCE_ANONYMOUS;
    }

    public function isMcpAuthenticated(): bool
    {
        return $this->callContext->isAuthenticated();
    }

    /**
     * Returns the tl_user.username for the given id, or null if no such row.
     * Cached for the lifetime of the daemon process.
     */
    private function lookupUsername(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        if (\array_key_exists($userId, $this->usernameCache)) {
            return $this->usernameCache[$userId];
        }

        try {
            $username = $this->connection->fetchOne(
                'SELECT username FROM tl_user WHERE id = ?',
                [$userId],
            );
        } catch (\Throwable) {
            $username = false;
        }

        return $this->usernameCache[$userId] = ($username === false ? null : (string) $username);
    }
}
