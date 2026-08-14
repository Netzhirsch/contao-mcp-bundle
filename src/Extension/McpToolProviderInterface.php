<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Extension;

/**
 * Marker interface for third-party MCP tool providers.
 *
 * A Contao bundle that wants to contribute additional MCP tools implements
 * this interface on each tool class. With Symfony autoconfiguration enabled
 * (the default in a modern bundle `services.yaml`), implementing classes are
 * automatically tagged `netzhirsch_mcp.tool` and picked up by the MCP server
 * — no manual tag, no manual scan-dir wiring.
 *
 * The interface itself is intentionally empty: the contract is expressed by
 * the `#[McpTool]` attributes on the class's public methods (exactly like the
 * core tools), not by methods on this interface. Implement
 * {@see AbstractMcpTool} instead of this interface directly unless you have a
 * reason not to — the abstract base gives you identity/attribution, deadlock
 * retry, and the destructive-action gate for free.
 *
 * SECURITY: implementing this interface does NOT make your tools callable.
 * Every extension tool is disabled by default and must be explicitly enabled
 * by the site operator via the `extension_tools_enabled` allowlist in
 * `var/mcp/config.json`. See EXTENDING.md for the full security model.
 */
interface McpToolProviderInterface
{
}
