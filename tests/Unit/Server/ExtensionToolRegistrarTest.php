<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Server;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Registry;
use Netzhirsch\ContaoMcpBundle\Server\ExtensionToolRegistrar;
use Netzhirsch\ContaoMcpBundle\Tests\Fixtures\Extension\GreetingTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * End-to-end proof of the registration glue against a REAL SDK Registry.
 * Covers the security-relevant behaviour the gate test can't: that enabling
 * actually registers a callable tool, that disabling actually keeps it out,
 * and that a collision is skipped.
 *
 * {@see GreetingTool} still carries php-mcp's `#[McpTool]`, the attribute
 * EXTENDING.md documented up to 1.40 — so these tests also prove an extension
 * written back then keeps working. {@see SdkGreetingTool} is the current form.
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
        $registry = new Registry();

        $registered = $this->registrar()->register($registry, ['acme_greet'], [GreetingTool::class]);

        self::assertSame(['acme_greet'], $registered);
        self::assertTrue($registry->hasTool('acme_greet'), 'Enabled tool must be in the registry.');

        // Schema was derived from the method signature: greet(string $name).
        $tool = $registry->getTool('acme_greet')->tool;
        self::assertArrayHasKey('name', $tool->inputSchema['properties'] ?? []);
        self::assertSame('Returns a greeting for the given name.', $tool->description);
    }

    public function testTheSdkAttributeRegistersTheSameWay(): void
    {
        $registry = new Registry();

        $registered = $this->registrar()->register($registry, ['acme_shout'], [SdkGreetingTool::class]);

        self::assertSame(['acme_shout'], $registered);
        $tool = $registry->getTool('acme_shout')->tool;
        self::assertSame('Shout', $tool->title);
        self::assertSame('Shouts a greeting.', $tool->description);
        self::assertSame([SdkGreetingTool::class, 'shout'], $registry->getTool('acme_shout')->handler);
    }

    /**
     * contao-mia-bundle writes `annotations: new ToolAnnotations(…)` with the
     * php-mcp class. Without php-mcp that class is an alias; the hints must
     * arrive in the registered tool.
     */
    public function testLegacyAnnotationsSurviveTheMove(): void
    {
        $registry = new Registry();

        $registered = $this->registrar()->register($registry, ['acme_vehicles'], [LegacyAnnotatedTool::class]);

        self::assertSame(['acme_vehicles'], $registered);
        $annotations = $registry->getTool('acme_vehicles')->tool->annotations;
        self::assertNotNull($annotations);
        self::assertTrue($annotations->readOnlyHint);
        self::assertFalse($annotations->openWorldHint);
    }

    /**
     * An attribute naming a class that is not installed must cost its own
     * tool, not the server: the other tool of the same class still registers.
     */
    public function testAnUnreadableAttributeSkipsOnlyItsTool(): void
    {
        $registry = new Registry();
        $registrar = $this->registrar();

        $registered = $registrar->register($registry, ['acme_broken', 'acme_fine'], [HalfBrokenTool::class]);

        self::assertSame(['acme_fine'], $registered);
        self::assertSame(['acme_fine'], array_column($registrar->candidates([HalfBrokenTool::class]), 'name'));
    }

    public function testCandidatesListBothAttributeNamespaces(): void
    {
        $candidates = $this->registrar()->candidates([GreetingTool::class, SdkGreetingTool::class]);

        self::assertSame(['acme_greet', 'acme_shout'], array_column($candidates, 'name'));
        self::assertSame('Shouts a greeting.', $candidates[1]['description']);
    }

    public function testOnlyAnnotatedMethodsAreRegistered(): void
    {
        $registry = new Registry();

        $this->registrar()->register($registry, ['acme_greet'], [GreetingTool::class]);

        // GreetingTool also has public methods notATool()/publicRequireConfirmation()/
        // publicResolveAuthorId() without #[McpTool] — none must register.
        self::assertSame(['acme_greet'], array_keys($registry->getTools()->references));
    }

    public function testDisabledToolIsNotRegistered(): void
    {
        $registry = new Registry();

        $registered = $this->registrar()->register($registry, [], [GreetingTool::class]);

        self::assertSame([], $registered);
        self::assertFalse($registry->hasTool('acme_greet'), 'Default-off: a tool not on the allowlist must NOT register.');
        self::assertSame([], array_keys($registry->getTools()->references));
    }

    public function testNameCollisionIsSkipped(): void
    {
        $registry = new Registry();

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
        $registry = new Registry();

        $registered = $this->registrar()->register($registry, ['acme_greet'], []);

        self::assertSame([], $registered);
        self::assertSame([], array_keys($registry->getTools()->references));
    }

    public function testMissingClassIsSkippedGracefully(): void
    {
        $registry = new Registry();

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

/**
 * Written the way contao-mia-bundle writes its tools: php-mcp attribute and
 * php-mcp annotations class.
 */
final class LegacyAnnotatedTool
{
    /**
     * @return list<string>
     */
    #[\PhpMcp\Server\Attributes\McpTool(
        name: 'acme_vehicles',
        description: 'Lists vehicles.',
        annotations: new \PhpMcp\Schema\ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function list(): array
    {
        return [];
    }
}

/**
 * One tool whose attribute cannot be evaluated, one that is fine.
 */
final class HalfBrokenTool
{
    /**
     * @return list<string>
     */
    #[McpTool(name: 'acme_broken', description: 'Broken.', annotations: new \Acme\NotInstalled\Annotations())]
    public function broken(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    #[McpTool(name: 'acme_fine', description: 'Fine.')]
    public function fine(): array
    {
        return [];
    }
}

/**
 * A third-party tool provider written against the SDK attribute.
 */
final class SdkGreetingTool
{
    /**
     * @return array{greeting: string}
     */
    #[McpTool(name: 'acme_shout', title: 'Shout', description: 'Shouts a greeting.')]
    public function shout(string $name): array
    {
        return ['greeting' => strtoupper('Hello, '.$name)];
    }
}
