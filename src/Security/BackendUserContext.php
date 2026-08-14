<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Resolves the Contao BackendUser + a Symfony security token for the
 * OAuth-authenticated MCP caller, so the bundle can ask Contao's OWN security
 * voters whether a given operation is allowed (true backend-permission parity).
 *
 * Loading via the Contao backend user provider runs the user through
 * {@see \Contao\User::loadUserBy()} which calls `setUserFromDb()` — i.e. group
 * permissions (modules, pagemounts, filemounts, alexf, cud, …) are merged into
 * the user object exactly as on a real backend login. The resulting token
 * carries `ROLE_ADMIN` for admins (see {@see \Contao\BackendUser::getRoles()}),
 * so Contao's voters short-circuit to "allowed" for admins automatically.
 *
 * Everything is memoised per user-id for the lifetime of the PHP-FPM worker's
 * single MCP request (the call context is reset per request).
 */
final class BackendUserContext
{
    private const FIREWALL = 'contao_backend';

    /** @var array<int, TokenInterface|null> */
    private array $tokenCache = [];

    /** @var array<int, bool> */
    private array $mcpAccessCache = [];

    public function __construct(
        private readonly UserProviderInterface $backendUserProvider,
        private readonly Connection $connection,
    ) {
    }

    /**
     * A security token wrapping the fully-resolved BackendUser for the given
     * tl_user id, or null if the user no longer exists. Used as the principal
     * for AccessDecisionManager voting.
     */
    public function tokenFor(int $userId): ?TokenInterface
    {
        if (\array_key_exists($userId, $this->tokenCache)) {
            return $this->tokenCache[$userId];
        }

        $username = $this->usernameFor($userId);
        if ($username === null) {
            return $this->tokenCache[$userId] = null;
        }

        try {
            $user = $this->backendUserProvider->loadUserByIdentifier($username);
        } catch (UserNotFoundException) {
            return $this->tokenCache[$userId] = null;
        }

        if (!$user instanceof UserInterface) {
            return $this->tokenCache[$userId] = null;
        }

        return $this->tokenCache[$userId] = new UsernamePasswordToken($user, self::FIREWALL, $user->getRoles());
    }

    /**
     * True if the user is a Contao admin — admins bypass every MCP permission
     * check (the coarse access gate and the per-operation voter checks alike).
     */
    public function isAdmin(int $userId): bool
    {
        $token = $this->tokenFor($userId);

        return $token !== null && \in_array('ROLE_ADMIN', $token->getRoleNames(), true);
    }

    /**
     * The coarse "may this user use the MCP server at all" gate
     * (tl_user.netzhirschMcpAccess OR any of their groups'
     * tl_user_group.netzhirschMcpAccess). Admins are always allowed.
     */
    public function hasMcpAccess(int $userId): bool
    {
        if (\array_key_exists($userId, $this->mcpAccessCache)) {
            return $this->mcpAccessCache[$userId];
        }

        if ($this->isAdmin($userId)) {
            return $this->mcpAccessCache[$userId] = true;
        }

        try {
            // `groups` is a MySQL reserved word — must be quoted.
            $row = $this->connection->fetchAssociative(
                'SELECT netzhirschMcpAccess, inherit, '.$this->connection->quoteIdentifier('groups').' FROM tl_user WHERE id = ?',
                [$userId],
            );
        } catch (\Throwable) {
            $row = false;
        }

        if ($row === false) {
            return $this->mcpAccessCache[$userId] = false;
        }

        // Mirror Contao's own inheritance for this flag (see BackendUser::
        // setUserFromDb): the OWN flag only counts when own permissions apply
        // (inherit != "group"); GROUP flags only count when group permissions
        // are inherited (inherit "group" or "extend"). So under "Nur
        // Gruppenrechte verwenden" the user's own checkbox is ignored — exactly
        // like every other permission field, which Contao also hides there.
        $inherit = (string) ($row['inherit'] ?? '');
        $ownApplies = $inherit !== 'group';
        $groupApplies = \in_array($inherit, ['group', 'extend'], true);

        if ($ownApplies && (int) ($row['netzhirschMcpAccess'] ?? 0) === 1) {
            return $this->mcpAccessCache[$userId] = true;
        }

        if (!$groupApplies) {
            return $this->mcpAccessCache[$userId] = false;
        }

        $groupIds = $this->normaliseGroupIds($row['groups'] ?? null);
        if ($groupIds === []) {
            return $this->mcpAccessCache[$userId] = false;
        }

        $placeholders = implode(',', array_fill(0, \count($groupIds), '?'));
        try {
            $count = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM tl_user_group WHERE id IN ($placeholders) AND netzhirschMcpAccess = 1 AND disable = 0",
                $groupIds,
            );
        } catch (\Throwable) {
            $count = 0;
        }

        return $this->mcpAccessCache[$userId] = $count > 0;
    }

    private function usernameFor(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        try {
            $username = $this->connection->fetchOne('SELECT username FROM tl_user WHERE id = ?', [$userId]);
        } catch (\Throwable) {
            return null;
        }

        return ($username === false || $username === null || $username === '') ? null : (string) $username;
    }

    /**
     * tl_user.groups is a serialized PHP array of group ids (string|int).
     *
     * @return list<int>
     */
    private function normaliseGroupIds(mixed $raw): array
    {
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        if (!\is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[$id] = true;
            }
        }

        return array_keys($out);
    }
}
