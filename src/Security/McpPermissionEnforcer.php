<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Security;

/**
 * Single entry point that turns a tool call (name + arguments) into a
 * permission decision, by resolving the requirement via {@see ToolPermissionMap}
 * and asking {@see McpPermissionGuard}.
 *
 * Called from TWO places so there is no bypass:
 *   1. {@see \Netzhirsch\ContaoMcpBundle\Controller\McpController} for every
 *      direct `tools/call`.
 *   2. The `contao_call` Discovery tool, which proxies to hidden tools — it
 *      must re-check the PROXIED tool, otherwise lazy-mode would be a hole.
 *
 * Returns null when allowed, or a structured `{error, message}` array when
 * denied (handed straight back to the client as the tool result).
 */
final class McpPermissionEnforcer
{
    public function __construct(
        private readonly ToolPermissionMap $map,
        private readonly McpPermissionGuard $guard,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{error: string, message: string}|null
     */
    public function check(string $tool, array $args): ?array
    {
        $req = $this->map->requirement($tool, $args);

        if ($req === null) {
            // Unknown / unmapped tool → secure default: admins only.
            return $this->guard->ensureAdmin(
                \sprintf('The tool "%s" is not permission-mapped and is restricted to administrators.', $tool),
            );
        }

        return match ($req['kind']) {
            'none', 'proxy' => null,
            'admin' => $this->guard->ensureAdmin(
                \sprintf('The tool "%s" requires administrator rights.', $tool),
            ),
            'module' => $this->guard->ensureModule((string) $req['module']),
            'dc' => $this->guard->ensureCan(
                (string) $req['table'],
                (string) $req['op'],
                isset($req['id']) ? (int) $req['id'] : null,
                \is_array($req['fields'] ?? null) ? $req['fields'] : null,
            ),
            default => $this->guard->ensureAdmin(
                \sprintf('The tool "%s" has an unknown permission requirement and is restricted to administrators.', $tool),
            ),
        };
    }

    /**
     * Coarse, record-free visibility check for tools/list + discovery: would
     * this tool be at all usable by the current caller? Uses module/admin
     * level only (no record id at list time). Row-/field-level stays enforced
     * at call time. Trusted mode (no user) → everything visible.
     */
    public function isToolVisible(string $tool): bool
    {
        $req = $this->map->requirement($tool, []);

        if ($req === null) {
            return $this->guard->isAdminOrTrusted(); // unmapped → admin-only
        }

        return match ($req['kind']) {
            'none', 'proxy' => true,
            'admin' => $this->guard->isAdminOrTrusted(),
            'module' => $this->guard->ensureModule((string) $req['module']) === null,
            'dc' => $this->guard->canAccessTableModule((string) $req['table']),
            default => $this->guard->isAdminOrTrusted(),
        };
    }
}
