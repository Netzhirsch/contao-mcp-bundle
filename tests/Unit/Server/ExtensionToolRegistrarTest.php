<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Server;

use Netzhirsch\ContaoMcpBundle\Server\ExtensionToolRegistrar;
use Netzhirsch\ContaoMcpBundle\Tests\Fixtures\Extension\GreetingTool;
use PhpMcp\Server\Registry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * End-to-end proof of the registration glue against a REAL php-mcp Registry
 * (cheaply constructible from just a logger). Covers the security-relevant
 * behaviour the gate test can't: that enabling actually registers a callable
 * tool, that disabling actually keeps it out, and that a collision is
 * skipped.
 */
#[CoversClass(ExtensionToolRegistrar::class)]
final class ExtensionToolRegistrarTest extends TestCase
{
    private function registrar(): ExtensionToolRegistrar
    {
        return new ExtensionToolRegistrar(new NullLogger());
    }

    public function testEnabledToolIsRegisteredWithDerivedSchema(): void
    {
        $registry = new Registry(new NullLogger());

        $registered = $this->registrar()->register($registry, ['acme_greet'], [GreetingTool::class]);

        self::assertSame(['acme_greet'], $registered);
        self::assertNotNull($registry->getTool('acme_greet'), 'Enabled tool must be in the registry.');

        // Schema was derived from the method signature: greet(string $name).
        $schema = $registry->getTool('acme_greet')?->schema;
        self::assertNotNull($schema);
        self::assertArrayHasKey('name', $schema->inputSchema['properties'] ?? []);
    }

    public function testOnlyAnnotatedMethodsAreRegistered(): void
    {
        $registry = new Registry(new NullLogger());

        $this->registrar()->register($registry, ['acme_greet'], [GreetingTool::class]);

        // GreetingTool also has public methods notATool()/publicRequireConfirmation()/
        // publicResolveAuthorId() without #[McpTool] — none must register.
        self::assertSame(['acme_greet'], array_keys($registry->getTools()));
    }

    public function testDisabledToolIsNotRegistered(): void
    {
        $registry = new Registry(new NullLogger());

        $registered = $this->registrar()->register($registry, [], [GreetingTool::class]);

        self::assertSame([], $registered);
        self::assertNull($registry->getTool('acme_greet'), 'Default-off: a tool not on the allowlist must NOT register.');
        self::assertSame([], array_keys($registry->getTools()));
    }

    public function testNameCollisionIsSkipped(): void
    {
        $registry = new Registry(new NullLogger());

        // First registration takes the name.
        $first = $this->registrar()->register($registry, ['acme_greet'], [GreetingTool::class]);
        self::assertSame(['acme_greet'], $first);

        // Second pass: name is already taken → skipped, nothing new.
        $second = $this->registrar()->register($registry, ['acme_greet'], [GreetingTool::class]);
        self::assertSame([], $second, 'A taken name must not be re-registered (core/earlier wins).');
        self::assertCount(1, $registry->getTools());
    }

    public function testNoProviderClassesIsNoOp(): void
    {
        $registry = new Registry(new NullLogger());

        $registered = $this->registrar()->register($registry, ['acme_greet'], []);

        self::assertSame([], $registered);
        self::assertSame([], array_keys($registry->getTools()));
    }

    public function testMissingClassIsSkippedGracefully(): void
    {
        $registry = new Registry(new NullLogger());

        // A stale FQCN (bundle uninstalled but config not cleaned) must not
        // fatal the whole server boot.
        $registered = $this->registrar()->register(
            $registry,
            ['acme_greet'],
            ['Acme\\Gone\\NoSuchTool'],
        );

        self::assertSame([], $registered);
    }
}
