<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Security;

use Contao\CalendarBundle\Security\ContaoCalendarPermissions;
use Contao\CalendarBundle\Security\Voter\CalendarEventsAccessVoter;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\Voter\DataContainer\FormFieldAccessVoter;
use Contao\DataContainer;
use Contao\FaqBundle\Security\ContaoFaqPermissions;
use Contao\FaqBundle\Security\Voter\FaqAccessVoter;
use Contao\NewsBundle\Security\ContaoNewsPermissions;
use Contao\NewsBundle\Security\Voter\NewsAccessVoter;
use Contao\NewsletterBundle\Security\ContaoNewsletterPermissions;
use Contao\NewsletterBundle\Security\Voter\NewsletterAccessVoter;
use Contao\NewsletterBundle\Security\Voter\NewsletterRecipientsAccessVoter;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\BackendUserContext;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionEnforcer;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Security\ToolPermissionMap;
use Netzhirsch\ContaoMcpBundle\Service\McpCallContext;
use Netzhirsch\ContaoMcpBundle\Service\OptionalBundles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Writes into a container (news archive, calendar, FAQ category, newsletter
 * channel, form) for a restricted editor, from tool call to Contao's own voter.
 *
 * The voters look for the container in `pid`; the tools take `archive_id`,
 * `calendar_id` and so on. Handed the argument names, news_create reached
 * NewsAccessVoter without a parent and was refused for every restricted
 * editor (reported from a live site, Contao 5.7.8). And news_update with a
 * new archive_id was checked against the old archive only, so a news entry
 * could be moved into an archive the editor may not touch.
 *
 * The editor here may edit container 4 and nothing else.
 */
#[CoversClass(ToolPermissionMap::class)]
#[CoversClass(McpPermissionGuard::class)]
final class ParentScopedWriteTest extends TestCase
{
    private const OWN = 4;

    private const FOREIGN = 9;

    /** The edit right on a container, per table — what ParentAccessTrait asks for. */
    private const CONTAINER_RIGHTS = [
        ContaoNewsPermissions::USER_CAN_EDIT_ARCHIVE,
        ContaoCalendarPermissions::USER_CAN_EDIT_CALENDAR,
        ContaoFaqPermissions::USER_CAN_EDIT_CATEGORY,
        ContaoNewsletterPermissions::USER_CAN_EDIT_CHANNEL,
        ContaoCorePermissions::USER_CAN_EDIT_FORM,
    ];

    /** @var array<string, VoterInterface> */
    private array $voters = [];

    private mixed $savedModules = null;

    private mixed $savedDca = null;

    protected function setUp(): void
    {
        if (!class_exists(NewsAccessVoter::class)) {
            self::markTestSkipped('This Contao version has no record voter for tl_news.');
        }

        // No backend modules: the module gate is not what is under test.
        $this->savedModules = $GLOBALS['BE_MOD'] ?? null;
        $GLOBALS['BE_MOD'] = [];

        // Just enough of tl_news for the field check to know `author`.
        $this->savedDca = $GLOBALS['TL_DCA']['tl_news'] ?? null;
        $GLOBALS['TL_DCA']['tl_news'] = [
            'config' => ['dataContainer' => 'Contao\DC_Table'],
            'fields' => [
                'pid' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
                'author' => ['inputType' => 'select', 'sql' => 'int(10) unsigned NOT NULL default 0'],
            ],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->savedModules === null) {
            unset($GLOBALS['BE_MOD']);
        } else {
            $GLOBALS['BE_MOD'] = $this->savedModules;
        }

        if ($this->savedDca === null) {
            unset($GLOBALS['TL_DCA']['tl_news']);
        } else {
            $GLOBALS['TL_DCA']['tl_news'] = $this->savedDca;
        }
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function creates(): iterable
    {
        yield 'news' => ['news_create', ['archive_id' => '%s', 'headline' => 'Neu']];
        yield 'event' => ['calendar_event_create', ['calendar_id' => '%s', 'title' => 'Neu', 'startDate' => '2026-11-01']];
        yield 'faq' => ['faq_create', ['category_id' => '%s', 'question' => 'Neu?', 'sorting' => 128]];
        yield 'newsletter' => ['newsletter_create', ['channel_id' => '%s', 'subject' => 'Neu']];
        yield 'recipient' => ['newsletter_recipient_create', ['channel_id' => '%s', 'email' => 'a@example.org']];
        yield 'form field' => ['form_field_create', ['form_id' => '%s', 'type' => 'text']];
    }

    /**
     * @param array<string, mixed> $args
     */
    #[DataProvider('creates')]
    public function testCreatingInOwnContainerIsAllowed(string $tool, array $args): void
    {
        self::assertNull($this->enforcer()->check($tool, self::into($args, self::OWN)));
    }

    /**
     * @param array<string, mixed> $args
     */
    #[DataProvider('creates')]
    public function testCreatingInForeignContainerIsDenied(string $tool, array $args): void
    {
        $denied = $this->enforcer()->check($tool, self::into($args, self::FOREIGN));

        self::assertSame('permission_denied', $denied['error'] ?? null);
    }

    public function testTheAuthorArgumentNeedsTheAuthorFieldRight(): void
    {
        $denied = $this->enforcer(fieldRights: false)->check('news_create', ['archive_id' => self::OWN, 'headline' => 'Neu', 'author_id' => 3]);

        self::assertSame('permission_denied', $denied['error'] ?? null);
        self::assertStringContainsString('"author"', $denied['message'] ?? '');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function moves(): iterable
    {
        yield 'news' => ['news_update', 'archive_id'];
        yield 'event' => ['calendar_event_update', 'calendar_id'];
        yield 'faq' => ['faq_update', 'category_id'];
    }

    /**
     * The other direction: the record sits in the editor's container, the
     * update names one that is not.
     */
    #[DataProvider('moves')]
    public function testMovingIntoForeignContainerIsDenied(string $tool, string $parentArg): void
    {
        $denied = $this->enforcer()->check($tool, ['id' => 5, $parentArg => self::FOREIGN]);

        self::assertSame('permission_denied', $denied['error'] ?? null);
    }

    #[DataProvider('moves')]
    public function testUpdatingInPlaceIsAllowed(string $tool, string $parentArg): void
    {
        self::assertNull($this->enforcer()->check($tool, ['id' => 5, $parentArg => self::OWN]));
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private static function into(array $args, int $container): array
    {
        return array_map(static fn (mixed $v): mixed => $v === '%s' ? $container : $v, $args);
    }

    /**
     * A restricted editor (no ROLE_ADMIN) with every module, field type and
     * — unless $fieldRights is false — every field right. Container rights
     * are only for {@see OWN}; the stored record sits in it.
     */
    private function enforcer(bool $fieldRights = true): McpPermissionEnforcer
    {
        $call = new McpCallContext();
        $call->setIdentity(7, 'client', null);

        $provider = $this->createStub(UserProviderInterface::class);
        $provider->method('loadUserByIdentifier')->willReturn(new InMemoryUser('editor', null, ['ROLE_USER']));

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('editor');
        $connection->method('fetchAssociative')->willReturn(['id' => 5, 'pid' => self::OWN, 'type' => 'text']);
        $connection->method('quoteIdentifier')->willReturnArgument(0);

        $users = new BackendUserContext($provider, $connection, $this->createStub(UserCheckerInterface::class), new NullLogger());

        $decisions = $this->createStub(AccessDecisionManagerInterface::class);
        $decisions->method('decide')->willReturnCallback(
            function (TokenInterface $token, array $attributes, mixed $subject = null) use ($fieldRights): bool {
                $attribute = (string) ($attributes[0] ?? '');

                if (str_starts_with($attribute, ContaoCorePermissions::DC_PREFIX)) {
                    $voter = $this->voters[substr($attribute, \strlen(ContaoCorePermissions::DC_PREFIX))] ?? null;

                    return $voter === null || $voter->vote($token, $subject, $attributes) !== VoterInterface::ACCESS_DENIED;
                }

                if (\in_array($attribute, self::CONTAINER_RIGHTS, true)) {
                    return (int) $subject === self::OWN;
                }

                if ($attribute === ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE) {
                    return $fieldRights;
                }

                return true;
            },
        );

        $this->voters = [
            'tl_news' => new NewsAccessVoter($decisions),
            'tl_calendar_events' => new CalendarEventsAccessVoter($decisions),
            'tl_faq' => new FaqAccessVoter($decisions),
            'tl_newsletter' => new NewsletterAccessVoter($decisions),
            'tl_newsletter_recipients' => new NewsletterRecipientsAccessVoter($decisions),
            'tl_form_field' => new FormFieldAccessVoter($decisions),
        ];

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
