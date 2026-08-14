<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Security;

use Netzhirsch\ContaoMcpBundle\Extension\McpToolPermissionProviderInterface;

/**
 * Collects the permission requirements that third-party extension tools
 * declare via {@see McpToolPermissionProviderInterface}, so {@see ToolPermissionMap}
 * can enforce the same backend parity on them as on core tools.
 *
 * Built lazily from the `netzhirsch_mcp.tool`-tagged services (the same tag the
 * {@see \Netzhirsch\ContaoMcpBundle\DependencyInjection\Compiler\McpToolProviderPass}
 * collects). Only providers that implement the permission interface contribute;
 * the rest stay admin-only by the enforcer's secure default.
 *
 * First-declaration-wins on a name collision — combined with "core always wins"
 * in the map itself, an extension can never override a core tool's requirement.
 */
final class ExtensionPermissionMap
{
    /** @var array<string, array<string, mixed>>|null memoised tool → base requirement */
    private ?array $map = null;

    /**
     * @param iterable<object> $providers `netzhirsch_mcp.tool`-tagged services
     */
    public function __construct(private readonly iterable $providers = [])
    {
    }

    /**
     * The declared (pre-hydration) requirement for a tool, or null when no
     * extension declares it.
     *
     * @return array<string, mixed>|null
     */
    public function baseRequirement(string $tool): ?array
    {
        return $this->build()[$tool] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function build(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $map = [];
        foreach ($this->providers as $provider) {
            if (!$provider instanceof McpToolPermissionProviderInterface) {
                continue;
            }
            foreach ($provider->getMcpToolPermissions() as $tool => $req) {
                if (\is_string($tool) && $tool !== '' && \is_array($req) && !isset($map[$tool])) {
                    $map[$tool] = $req;
                }
            }
        }

        return $this->map = $map;
    }
}
