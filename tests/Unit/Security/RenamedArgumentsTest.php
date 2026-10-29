<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Security;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\BackendUserContext;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionEnforcer;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Security\ToolPermissionMap;
use Netzhirsch\ContaoMcpBundle\Service\McpCallContext;
use Netzhirsch\ContaoMcpBundle\Service\OptionalBundles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Field rights for arguments the tools name differently from their column,
 * from tool call to the guard's decision.
 *
 * The field check asks Contao about the names it is handed, and skips a
 * name the DCA does not know. `home_dir` is not a tl_member field, so a
 * restricted editor set a member's home directory without the right to
 * `homeDir`. A key inside `extras` was not even looked at. And `active`
 * writes `disable` the other way round: deactivating a member compared as
 * "unchanged" against the stored row and needed no right at all.
 *
 * The editor here may open every module and record and holds no field
 * right, so every field they really write must be refused.
 */
#[CoversClass(ToolPermissionMap::class)]
#[CoversClass(McpPermissionGuard::class)]
final class RenamedArgumentsTest extends TestCase
{
    private const TABLES = ['tl_member', 'tl_news'];

    /** @var array<string, mixed> */
    private array $savedDca = [];

    private mixed $savedModules = null;

    protected function setUp(): void
    {
        $this->savedModules = $GLOBALS['BE_MOD'] ?? null;
        $GLOBALS['BE_MOD'] = [];

        foreach (self::TABLES as $table) {
            $this->savedDca[$table] = $GLOBALS['TL_DCA'][$table] ?? null;
        }

        // Trimmed Contao 5.7 DCAs: every field with an input is excluded.
        $GLOBALS['TL_DCA']['tl_member'] = [
            'config' => ['dataContainer' => 'Contao\DC_Table'],
            'fields' => [
                'disable' => ['inputType' => 'checkbox', 'sql' => ['type' => 'boolean', 'default' => false]],
                'homeDir' => ['inputType' => 'fileTree', 'sql' => 'binary(16) NULL'],
            ],
        ];
        $GLOBALS['TL_DCA']['tl_news'] = [
            'config' => ['dataContainer' => 'Contao\DC_Table'],
            'fields' => [
                'pid' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
                'published' => ['inputType' => 'checkbox', 'sql' => ['type' => 'boolean', 'default' => false]],
            ],
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->savedDca as $table => $saved) {
            if ($saved === null) {
                unset($GLOBALS['TL_DCA'][$table]);
            } else {
                $GLOBALS['TL_DCA'][$table] = $saved;
            }
        }

        if ($this->savedModules === null) {
            unset($GLOBALS['BE_MOD']);
        } else {
            $GLOBALS['BE_MOD'] = $this->savedModules;
        }
    }

    public function testASnakeCaseKeyNeedsTheRightOfItsColumn(): void
    {
        $denied = $this->enforcer()->check('member_update', ['id' => 1, 'fields' => ['home_dir' => 'files/mitglied']]);

        self::assertSame('permission_denied', $denied['error'] ?? null);
        self::assertStringContainsString('"homeDir"', $denied['message'] ?? '');
    }

    public function testDeactivatingNeedsTheRightToDisable(): void
    {
        $denied = $this->enforcer()->check('member_update', ['id' => 1, 'fields' => ['active' => false]]);

        self::assertSame('permission_denied', $denied['error'] ?? null);
        self::assertStringContainsString('"disable"', $denied['message'] ?? '');
    }

    /**
     * The member is active; saying so again writes nothing and needs no
     * right, as in the backend.
     */
    public function testConfirmingTheStoredStateNeedsNoRight(): void
    {
        self::assertNull($this->enforcer()->check('member_update', ['id' => 1, 'fields' => ['active' => true]]));
    }

    public function testAKeyInExtrasIsAWrittenField(): void
    {
        $denied = $this->enforcer()->check('news_update', ['id' => 1, 'extras' => ['published' => true]]);

        self::assertSame('permission_denied', $denied['error'] ?? null);
        self::assertStringContainsString('"published"', $denied['message'] ?? '');
    }

    private function enforcer(): McpPermissionEnforcer
    {
        $call = new McpCallContext();
        $call->setIdentity(7, 'client', null);

        $provider = $this->createStub(UserProviderInterface::class);
        $provider->method('loadUserByIdentifier')->willReturn(new InMemoryUser('editor', null, ['ROLE_USER']));

        // The stored row: an active member, an unpublished news entry.
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('editor');
        $connection->method('fetchAssociative')->willReturn(['id' => 1, 'pid' => 4, 'disable' => '', 'homeDir' => null, 'published' => '']);
        $connection->method('quoteIdentifier')->willReturnArgument(0);

        $users = new BackendUserContext($provider, $connection, $this->createStub(UserCheckerInterface::class), new NullLogger());

        $decisions = $this->createStub(AccessDecisionManagerInterface::class);
        $decisions->method('decide')->willReturnCallback(
            static fn (TokenInterface $token, array $attributes, mixed $subject = null): bool => $attributes !== [ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE],
        );

        $controller = $this->createStub(Adapter::class);
        $controller->method('__call')->willReturn(null);

        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(
            static fn (string $class): Adapter => $class === DataContainer::class ? new Adapter(DataContainer::class) : $controller,
        );

        $guard = new McpPermissionGuard($call, $users, $decisions, $connection, $framework, new NullLogger());

        return new McpPermissionEnforcer(new ToolPermissionMap(), $guard, new OptionalBundles());
    }
}
