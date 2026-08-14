<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Fixtures\Extension;

use Netzhirsch\ContaoMcpBundle\Extension\AbstractMcpTool;
use Netzhirsch\ContaoMcpBundle\Extension\McpToolPermissionProviderInterface;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use PhpMcp\Server\Attributes\McpTool;

/**
 * Test fixture: a minimal third-party MCP tool provider. Used by the
 * extension-point unit tests to prove registration, the allowlist gate, and
 * the base-class helpers without depending on a real consumer bundle.
 *
 * Also opts into permission parity via {@see McpToolPermissionProviderInterface}
 * so the extension-permission tests have a real declaring provider.
 *
 * Lives under tests/Fixtures so it is NEVER shipped or registered in a
 * production container — only the test autoloader sees it.
 */
final class GreetingTool extends AbstractMcpTool implements McpToolPermissionProviderInterface
{
    public function getMcpToolPermissions(): array
    {
        // acme_greet is read-only meta — no backend record involved.
        return ['acme_greet' => ['kind' => 'none']];
    }

    /**
     * @return array{greeting: string}
     */
    #[McpTool(
        name: 'acme_greet',
        description: 'Returns a greeting for the given name.',
    )]
    public function greet(string $name): array
    {
        return ['greeting' => 'Hello, '.$name];
    }

    /**
     * A second public method WITHOUT #[McpTool] — must be ignored by the
     * registrar (proves we only register annotated methods).
     */
    public function notATool(): string
    {
        return 'ignore me';
    }

    /**
     * Exposes the destructive-gate helper for the base-class test.
     *
     * @return array{error: string, message: string}|null
     */
    public function publicRequireConfirmation(bool $confirm): ?array
    {
        return $this->requireConfirmation($confirm);
    }

    public function publicResolveAuthorId(): int
    {
        return $this->resolveAuthorId();
    }

    public function publicPermissionGuard(): McpPermissionGuard
    {
        return $this->permissionGuard();
    }
}
