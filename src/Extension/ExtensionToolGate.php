<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Extension;

/**
 * Pure decision logic for whether a third-party extension tool may be
 * registered into the MCP server's registry. Extracted from
 * {@see \Netzhirsch\ContaoMcpBundle\Server\HttpDispatcherFactory} so the
 * security-critical gating can be unit-tested in isolation, without booting
 * the php-mcp Server / Symfony container.
 *
 * Two independent gates, in order:
 *
 *   1. ALLOWLIST — an extension tool is only ever registered if the site
 *      operator has put its name into `extension_tools_enabled`
 *      (var/mcp/config.json). Default is an empty list, so a freshly
 *      `composer require`d tool bundle exposes NOTHING until the operator
 *      opts in. This is the core defense: third-party code does not become
 *      LLM-callable just by being installed.
 *
 *   2. COLLISION — a name already taken (by a core tool or an
 *      already-registered extension tool) wins. The extension tool is
 *      skipped. Core tools can therefore never be shadowed/hijacked by an
 *      extension, and two extensions cannot fight over a name
 *      non-deterministically.
 */
final class ExtensionToolGate
{
    public const REGISTER = 'register';
    public const SKIP_DISABLED = 'skip_disabled';
    public const SKIP_DUPLICATE = 'skip_duplicate';

    private function __construct()
    {
    }

    /**
     * @param string              $toolName the tool's `#[McpTool]` name
     * @param list<string>        $enabled  operator allowlist (extension_tools_enabled)
     * @param array<string, true> $taken    names already registered (core + earlier extensions)
     *
     * @return self::REGISTER|self::SKIP_DISABLED|self::SKIP_DUPLICATE
     */
    public static function decide(string $toolName, array $enabled, array $taken): string
    {
        if (!\in_array($toolName, $enabled, true)) {
            return self::SKIP_DISABLED;
        }
        if (isset($taken[$toolName])) {
            return self::SKIP_DUPLICATE;
        }

        return self::REGISTER;
    }
}
