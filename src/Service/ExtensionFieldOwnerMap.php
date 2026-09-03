<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Netzhirsch\ContaoMcpBundle\Extension\McpFieldOwnerProviderInterface;

/**
 * Collects the field→tool ownership that extension bundles declare via
 * {@see McpFieldOwnerProviderInterface}, so a refusal from the generic write
 * path can name the tool that does own the column.
 *
 * Built lazily from the `netzhirsch_mcp.tool`-tagged services, the same tag
 * {@see ExtensionPermissionMap} reads. Only providers implementing the
 * interface contribute; everyone else simply has no declared owner, and the
 * message falls back to "search for it with these words".
 *
 * First declaration wins on a collision — and the core's own map wins over all
 * of them, so an extension can never redirect a caller away from a core tool.
 */
final class ExtensionFieldOwnerMap
{
    /** @var array<string, array<string, string>>|null memoised "tl_table.field" → hints */
    private ?array $map = null;

    /**
     * @param iterable<object> $providers `netzhirsch_mcp.tool`-tagged services
     */
    public function __construct(private readonly iterable $providers = [])
    {
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function all(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $map = [];

        foreach ($this->providers as $provider) {
            if (!$provider instanceof McpFieldOwnerProviderInterface) {
                continue;
            }

            foreach ($provider->getMcpFieldOwners() as $key => $hints) {
                $key = (string) $key;

                // "tl_table.field" — anything else is a typo, and a typo that
                // silently became a key would show up as a message that never
                // appears, which is the hardest kind of bug to notice.
                if (isset($map[$key]) || preg_match('/^tl_[a-z0-9_]+\.[A-Za-z0-9_]+$/', $key) !== 1) {
                    continue;
                }

                $clean = [];
                foreach (['write', 'read'] as $kind) {
                    $hint = $hints[$kind] ?? null;
                    if (\is_string($hint) && trim($hint) !== '') {
                        $clean[$kind] = trim($hint);
                    }
                }

                if ($clean !== []) {
                    $map[$key] = $clean;
                }
            }
        }

        return $this->map = $map;
    }
}
