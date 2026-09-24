<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Security;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DataContainer;
use Contao\DC_File;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\BackendUserContext;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\McpCallContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Field rights for a restricted editor, decided the way Contao decides them.
 *
 * Since Contao 5.0 every field with an input is excluded unless its DCA says
 * `exclude => false`, and the core DCAs no longer set the key at all. The guard
 * read `exclude ?? false`, the Contao 4 default, and so checked no core field.
 * The fixture is a trimmed Contao 5.7 tl_content: no field carries the key,
 * except the one that opts out.
 */
#[CoversClass(McpPermissionGuard::class)]
final class FieldPermissionTest extends TestCase
{
    /** @var list<string> "table::field" rights the editor has */
    private array $fieldRights = [];

    /** @var array<string, mixed>|null the stored row an update is checked against */
    private ?array $stored = null;

    private mixed $savedDca = null;

    private mixed $savedSettingsDca = null;

    private mixed $savedModules = null;

    protected function setUp(): void
    {
        $this->savedDca = $GLOBALS['TL_DCA']['tl_content'] ?? null;
        $this->savedSettingsDca = $GLOBALS['TL_DCA']['tl_settings'] ?? null;
        $this->savedModules = $GLOBALS['BE_MOD'] ?? null;

        $GLOBALS['BE_MOD'] = [];
        $GLOBALS['TL_DCA']['tl_content'] = [
            'config' => ['dataContainer' => 'Contao\DC_Table'],
            'fields' => [
                'pid' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
                'ptable' => ['sql' => "varchar(64) NOT NULL default ''"],
                'sorting' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
                'type' => ['inputType' => 'select', 'sql' => ['type' => 'string', 'length' => 64, 'default' => 'text']],
                'headline' => ['inputType' => 'inputUnit', 'sql' => "varchar(255) NOT NULL default 'a:2:{s:5:\"value\";s:0:\"\";s:4:\"unit\";s:2:\"h2\";}'"],
                'text' => ['inputType' => 'textarea', 'sql' => 'mediumtext NULL'],
                'invisible' => ['inputType' => 'checkbox', 'sql' => ['type' => 'boolean', 'default' => false]],
                'customTpl' => ['inputType' => 'select', 'sql' => "varchar(64) NOT NULL default ''"],
                'cssID' => ['inputType' => 'text', 'exclude' => false, 'sql' => "varchar(255) NOT NULL default ''"],
            ],
        ];
        $GLOBALS['TL_DCA']['tl_settings'] = [
            'config' => ['dataContainer' => DC_File::class],
            'fields' => ['adminEmail' => ['inputType' => 'text']],
        ];
    }

    protected function tearDown(): void
    {
        foreach (['tl_content' => $this->savedDca, 'tl_settings' => $this->savedSettingsDca] as $table => $saved) {
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

    /**
     * A restricted editor (no ROLE_ADMIN) whose table and record rights all
     * pass; only the field rights in $fieldRights are granted.
     */
    private function guard(): McpPermissionGuard
    {
        $call = new McpCallContext();
        $call->setIdentity(7, 'client', null);

        $provider = $this->createStub(UserProviderInterface::class);
        $provider->method('loadUserByIdentifier')->willReturn(new InMemoryUser('editor', null, ['ROLE_USER']));

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('editor');
        $connection->method('fetchAssociative')->willReturnCallback(fn (): array|false => $this->stored ?? false);
        $connection->method('quoteIdentifier')->willReturnArgument(0);

        $users = new BackendUserContext($provider, $connection, $this->createStub(UserCheckerInterface::class), new NullLogger());

        $decisions = $this->createStub(AccessDecisionManagerInterface::class);
        $decisions->method('decide')->willReturnCallback(
            fn (TokenInterface $token, array $attributes, mixed $subject = null): bool => $attributes !== [ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE]
                || \in_array($subject, $this->fieldRights, true),
        );

        $controller = $this->createStub(Adapter::class);
        $controller->method('__call')->willReturn(null);

        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(
            static fn (string $class): Adapter => $class === DataContainer::class ? new Adapter(DataContainer::class) : $controller,
        );

        return new McpPermissionGuard($call, $users, $decisions, $connection, $framework, new NullLogger());
    }

    /**
     * The gap: `headline` carries no exclude key, so it was never checked.
     */
    public function testAFieldWithoutAnExcludeKeyNeedsTheFieldRight(): void
    {
        $denied = $this->guard()->ensureCan('tl_content', 'create', null, ['ptable' => 'tl_article', 'pid' => 3, 'headline' => 'Hallo']);

        self::assertSame('permission_denied', $denied['error'] ?? null);
        self::assertStringContainsString('"headline"', $denied['message'] ?? '');

        $this->fieldRights = ['tl_content::headline'];
        self::assertNull($this->guard()->ensureCan('tl_content', 'create', null, ['ptable' => 'tl_article', 'pid' => 3, 'headline' => 'Hallo']));
    }

    public function testAFieldThatOptsOutNeedsNoRight(): void
    {
        self::assertNull($this->guard()->ensureCan('tl_content', 'create', null, ['cssID' => 'x']));
    }

    /**
     * Columns without an input and argument names that are no field at all
     * (the enforcer hands over what the call carried) are nothing to check.
     */
    public function testWhatIsNoFormFieldIsNotChecked(): void
    {
        self::assertNull($this->guard()->ensureCan('tl_content', 'create', null, [
            'ptable' => 'tl_article', 'pid' => 3, 'sorting' => 128, 'fields' => ['x' => 1], 'page_id' => 2,
        ]));
    }

    /**
     * The backend's own create fills in the defaults without asking — the
     * "new element" button needs no right to the type field. Sending the
     * default is not editing the field.
     */
    public function testTheValueANewRecordStartsWithNeedsNoRight(): void
    {
        $guard = $this->guard();

        self::assertNull($guard->ensureCan('tl_content', 'create', null, ['type' => 'text', 'invisible' => false, 'customTpl' => '']));

        $denied = $guard->ensureCan('tl_content', 'create', null, ['type' => 'html']);
        self::assertStringContainsString('"type"', $denied['message'] ?? '');

        $denied = $guard->ensureCan('tl_content', 'create', null, ['invisible' => true]);
        self::assertStringContainsString('"invisible"', $denied['message'] ?? '');
    }

    /**
     * On an update the stored value is what "unchanged" means.
     */
    public function testRewritingTheStoredValueNeedsNoRight(): void
    {
        $this->stored = ['id' => 5, 'pid' => 3, 'ptable' => 'tl_article', 'type' => 'text', 'text' => '<p>alt</p>', 'invisible' => '1'];
        $guard = $this->guard();

        self::assertNull($guard->ensureCan('tl_content', 'update', 5, ['type' => 'text', 'invisible' => true]));

        $denied = $guard->ensureCan('tl_content', 'update', 5, ['text' => '<p>neu</p>']);
        self::assertStringContainsString('"text"', $denied['message'] ?? '');
    }

    public function testNullIsNotAWrite(): void
    {
        self::assertNull($this->guard()->ensureCan('tl_content', 'create', null, ['headline' => null, 'text' => null]));
    }

    /**
     * A copy inserts the source row; asking for the right to its type would
     * make MCP stricter than the copy button. Only what the caller writes is
     * checked.
     */
    public function testOnlyTheWrittenFieldsAreCheckedWhenTheyAreNamed(): void
    {
        $guard = $this->guard();
        $row = ['ptable' => 'tl_article', 'pid' => 3, 'type' => 'html', 'headline' => 'Kopie'];

        self::assertNull($guard->ensureCan('tl_content', 'create', null, $row, []));

        $denied = $guard->ensureCan('tl_content', 'create', null, $row, ['headline' => 'Kopie']);
        self::assertStringContainsString('"headline"', $denied['message'] ?? '');
    }

    /**
     * DC_File tables (tl_settings) have no excluded fields in Contao.
     */
    public function testAFileBasedTableHasNoExcludedFields(): void
    {
        self::assertNull($this->guard()->ensureCan('tl_settings', 'update', null, ['adminEmail' => 'a@example.org']));
    }
}
