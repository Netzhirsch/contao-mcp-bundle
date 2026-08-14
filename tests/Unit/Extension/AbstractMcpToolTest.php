<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Extension;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Extension\AbstractMcpTool;
use Netzhirsch\ContaoMcpBundle\Security\BackendUserContext;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\DbalRetry;
use Netzhirsch\ContaoMcpBundle\Service\McpCallContext;
use Netzhirsch\ContaoMcpBundle\Tests\Fixtures\Extension\GreetingTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Covers the shared helpers third-party tools inherit from AbstractMcpTool.
 * Uses the GreetingTool fixture, which exposes the protected helpers via thin
 * public proxies.
 *
 * AuthorResolver and DbalRetry are `final` (not mockable), so we construct
 * real instances — AuthorResolver with mocked framework/connection (never
 * touched on the default-author path) and a real DbalRetry over a NullLogger.
 */
#[CoversClass(AbstractMcpTool::class)]
final class AbstractMcpToolTest extends TestCase
{
    public function testRequireConfirmationReturnsNullWhenConfirmed(): void
    {
        self::assertNull($this->wiredTool()->publicRequireConfirmation(true));
    }

    public function testRequireConfirmationReturnsErrorWhenNotConfirmed(): void
    {
        $err = $this->wiredTool()->publicRequireConfirmation(false);

        self::assertIsArray($err);
        self::assertSame('destructive_confirmation_required', $err['error']);
        self::assertArrayHasKey('message', $err);
    }

    public function testResolveAuthorIdDelegatesToAuthorResolver(): void
    {
        // Empty call context + configured default author → resolve() returns
        // the default (7) without ever touching framework/connection.
        $tool = $this->wiredTool(defaultAuthorId: 7);
        self::assertSame(7, $tool->publicResolveAuthorId());
    }

    public function testHelpersThrowWhenServicesNotWired(): void
    {
        // A tool instantiated without the container (so the #[Required]
        // setter never ran) must fail loudly, not silently misbehave.
        $tool = new GreetingTool();

        $this->expectException(\LogicException::class);
        $tool->publicResolveAuthorId();
    }

    public function testPermissionGuardIsExposedToExtensionTools(): void
    {
        // Extension tools must reach the parity guard to filter list results.
        self::assertInstanceOf(McpPermissionGuard::class, $this->wiredTool()->publicPermissionGuard());
    }

    public function testPermissionGuardThrowsWhenNotWired(): void
    {
        $this->expectException(\LogicException::class);
        (new GreetingTool())->publicPermissionGuard();
    }

    public function testToolMethodWorksIndependentlyOfInjection(): void
    {
        // The actual #[McpTool] method doesn't touch the injected services,
        // so it works even on a bare instance — proves the helpers are
        // opt-in, not mandatory plumbing for every method.
        self::assertSame(['greeting' => 'Hello, Ada'], (new GreetingTool())->greet('Ada'));
    }

    private function wiredTool(int $defaultAuthorId = 1): GreetingTool
    {
        $context = new McpCallContext();
        $authorResolver = new AuthorResolver(
            $this->createMock(ContaoFramework::class),
            $context,
            $this->createMock(Connection::class),
            $defaultAuthorId,
            new NullLogger(),
        );

        $tool = new GreetingTool();
        $tool->setMcpToolServices($context, $authorResolver, new DbalRetry(new NullLogger()), $this->makeGuard($context));

        return $tool;
    }

    /**
     * A real McpPermissionGuard over mocked collaborators (it is `final`, so it
     * can't be mocked directly). Never exercised by these tests — they only
     * assert it is wired through and reachable.
     */
    private function makeGuard(McpCallContext $context): McpPermissionGuard
    {
        return new McpPermissionGuard(
            $context,
            new BackendUserContext($this->createMock(UserProviderInterface::class), $this->createMock(Connection::class)),
            $this->createMock(AccessDecisionManagerInterface::class),
            $this->createMock(Connection::class),
            $this->createMock(ContaoFramework::class),
            new NullLogger(),
        );
    }
}
