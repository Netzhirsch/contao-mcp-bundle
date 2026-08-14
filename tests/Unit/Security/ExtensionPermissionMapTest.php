<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Security;

use Netzhirsch\ContaoMcpBundle\Extension\McpToolPermissionProviderInterface;
use Netzhirsch\ContaoMcpBundle\Security\ExtensionPermissionMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Collects the permission requirements extension tools declare, so the core
 * enforcer can apply parity to them. Wrong behaviour here is a security issue:
 * a missing declaration must fall back to admin-only (never silently allow),
 * and an extension must never be able to override a core tool's requirement.
 */
#[CoversClass(ExtensionPermissionMap::class)]
final class ExtensionPermissionMapTest extends TestCase
{
    public function testReturnsDeclaredRequirement(): void
    {
        $map = new ExtensionPermissionMap([
            self::provider(['acme_invoice_get' => ['kind' => 'dc', 'table' => 'tl_acme_invoice', 'op' => 'read']]),
        ]);

        self::assertSame(
            ['kind' => 'dc', 'table' => 'tl_acme_invoice', 'op' => 'read'],
            $map->baseRequirement('acme_invoice_get'),
        );
    }

    public function testUndeclaredToolReturnsNull(): void
    {
        $map = new ExtensionPermissionMap([
            self::provider(['acme_invoice_get' => ['kind' => 'none']]),
        ]);

        self::assertNull($map->baseRequirement('acme_unknown'));
    }

    public function testEmptyProvidersReturnsNull(): void
    {
        self::assertNull((new ExtensionPermissionMap())->baseRequirement('anything'));
    }

    public function testFirstDeclarationWinsOnCollision(): void
    {
        $map = new ExtensionPermissionMap([
            self::provider(['acme_dup' => ['kind' => 'none']]),
            self::provider(['acme_dup' => ['kind' => 'admin']]),
        ]);

        self::assertSame(['kind' => 'none'], $map->baseRequirement('acme_dup'));
    }

    public function testNonPermissionProvidersAreIgnored(): void
    {
        // A plain object (not implementing the permission interface) contributes
        // nothing — it stays admin-only via the enforcer's null fallback.
        $map = new ExtensionPermissionMap([new \stdClass()]);

        self::assertNull($map->baseRequirement('acme_anything'));
    }

    public function testInvalidEntriesAreSkipped(): void
    {
        $map = new ExtensionPermissionMap([
            self::provider([
                'acme_valid' => ['kind' => 'none'],
                'acme_bad_value' => 'not-an-array',
                42 => ['kind' => 'none'],
                '' => ['kind' => 'none'],
            ]),
        ]);

        self::assertSame(['kind' => 'none'], $map->baseRequirement('acme_valid'));
        self::assertNull($map->baseRequirement('acme_bad_value'));
    }

    /**
     * @param array<int|string, mixed> $permissions
     */
    private static function provider(array $permissions): McpToolPermissionProviderInterface
    {
        return new class($permissions) implements McpToolPermissionProviderInterface {
            /** @param array<int|string, mixed> $permissions */
            public function __construct(private readonly array $permissions)
            {
            }

            public function getMcpToolPermissions(): array
            {
                /** @var array<string, array<string, mixed>> */
                return $this->permissions;
            }
        };
    }
}
