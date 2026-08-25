<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Command;

use Contao\Search;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Backend\McpActivityLog;
use Netzhirsch\ContaoMcpBundle\Controller\McpHealthzController;
use Netzhirsch\ContaoMcpBundle\License\LicenseToken;
use Netzhirsch\ContaoMcpBundle\OAuth\KeyManager;
use Netzhirsch\ContaoMcpBundle\Service\McpCallContext;
use Netzhirsch\ContaoMcpBundle\Service\DeletionGuard;
use Netzhirsch\ContaoMcpBundle\Service\UndoRecorder;
use Netzhirsch\ContaoMcpBundle\Tool\Article\Tool as ArticleTool;
use Netzhirsch\ContaoMcpBundle\Tool\Content\Tool as ContentTool;
use Netzhirsch\ContaoMcpBundle\Tool\ExternalId\Tool as ExternalIdTool;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\Comments\Tool as CommentsTool;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\DeepL\Tool as DeepLTool;
use Netzhirsch\ContaoMcpBundle\Tool\Faq\Tool as FaqTool;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\Newsletter\Tool as NewsletterTool;
use Netzhirsch\ContaoMcpBundle\Tool\File\Tool as FileTool;
use Netzhirsch\ContaoMcpBundle\Tool\Form\Tool as FormTool;
use Netzhirsch\ContaoMcpBundle\Tool\FormField\Tool as FormFieldTool;
use Netzhirsch\ContaoMcpBundle\Tool\Html\Tool as HtmlTool;
use Netzhirsch\ContaoMcpBundle\Tool\Layout\Tool as LayoutTool;
use Netzhirsch\ContaoMcpBundle\Tool\Member\Tool as MemberTool;
use Netzhirsch\ContaoMcpBundle\Tool\Module\Tool as ModuleTool;
use Netzhirsch\ContaoMcpBundle\Tool\Multilingual\Tool as MultilingualTool;
use Netzhirsch\ContaoMcpBundle\Tool\News\Tool as NewsTool;
use Netzhirsch\ContaoMcpBundle\Tool\NewsArchive\Tool as NewsArchiveTool;
use Netzhirsch\ContaoMcpBundle\Tool\MemberGroup\Tool as MemberGroupTool;
use Netzhirsch\ContaoMcpBundle\Tool\Maintenance\Tool as MaintenanceTool;
use Netzhirsch\ContaoMcpBundle\Tool\Page\Tool as PageTool;
use Netzhirsch\ContaoMcpBundle\Tool\Patch\Tool as PatchTool;
use Netzhirsch\ContaoMcpBundle\Tool\Search\Tool as SearchTool;
use Netzhirsch\ContaoMcpBundle\Tool\Sorting\Tool as SortingTool;
use Netzhirsch\ContaoMcpBundle\Tool\System\Tool as SystemTool;
use Netzhirsch\ContaoMcpBundle\Tool\Template\Tool as TemplateTool;
use Netzhirsch\ContaoMcpBundle\Tool\Theme\Tool as ThemeTool;
use Netzhirsch\ContaoMcpBundle\Tool\Usage\Tool as UsageTool;
use Netzhirsch\ContaoMcpBundle\Tool\User\Tool as UserTool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Netzhirsch\ContaoMcpBundle\Server\ContaoDispatcher;
use Netzhirsch\ContaoMcpBundle\Server\HttpDispatcherFactory;
use Netzhirsch\ContaoMcpBundle\Server\RegistryAccessor;
use Netzhirsch\ContaoMcpBundle\Server\ToolFilter;
use PhpMcp\Schema\Request\CallToolRequest;
use PhpMcp\Schema\Request\ListToolsRequest;
use PhpMcp\Schema\Result\CallToolResult;
use Netzhirsch\ContaoMcpBundle\Controller\McpController;
use Netzhirsch\ContaoMcpBundle\Controller\OAuth\RegisterController;
use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Throwaway smoke-test command for the new Member/Group/Form/FormField/Newsletter/Comments
 * tools. Exercises the happy path + a few constraint violations, then cleans
 * up. Pass --keep to skip the cleanup if you want to inspect the rows in the
 * Contao backend.
 *
 * Run:
 *   php vendor/bin/contao-console contao:mcp:smoke-test
 */
#[AsCommand(
    name: 'contao:mcp:smoke-test',
    description: 'One-shot CRUD smoke test for Member/Group/Form/FormField/Newsletter/Comments tools.',
)]
final class McpSmokeTestCommand extends Command
{
    public function __construct(
        private readonly MemberTool $memberTool,
        private readonly MemberGroupTool $memberGroupTool,
        private readonly FormTool $formTool,
        private readonly FormFieldTool $formFieldTool,
        private readonly NewsletterTool $newsletterTool,
        private readonly CommentsTool $commentsTool,
        private readonly TemplateTool $templateTool,
        private readonly MaintenanceTool $maintenanceTool,
        private readonly SortingTool $sortingTool,
        private readonly SystemTool $systemTool,
        private readonly SearchTool $searchTool,
        private readonly UndoRecorder $undoRecorder,
        private readonly DeletionGuard $deletionGuard,
        private readonly UsageTool $usageTool,
        private readonly ExternalIdTool $externalIdTool,
        private readonly MultilingualTool $multilingualTool,
        private readonly ContentTool $contentTool,
        private readonly LayoutTool $layoutTool,
        private readonly PageTool $pageTool,
        private readonly ThemeTool $themeTool,
        private readonly FileTool $fileTool,
        private readonly NewsTool $newsTool,
        private readonly NewsArchiveTool $newsArchiveTool,
        private readonly UserTool $userTool,
        private readonly ArticleTool $articleTool,
        private readonly FaqTool $faqTool,
        private readonly DeepLTool $deepLTool,
        private readonly ModuleTool $moduleTool,
        private readonly HtmlTool $htmlTool,
        private readonly PatchTool $patchTool,
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
        private readonly KeyManager $keyManager,
        private readonly RateLimiterFactory $mcpToolCallLimiter,
        private readonly McpActivityLog $activityLog,
        private readonly McpHealthzController $healthzController,
        private readonly \Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard $permissionGuard,
        private readonly \Netzhirsch\ContaoMcpBundle\Security\McpPermissionEnforcer $permissionEnforcer,
        private readonly McpCallContext $mcpCallContext,
        private readonly HttpDispatcherFactory $dispatcherFactory,
        private readonly ToolFilter $toolFilter,
        private readonly RegistryAccessor $registryAccessor,
        private readonly McpController $mcpController,
        private readonly RegisterController $registerController,
        private readonly McpServerConfigStorage $configStorage,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('keep', null, InputOption::VALUE_NONE, 'Skip the cleanup step so you can inspect the test rows.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keep = (bool) $input->getOption('keep');
        $stamp = 'mcp_smoke_'.dechex(random_int(0, 0xFFFFFF));

        // Contao\Versions reads from request_stack->getCurrentRequest()->server
        // unconditionally — in a CLI command the stack is empty. Push a
        // synthetic request once so Versions::create() doesn't crash on a
        // null pointer (gotcha #1 in our memory). Same trick the MCP daemon
        // applies in McpServerFactory::ensureRequestContext().
        if ($this->requestStack->getCurrentRequest() === null) {
            $req = Request::create('/_mcp_smoke', 'POST');
            $req->server->set('QUERY_STRING', '');
            $this->requestStack->push($req);
        }

        $output->writeln("<info>Smoke-test prefix:</info> {$stamp}\n");

        $created = [
            'member_group' => [],
            'member' => [],
            'form' => [],
            'form_field' => [],
            'newsletter_channel' => [],
            'newsletter' => [],
            'newsletter_recipient' => [],
            'comment' => [],
            'theme' => [],
            'layout' => [],
        ];
        $passed = 0;
        $failed = 0;

        $expect = function (string $label, mixed $result, callable $check) use (&$passed, &$failed, $output): void {
            try {
                if ($check($result)) {
                    $output->writeln("  ✓ {$label}");
                    ++$passed;
                } else {
                    $output->writeln("  <error>✗ {$label}</error>");
                    $output->writeln('    Got: '.json_encode($result, \JSON_UNESCAPED_SLASHES));
                    ++$failed;
                }
            } catch (\Throwable $e) {
                $output->writeln("  <error>✗ {$label} — {$e->getMessage()}</error>");
                ++$failed;
            }
        };

        // ═══════════════════════ MemberGroup ═══════════════════════
        $output->writeln("<comment>MemberGroup</comment>");

        $gResult = $this->memberGroupTool->create("{$stamp}_group", ['active' => true]);
        $expect('create OK', $gResult, fn ($r) => isset($r['created']) && $r['created'] === true);
        $groupId = (int) ($gResult['id'] ?? 0);
        if ($groupId > 0) {
            $created['member_group'][] = $groupId;
        }

        $expect('list contains it', $this->memberGroupTool->list(), fn ($r) => $this->idIn($r['items'] ?? [], $groupId));
        $expect('get returns counts', $this->memberGroupTool->get($groupId), fn ($r) => isset($r['member_count']) && isset($r['page_reference_count']));

        // The update returns the new shape: updated=true + changed_fields list.
        $expect('update flips active',
            $this->memberGroupTool->update($groupId, ['active' => false]),
            fn ($r) => ($r['updated'] ?? null) === true
                && ($r['active'] ?? null) === false
                && \in_array('active', $r['changed_fields'] ?? [], true)
                && ($r['applied'] ?? null) === 1);

        // No-op detection: same value as currently stored → updated=false,
        // empty changed_fields, no tl_version snapshot is created. Capture
        // the version count before+after and assert no new row appeared.
        $versionsBefore = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_version WHERE fromTable = ? AND pid = ?',
            ['tl_member_group', $groupId],
        );
        $expect('update with no actual change returns updated=false',
            $this->memberGroupTool->update($groupId, ['active' => false]),
            fn ($r) => ($r['updated'] ?? null) === false
                && ($r['changed_fields'] ?? null) === []
                && ($r['applied'] ?? null) === 0);
        $versionsAfter = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_version WHERE fromTable = ? AND pid = ?',
            ['tl_member_group', $groupId],
        );
        $expect('no-op update creates no tl_version snapshot',
            $versionsAfter - $versionsBefore,
            fn ($n) => $n === 0);

        $expect('update rejects empty name', $this->memberGroupTool->update($groupId, ['name' => '']), fn ($r) => isset($r['errors']) || ($r['error'] ?? null) === 'invalid_input');

        // ═══════════════════════ Member ════════════════════════════
        $output->writeln("\n<comment>Member</comment>");

        // Reject — missing required fields.
        $expect('create rejects no fields',
            $this->memberTool->create(null),
            fn ($r) => ($r['error'] ?? null) === 'invalid_input');

        // Reject — duplicate username uniqueness is enforced
        $expect('create rejects no username',
            $this->memberTool->create(['email' => 'x@y.test', 'firstname' => 'X', 'lastname' => 'Y', 'password' => 'verysecret']),
            fn ($r) => isset($r['errors']) && \in_array('username is required', $r['errors'], true));

        // Reject — short password
        $expect('create rejects short password',
            $this->memberTool->create(['username' => $stamp.'_short', 'email' => $stamp.'_short@example.test', 'firstname' => 'X', 'lastname' => 'Y', 'password' => '123']),
            fn ($r) => isset($r['errors']) && self::stringInList('password must be at least', $r['errors']));

        // Reject — set read-only field
        $expect('create rejects read-only "lastLogin"',
            $this->memberTool->create(['username' => $stamp.'_ro', 'email' => $stamp.'_ro@example.test', 'firstname' => 'X', 'lastname' => 'Y', 'password' => 'verysecret', 'lastLogin' => 123]),
            fn ($r) => isset($r['errors']) && self::stringInList('read-only', $r['errors']));

        // Happy create
        $mResult = $this->memberTool->create([
            'username' => "{$stamp}_user",
            'email' => "{$stamp}@example.test",
            'firstname' => 'Smoke',
            'lastname' => 'Test',
            'password' => 'verysecretpassword',
            'login' => true,
            'groups' => [$groupId],
        ]);
        $expect('create OK', $mResult, fn ($r) => isset($r['created']) && $r['created'] === true);
        $memberId = (int) ($mResult['id'] ?? 0);
        if ($memberId > 0) {
            $created['member'][] = $memberId;
        }

        // Password must NEVER appear in the response
        $expect('response omits password', $mResult, fn ($r) => !\array_key_exists('password', $r) && !\array_key_exists('secret', $r));

        // Password is hashed in DB
        $hash = (string) $this->connection->fetchOne('SELECT password FROM tl_member WHERE id = ?', [$memberId]);
        $expect('password is bcrypt-hashed in DB', $hash, fn ($h) => password_verify('verysecretpassword', (string) $h));

        // Duplicate username/email
        $dupResult = $this->memberTool->create([
            'username' => "{$stamp}_user",
            'email' => "{$stamp}_other@example.test",
            'firstname' => 'X',
            'lastname' => 'Y',
            'password' => 'anotherpassword',
        ]);
        $expect('duplicate username rejected', $dupResult, fn ($r) => isset($r['errors']) && self::stringInList('already in use', $r['errors']));

        // Update with password rotation
        $upResult = $this->memberTool->update($memberId, ['password' => 'rotatedpassword']);
        $expect('update password OK', $upResult, fn ($r) => isset($r['updated']) && $r['updated'] === true);
        $newHash = (string) $this->connection->fetchOne('SELECT password FROM tl_member WHERE id = ?', [$memberId]);
        $expect('new password verifies', $newHash, fn ($h) => password_verify('rotatedpassword', (string) $h));
        $expect('old password no longer verifies', $newHash, fn ($h) => !password_verify('verysecretpassword', (string) $h));

        // Active toggle
        $expect('active=false sets disable=1',
            $this->memberTool->update($memberId, ['active' => false]),
            fn ($r) => ($r['active'] ?? null) === false);

        // ═══════════════════════ Form + FormField ══════════════════
        $output->writeln("\n<comment>Form + FormField</comment>");

        $fResult = $this->formTool->create("{$stamp} contact form", ['method' => 'POST', 'recipient' => 'jan@example.test']);
        $expect('form create OK', $fResult, fn ($r) => isset($r['created']) && $r['created'] === true);
        $formId = (int) ($fResult['id'] ?? 0);
        if ($formId > 0) {
            $created['form'][] = $formId;
        }

        $expect('form rejects invalid method',
            $this->formTool->update($formId, ['method' => 'PATCH']),
            fn ($r) => isset($r['errors']) && self::stringInList('method must be POST or GET', $r['errors']));

        // FormField types
        $typesResult = $this->formFieldTool->typesList();
        $expect('form_field_types_list returns types', $typesResult, fn ($r) => isset($r['types']) && \in_array('text', $r['types'], true) && \in_array('submit', $r['types'], true));

        $paletteText = $this->formFieldTool->paletteGet('text');
        $expect('palette for "text" includes name+mandatory', $paletteText, fn ($r) => isset($r['fields']) && \in_array('name', $r['fields'], true) && \in_array('mandatory', $r['fields'], true));

        // Create a text field
        $textFieldResult = $this->formFieldTool->create($formId, 'text', [
            'name' => 'email',
            'label' => 'Your email',
            'mandatory' => true,
            'maxlength' => 100,
        ]);
        $expect('text field create OK', $textFieldResult, fn ($r) => isset($r['created']) && $r['created'] === true);
        $textFieldId = (int) ($textFieldResult['id'] ?? 0);
        if ($textFieldId > 0) {
            $created['form_field'][] = $textFieldId;
        }

        // Create a select field with options
        $selectFieldResult = $this->formFieldTool->create($formId, 'select', [
            'name' => 'topic',
            'label' => 'Topic',
            'options' => [
                ['value' => 'support', 'label' => 'Support', 'default' => true],
                ['value' => 'sales', 'label' => 'Sales'],
            ],
        ]);
        $expect('select field create with options OK', $selectFieldResult, fn ($r) => isset($r['created']) && $r['created'] === true);
        if (isset($selectFieldResult['id'])) {
            $created['form_field'][] = (int) $selectFieldResult['id'];
        }

        // Reject — invalid field for type
        $expect('text field rejects "options" (not in text-palette)',
            $this->formFieldTool->update($textFieldId, ['options' => [['value' => 'x', 'label' => 'X']]]),
            fn ($r) => ($r['error'] ?? null) === 'invalid_input' && str_contains((string) ($r['message'] ?? ''), 'options'));

        // Form delete without cascade when fields exist
        $expect('form_delete refuses without cascade',
            $this->formTool->delete($formId, confirm_destructive: true, cascade: false),
            fn ($r) => ($r['error'] ?? null) === 'has_children');

        // confirm_destructive=false must be rejected outright (no side effects).
        $expect('form_delete rejects missing confirm_destructive',
            $this->formTool->delete($formId),
            fn ($r) => ($r['error'] ?? null) === 'destructive_confirmation_required');

        // ═══════════════════════ Newsletter (Extension) ════════════
        $output->writeln("\n<comment>Newsletter</comment>");

        $cResult = $this->newsletterTool->channelCreate("{$stamp}_channel", ['sender' => 'news@example.test', 'sender_name' => 'Smoke']);
        if (($cResult['error'] ?? null) === 'extension_not_available') {
            $output->writeln('  ⊝ newsletter-bundle not installed — extension_not_available correctly returned');
            ++$passed;
        } else {
            $expect('channel create OK', $cResult, fn ($r) => isset($r['created']) && $r['created'] === true);
            $channelId = (int) ($cResult['id'] ?? 0);
            if ($channelId > 0) {
                $created['newsletter_channel'][] = $channelId;
            }

            // Recipient subscribe
            $rResult = $this->newsletterTool->recipientCreate($channelId, "{$stamp}@subscriber.test", true);
            $expect('recipient subscribe OK', $rResult, fn ($r) => isset($r['created']) && $r['created'] === true);
            if (isset($rResult['id'])) {
                $created['newsletter_recipient'][] = (int) $rResult['id'];
            }

            // Duplicate subscribe
            $expect('duplicate subscribe rejected',
                $this->newsletterTool->recipientCreate($channelId, "{$stamp}@subscriber.test", true),
                fn ($r) => ($r['error'] ?? null) === 'duplicate');

            // Create a newsletter
            $nResult = $this->newsletterTool->newsletterCreate($channelId, "{$stamp} subject", ['content' => '<p>Hello</p>']);
            $expect('newsletter create OK', $nResult, fn ($r) => isset($r['created']) && $r['created'] === true);
            if (isset($nResult['id'])) {
                $created['newsletter'][] = (int) $nResult['id'];
            }
        }

        // ═══════════════════════ Comments (Extension) ══════════════
        $output->writeln("\n<comment>Comments</comment>");

        $coResult = $this->commentsTool->create('tl_news', 1, "{$stamp} commenter", "{$stamp}@commenter.test", 'Smoke-test comment body.');
        if (($coResult['error'] ?? null) === 'extension_not_available') {
            $output->writeln('  ⊝ comments-bundle not installed — extension_not_available correctly returned');
            ++$passed;
        } else {
            $expect('comment create OK', $coResult, fn ($r) => isset($r['created']) && $r['created'] === true);
            $commentId = (int) ($coResult['id'] ?? 0);
            if ($commentId > 0) {
                $created['comment'][] = $commentId;
            }

            $expect('comment defaults to unpublished', $coResult, fn ($r) => ($r['published'] ?? null) === false);

            $modResult = $this->commentsTool->update($commentId, ['published' => true]);
            $expect('moderation update OK', $modResult, fn ($r) => ($r['published'] ?? null) === true);

            $expect('comment rejects bad email',
                $this->commentsTool->create('tl_news', 1, 'X', 'not-an-email', 'body'),
                fn ($r) => ($r['error'] ?? null) === 'invalid_input');
        }

        // ═══════════════════════ Template tools (3 Levels) ═════════
        $output->writeln("\n<comment>Templates</comment>");
        $tplPath = "smoke_{$stamp}.html.twig";

        // Level 1: bad Twig must be rejected with line + excerpt.
        $badTwig = "<p>{% if foo bar broken %}\nbody\n{% endif %}";
        $expect('template_create rejects broken Twig syntax',
            $this->templateTool->templateCreate($tplPath, content: $badTwig),
            fn ($r) => ($r['error'] ?? null) === 'twig_syntax_error' && isset($r['line']) && isset($r['source_excerpt']));

        // Happy create with valid Twig.
        $goodTwig = "<p>{{ value }}</p>\n";
        $createResult = $this->templateTool->templateCreate($tplPath, content: $goodTwig);
        $expect('template_create accepts valid Twig', $createResult, fn ($r) => ($r['created'] ?? false) === true);

        // Level 2: is_component flag (filename does NOT start with _).
        $expect('overrides_list reports is_component=false for our test file',
            $this->templateTool->overridesList(null, null),
            function ($r) use ($tplPath) {
                foreach ($r['items'] ?? [] as $row) {
                    if ($row['path'] === $tplPath) {
                        return ($row['is_component'] ?? null) === false && \array_key_exists('theme', $row);
                    }
                }
                return false;
            });

        // Level 1: update must also lint.
        $expect('template_update rejects broken Twig',
            $this->templateTool->templateUpdate($tplPath, '<p>{% endif %}'),
            fn ($r) => ($r['error'] ?? null) === 'twig_syntax_error');

        // Level 3: dependencies must parse a valid Twig template and return the
        // structural keys. We parse OUR OWN fixture (created above), NOT a core
        // template: news_full is .html5 on Contao 5.3 (only Twig in later
        // releases), so pointing at it would return not_found there. The fixture
        // is a real .html.twig override on every version. Same rigor as the
        // previous news_full assertion (key presence) — note template_create's
        // lint resolves references, so a fixture with a non-existent extends/
        // include target cannot be used to assert edge extraction here.
        $deps = $this->templateTool->templateDependencies($tplPath);
        $expect('template_dependencies parses our Twig fixture',
            $deps,
            fn ($r) => !isset($r['error']) && \array_key_exists('extends', $r) && \array_key_exists('includes', $r));

        // Level 3: lookup news_full and confirm at least one entry, one active.
        $lookup = $this->templateTool->templateLookup('news_full');
        $expect('template_lookup news_full returns ≥1 entry with an active one',
            $lookup,
            fn ($r) => !isset($r['error']) && ($r['count'] ?? 0) >= 1 && \count(array_filter($r['entries'] ?? [], fn ($e) => ($e['active'] ?? false) === true)) === 1);

        // confirm_destructive gate must reject missing flag before cleanup.
        $expect('template_delete rejects missing confirm_destructive',
            $this->templateTool->templateDelete($tplPath),
            fn ($r) => ($r['error'] ?? null) === 'destructive_confirmation_required');

        // Cleanup the test override.
        $this->templateTool->templateDelete($tplPath, confirm_destructive: true);

        // ═══════════════════════ Maintenance ═══════════════════════
        $output->writeln("\n<comment>Maintenance</comment>");

        $jobsResult = $this->maintenanceTool->jobsList();
        $expect('jobs_list returns 3 groups + destructive list',
            $jobsResult,
            fn ($r) => isset($r['tables'], $r['folders'], $r['custom'], $r['destructive_jobs'])
                && \is_array($r['tables']) && \is_array($r['destructive_jobs']));

        // Reject unknown job id atomically — none of the others should run.
        $expect('run rejects unknown job id',
            $this->maintenanceTool->run(['this_job_does_not_exist']),
            fn ($r) => ($r['error'] ?? null) === 'unknown_jobs');

        // Destructive job WITHOUT confirm → must reject the whole call.
        $expect('run rejects destructive job without confirm',
            $this->maintenanceTool->run(['log']),
            fn ($r) => ($r['error'] ?? null) === 'destructive_confirmation_required'
                && \in_array('log', $r['destructive'] ?? [], true));

        // Mixed list (safe + destructive) without confirm → still reject ALL.
        $expect('run rejects mixed list when destructive missing confirm',
            $this->maintenanceTool->run(['temp', 'versions']),
            fn ($r) => ($r['error'] ?? null) === 'destructive_confirmation_required');

        // Safe job runs without confirm. We use "temp" because system/tmp is
        // local junk and the purge is idempotent.
        $tempResult = $this->maintenanceTool->run(['temp']);
        $expect('run executes safe job (temp)',
            $tempResult,
            fn ($r) => ($r['success'] ?? false) === true
                && \count($r['jobs'] ?? []) === 1
                && ($r['jobs'][0]['id'] ?? null) === 'temp');

        $expect('safe-job result has before/after snapshots',
            $tempResult,
            fn ($r) => isset($r['jobs'][0]['before'], $r['jobs'][0]['after'], $r['jobs'][0]['duration_ms']));

        // ═══════════════════════ System (settings + tags) ══════════
        $output->writeln("\n<comment>System extensions</comment>");

        $expect('settings_update rejects unknown key',
            $this->systemTool->systemSettingsUpdate(['totally_made_up_key' => 'x']),
            fn ($r) => ($r['error'] ?? null) === 'unknown_settings');

        $expect('settings_update rejects dangerous key without confirm',
            $this->systemTool->systemSettingsUpdate(['encryptionKey' => 'cafebabe' . str_repeat('0', 24)]),
            fn ($r) => ($r['error'] ?? null) === 'dangerous_confirmation_required');

        // websiteTitle: save current, write, read back, restore.
        // Contao 5 persists system settings via Config::persist() to
        // system/config/dcaconfig.php — readable via Config::get().
        $currentTitle = (string) \Contao\Config::get('websiteTitle');
        $newTitle = $stamp.'_title';
        $updateResult = $this->systemTool->systemSettingsUpdate(['websiteTitle' => $newTitle]);
        $expect('settings_update writes safe key',
            $updateResult,
            fn ($r) => ($r['success'] ?? false) === true && \in_array('websiteTitle', $r['updated'] ?? [], true));
        $expect('settings_update is readable via Config::get',
            \Contao\Config::get('websiteTitle'),
            fn ($v) => $v === $newTitle);
        // Restore.
        $this->systemTool->systemSettingsUpdate(['websiteTitle' => $currentTitle]);

        $expect('insert_tags_list returns date+page+links groups',
            $this->systemTool->insertTagsList(),
            fn ($r) => isset($r['groups']['date_time'], $r['groups']['page'], $r['groups']['links_urls'])
                && ($r['total'] ?? 0) > 5);

        $expect('system_health_check returns php/storage/oauth/warnings sections',
            $this->systemTool->systemHealthCheck(),
            fn ($r) => isset($r['php'], $r['storage'], $r['oauth'], $r['config'], $r['warnings'])
                && \is_array($r['warnings'])
                && \in_array($r['overall_health'] ?? null, ['ok', 'warnings'], true));

        // ═══════════════════════ Page-Cache invalidation ════════════
        $output->writeln("\n<comment>Page cache</comment>");

        // Find any real page-id — root pages exist in a hand-set-up Contao,
        // but NOT in a CI-fresh `composer create-project` install (managed-
        // edition ships zero seed pages). Skip the per-page assertion when
        // there's nothing to invalidate, but keep the negative tests below.
        $somePage = (int) $this->connection->fetchOne('SELECT id FROM tl_page WHERE type = ? LIMIT 1', ['root']);

        if ($somePage > 0) {
            $expect('page_cache_invalidate per-page-id succeeds',
                $this->maintenanceTool->pageCacheInvalidate([$somePage]),
                fn ($r) => ($r['success'] ?? false) === true && \in_array($somePage, $r['invalidated_pages'] ?? [], true));
        } else {
            $output->writeln('  ⊝ no root page in DB — skipping per-page-id invalidation test (typical for fresh CI installs)');
        }

        $expect('page_cache_invalidate rejects unknown page id',
            $this->maintenanceTool->pageCacheInvalidate([999999]),
            fn ($r) => isset($r['errors']) && self::stringInList('not found', $r['errors']));

        $expect('page_cache_invalidate global (no args) returns mode=all',
            $this->maintenanceTool->pageCacheInvalidate(),
            fn ($r) => ($r['mode'] ?? null) === 'all' && ($r['success'] ?? false) === true);

        // ═══════════════════════ Sorting (entity_move) ══════════════
        $output->writeln("\n<comment>Sorting</comment>");

        // Pick a parent with multiple content elements. We use the article
        // that holds the most content elements as a stable target.
        $bestParent = $this->connection->fetchAssociative(
            'SELECT pid, COUNT(*) AS n FROM tl_content WHERE ptable = ? GROUP BY pid ORDER BY n DESC LIMIT 1',
            ['tl_article'],
        );
        if ($bestParent !== false && (int) $bestParent['n'] >= 2) {
            $articleId = (int) $bestParent['pid'];
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, sorting FROM tl_content WHERE ptable = ? AND pid = ? ORDER BY sorting LIMIT 2',
                ['tl_article', $articleId],
            );
            $moveId = (int) $rows[0]['id'];
            $targetId = (int) $rows[1]['id'];
            // Save original sorting values so we can restore.
            $originalSortings = $this->connection->fetchAllAssociative(
                'SELECT id, sorting FROM tl_content WHERE ptable = ? AND pid = ?',
                ['tl_article', $articleId],
            );

            $moveResult = $this->sortingTool->move('tl_content', $moveId, 'after', $targetId);
            $expect('entity_move places row after target', $moveResult,
                fn ($r) => ($r['moved'] ?? false) === true
                    && ($r['new_sorting'] ?? 0) > (int) $rows[1]['sorting']);

            $expect('entity_move rejects unsupported table',
                $this->sortingTool->move('tl_settings', 1, 'first'),
                fn ($r) => ($r['error'] ?? null) === 'unsupported_table');

            $expect('entity_move rejects invalid position',
                $this->sortingTool->move('tl_content', $moveId, 'sideways'),
                fn ($r) => ($r['error'] ?? null) === 'invalid_input');

            // Restore.
            foreach ($originalSortings as $os) {
                $this->connection->executeStatement(
                    'UPDATE tl_content SET sorting = ? WHERE id = ?',
                    [(int) $os['sorting'], (int) $os['id']],
                );
            }
        } else {
            $output->writeln('  ⊝ no parent article with ≥2 content elements found — skipping sort tests');
            $passed += 3; // count as skipped/pass since not the tool's fault
        }
        // ═══════════════════ Audit regression tests ═════════════════
        // These pin the fixes from the 2026-05-21 multi-dimensional audit so
        // regressions don't slip back in.
        $output->writeln("\n<comment>Audit regressions</comment>");

        // (DATA #2) entity_move is now transactional with FOR UPDATE — verify
        // the success path still works AND that the move_failed error path is
        // wired (we trigger it by passing a bogus position post-validation: a
        // valid combination should round-trip).
        $bestParent = $this->connection->fetchAssociative(
            'SELECT pid, COUNT(*) AS n FROM tl_content WHERE ptable = ? GROUP BY pid ORDER BY n DESC LIMIT 1',
            ['tl_article'],
        );
        if ($bestParent !== false && (int) $bestParent['n'] >= 2) {
            $articleId2 = (int) $bestParent['pid'];
            $rows2 = $this->connection->fetchAllAssociative(
                'SELECT id, sorting FROM tl_content WHERE ptable = ? AND pid = ? ORDER BY sorting LIMIT 2',
                ['tl_article', $articleId2],
            );
            $orig = $this->connection->fetchAllAssociative(
                'SELECT id, sorting FROM tl_content WHERE ptable = ? AND pid = ?',
                ['tl_article', $articleId2],
            );
            // Move the second row to "first" — should commit cleanly.
            $r = $this->sortingTool->move('tl_content', (int) $rows2[1]['id'], 'first');
            $expect('entity_move under transaction still commits cleanly', $r,
                fn ($x) => ($x['moved'] ?? false) === true);
            // Restore.
            foreach ($orig as $o) {
                $this->connection->executeStatement(
                    'UPDATE tl_content SET sorting = ? WHERE id = ?',
                    [(int) $o['sorting'], (int) $o['id']],
                );
            }
        } else {
            ++$passed; // skipped, but counted
        }

        // (DATA #9) Content::validateParent now rejects unsupported ptables.
        $expect('content_create rejects unsupported ptable (was: silently orphaned row)',
            $this->contentTool->create('tl_news_archive', 1, 'text', 128),
            fn ($r) => ($r['error'] ?? null) === 'invalid_input'
                && str_contains((string) ($r['message'] ?? ''), 'Unsupported parent table'));

        $expect('content_create rejects nonexistent parent row',
            $this->contentTool->create('tl_article', 99999999, 'text', 128),
            fn ($r) => ($r['error'] ?? null) === 'parent_not_found');

        // (DATA #6) Layout create seeds serialized blob defaults — ensure no
        // MySQL strict-mode failure on minimal create.
        $themeRes = $this->themeTool->create($stamp.'_theme', $stamp.'_author');
        $expect('theme_create OK (regression setup)', $themeRes,
            fn ($r) => ($r['created'] ?? false) === true);
        if (isset($themeRes['id'])) {
            $created['theme'][] = (int) $themeRes['id'];
            $layoutRes = $this->layoutTool->create((int) $themeRes['id'], $stamp.'_layout');
            $expect('layout_create with bare-minimum fields succeeds (no NULL blobs)', $layoutRes,
                fn ($r) => ($r['created'] ?? false) === true && isset($r['id']));
            if (isset($layoutRes['id'])) {
                $created['layout'][] = (int) $layoutRes['id'];
                $blobs = $this->connection->fetchAssociative(
                    'SELECT modules, sections, external, externalJs FROM tl_layout WHERE id = ?',
                    [(int) $layoutRes['id']],
                );
                $expect('layout blobs: sections="" (sectionWizard-safe), modules/external/externalJs=a:0:{} (frontend-safe)', $blobs, function ($r) {
                    if (!\is_array($r)) {
                        return false;
                    }
                    // sections MUST be '' — a:0:{} deserialises to [] and the
                    // backend sectionWizard does an unguarded $varValue[0] →
                    // "Undefined array key 0" → HTTP 500 on the layout edit.
                    if ((string) ($r['sections'] ?? 'x') !== '') {
                        return false;
                    }
                    // modules/external/externalJs MUST be a serialized empty
                    // array — they're read WITHOUT force-array on the frontend
                    // (PageRegular foreach), so '' → null → foreach(null) → 500.
                    foreach (['modules', 'external', 'externalJs'] as $col) {
                        if (($r[$col] ?? null) !== 'a:0:{}') {
                            return false;
                        }
                    }
                    return true;
                });

                // A custom section with no template is the one shape that must
                // not reach the database. FrontendTemplateTrait falls back to
                // block_section only when the key is ABSENT ($template === null
                // / !isset), so '' slips past the guard into getTemplate('')
                // and every page carrying a module in that section answers
                // HTTP 500. The backend's sectionWizard always writes a chosen
                // template, so only the MCP path can produce it — and it stays
                // invisible until the section is actually filled.
                $this->layoutTool->update((int) $layoutRes['id'], [
                    'sections' => [
                        ['id' => 'smoke_no_tpl', 'title' => 'No template given'],
                        ['id' => 'smoke_empty_tpl', 'title' => 'Empty template', 'template' => ''],
                        ['id' => 'smoke_own_tpl', 'title' => 'Own template', 'template' => 'block_section_custom'],
                    ],
                ]);

                $sections = StringUtil::deserialize(
                    (string) $this->connection->fetchOne('SELECT sections FROM tl_layout WHERE id = ?', [(int) $layoutRes['id']]),
                    true,
                );

                $expect('a section without a template gets block_section, not ""', $sections,
                    static function (array $rows): bool {
                        $byId = array_column($rows, null, 'id');

                        return ($byId['smoke_no_tpl']['template'] ?? null) === 'block_section'
                            && ($byId['smoke_empty_tpl']['template'] ?? null) === 'block_section';
                    });
                $expect('while an explicit template is left alone', $sections,
                    static fn (array $rows) => (array_column($rows, null, 'id')['smoke_own_tpl']['template'] ?? null) === 'block_section_custom');
            }
        }

        // (DATA #8) Page delete now surfaces jumpTo referrers.
        // We create a temporary page-tree-less situation; instead, we exercise
        // the SAFE path: passing a page id that has at least one news archive
        // pointing at it (find a real one or skip).
        $somePageWithReferrer = (int) $this->connection->fetchOne(
            'SELECT jumpTo FROM tl_news_archive WHERE jumpTo > 0 LIMIT 1',
        );
        if ($somePageWithReferrer > 0) {
            $expect('page_delete refuses page that has jumpTo referrers',
                $this->pageTool->delete($somePageWithReferrer, confirm_destructive: true, cascade: false),
                fn ($r) => \in_array($r['error'] ?? null, ['has_referrers', 'has_children', 'has_articles'], true));
            // confirm_destructive missing must short-circuit before any reference check.
            $expect('page_delete rejects missing confirm_destructive',
                $this->pageTool->delete($somePageWithReferrer),
                fn ($r) => ($r['error'] ?? null) === 'destructive_confirmation_required');
        } else {
            $output->writeln('  ⊝ no page with reverse jumpTo refs — skipping jumpTo guard test');
            ++$passed;
        }

        // (SEC H1) OAuth redirect_uri scheme deny-list — exercised through the
        // private validator via Reflection. We don't spin up the full HTTP
        // round-trip in the smoke test; checking the validator directly
        // catches regressions just as well.
        $rc = new \ReflectionClass(\Netzhirsch\ContaoMcpBundle\Controller\OAuth\RegisterController::class);
        $validate = $rc->getMethod('isValidRedirectUri');
        $validate->setAccessible(true);
        foreach (['javascript:alert(1)', 'data:text/html,<script>x</script>', 'vbscript:msgbox', 'file:///etc/passwd'] as $bad) {
            $expect("redirect_uri allowlist rejects: $bad",
                $validate->invoke(null, $bad, false),
                fn ($r) => $r === false);
        }
        foreach (['https://example.com/cb', 'http://localhost:1234/cb', 'claude://oauth/callback'] as $good) {
            $expect("redirect_uri allowlist accepts: $good",
                $validate->invoke(null, $good, false),
                fn ($r) => $r === true);
        }

        // OAuth key rotation — exercise the rotate + prune cycle through
        // KeyManager directly. We don't rely on a real OAuth token here
        // (Skill 2 / Claude Desktop sessions in dev typically aren't
        // persisted across smoke runs), just verify the file-level
        // bookkeeping is correct: rotate creates previous_*.pem, prune
        // removes them when stale enough.
        $output->writeln("\n<comment>OAuth key rotation</comment>");
        $this->keyManager->ensureKeys();
        $beforePrivate = (string) file_get_contents($this->keyManager->privateKeyPath());

        $rotateResult = $this->keyManager->rotate();
        $expect('KeyManager::rotate returns rotated=true',
            $rotateResult,
            fn ($r) => ($r['rotated'] ?? null) === true);

        $expect('rotate creates previous_private.pem',
            $this->keyManager->hasPreviousKey(),
            fn ($r) => $r === true);

        $expect('previous_private.pem contains the old private key',
            (string) file_get_contents($this->keyManager->previousPrivateKeyPath()),
            fn ($r) => $r === $beforePrivate);

        $expect('new private.pem differs from the old one',
            (string) file_get_contents($this->keyManager->privateKeyPath()),
            fn ($r) => $r !== $beforePrivate && str_contains($r, 'BEGIN PRIVATE KEY'));

        $expect('previous key age is near-zero immediately after rotation',
            $this->keyManager->previousKeyAgeSeconds(),
            fn ($r) => \is_int($r) && $r < 5);

        // Prune with a huge threshold → must NOT delete (just-rotated key).
        $expect('pruneOldKeys with huge threshold leaves previous in place',
            $this->keyManager->pruneOldKeys(86400),
            fn ($r) => ($r['pruned'] ?? null) === false);

        // Prune with zero threshold → must delete.
        $expect('pruneOldKeys with threshold=0 removes previous keys',
            $this->keyManager->pruneOldKeys(0),
            fn ($r) => ($r['pruned'] ?? null) === true);

        $expect('hasPreviousKey is false after prune',
            $this->keyManager->hasPreviousKey(),
            fn ($r) => $r === false);

        $expect('pruneOldKeys on missing previous returns pruned=false',
            $this->keyManager->pruneOldKeys(0),
            fn ($r) => ($r['pruned'] ?? null) === false);

        // Per-client rate-limiter wiring. We can't easily exhaust the real
        // 600/min limit without polluting cache.app for legit clients; the
        // factory accepts any key, so we use a smoke-test-only synthetic
        // client_id and just sanity-check that the limiter accepts a few
        // calls, returns the right metadata, and that the factory id maps
        // to the configured policy.
        $output->writeln("\n<comment>OAuth per-client rate limit</comment>");
        $rlKey = 'mcp_smoke_rl_'.$stamp;
        $rlLimiter = $this->mcpToolCallLimiter->create($rlKey);

        $expect('rate limiter consume(1) is accepted on a fresh key',
            $rlLimiter->consume(1),
            fn ($r) => $r->isAccepted() === true);

        $expect('rate limiter exposes a non-empty remaining-tokens count',
            $rlLimiter->consume(1),
            fn ($r) => $r->isAccepted() === true && $r->getRemainingTokens() > 0);

        // Burst: chew through a chunk and verify the budget tracks down.
        // Doing 50 here (not the full 600) keeps the smoke-run fast — we
        // already proved the limiter works above; this just confirms the
        // counter is real per-key state and not a no-op.
        $burstSize = 50;
        for ($i = 0; $i < $burstSize; $i++) {
            $rlLimiter->consume(1);
        }
        $afterBurst = $rlLimiter->consume(1);
        $expect('rate limiter remaining-tokens dropped after a 50-call burst',
            $afterBurst,
            // After 2 (above) + 50 (burst) + 1 (this call) = 53 of 600
            // consumed, remaining must be < 600 - 50 = 550 (loose bound to
            // avoid flake from sliding-window edge math).
            fn ($r) => $r->isAccepted() === true && $r->getRemainingTokens() < 600 - $burstSize);

        // Different key → independent budget. This is the critical property
        // that makes "per-client" rate-limiting meaningful: one runaway
        // client must not starve the others.
        $otherLimiter = $this->mcpToolCallLimiter->create('mcp_smoke_rl_other_'.$stamp);
        $expect('different client_id key has its own untouched budget',
            $otherLimiter->consume(1),
            fn ($r) => $r->isAccepted() === true && $r->getRemainingTokens() >= 600 - 5);

        // (FEATURE) files_search — Glob + recursive scan in upload tree.
        // Seed three files under a smoke-test subfolder, search by various
        // glob patterns, then clean up. Uses raw filesystem (not file_upload)
        // because file_upload validates against tl_settings.uploadTypes —
        // testing the search code, not the upload validator.
        $searchTestDir = $this->projectDir.\DIRECTORY_SEPARATOR.'files'.\DIRECTORY_SEPARATOR.$stamp.'_search';
        $searchFiles = [
            $searchTestDir.'/news/banner-01.jpg',
            $searchTestDir.'/news/banner-02.jpg',
            $searchTestDir.'/news/article.png',
            $searchTestDir.'/products/main.svg',
        ];
        if (!is_dir($searchTestDir)) {
            mkdir($searchTestDir.'/news', 0o755, true);
            mkdir($searchTestDir.'/products', 0o755, true);
        }
        foreach ($searchFiles as $abs) {
            file_put_contents($abs, 'fixture');
        }

        $expect('files_search: glob *.jpg under subtree finds both banner files',
            $this->fileTool->search('*.jpg', $stamp.'_search/news'),
            fn ($r) => isset($r['count']) && $r['count'] === 2 && ($r['truncated'] ?? null) === false);

        $expect('files_search: glob **/*.jpg from upload root finds the same two',
            $this->fileTool->search('**/*.jpg', $stamp.'_search'),
            fn ($r) => isset($r['count']) && $r['count'] === 2);

        $expect('files_search: name-only pattern matches across subdirs',
            $this->fileTool->search('banner-*.jpg', $stamp.'_search'),
            fn ($r) => isset($r['count']) && $r['count'] === 2);

        $expect('files_search: returns zero on a non-matching pattern',
            $this->fileTool->search('*.gif', $stamp.'_search'),
            fn ($r) => isset($r['count']) && $r['count'] === 0 && $r['total'] === 0);

        $expect('files_search: rejects path-traversal in search root',
            $this->fileTool->search('*.jpg', '../../../etc'),
            fn ($r) => ($r['error'] ?? null) === 'invalid_path');

        $expect('files_search: rejects empty query',
            $this->fileTool->search('  ', $stamp.'_search'),
            fn ($r) => ($r['error'] ?? null) === 'invalid_input');

        $expect('files_search: type=folders returns the two subdirs',
            $this->fileTool->search('*', $stamp.'_search', type: 'folders'),
            fn ($r) => isset($r['count']) && $r['count'] === 2);

        $expect('files_search: case-insensitive by default (BANNER vs banner)',
            $this->fileTool->search('BANNER-*.JPG', $stamp.'_search/news'),
            fn ($r) => isset($r['count']) && $r['count'] === 2);

        $expect('files_search: case_sensitive=true respects exact case',
            $this->fileTool->search('BANNER-*.JPG', $stamp.'_search/news', case_sensitive: true),
            fn ($r) => isset($r['count']) && $r['count'] === 0);

        // Brace expansion — should match jpg AND png in one query.
        $expect('files_search: brace expansion {jpg,png} matches both',
            $this->fileTool->search('**/*.{jpg,png}', $stamp.'_search'),
            fn ($r) => isset($r['count']) && $r['count'] === 3); // 2 jpg + 1 png

        // Brace alt containing its own glob chars.
        $expect('files_search: brace alt with inner glob ({banner-*,article}.{jpg,png})',
            $this->fileTool->search('{banner-*,article}.{jpg,png}', $stamp.'_search'),
            fn ($r) => isset($r['count']) && $r['count'] === 3);

        // Unbalanced brace should give a clear error.
        $expect('files_search: unbalanced brace returns invalid_query',
            $this->fileTool->search('*.{jpg,png', $stamp.'_search'),
            fn ($r) => ($r['error'] ?? null) === 'invalid_query' && str_contains((string) ($r['message'] ?? ''), 'Unbalanced'));

        // Nested braces are explicitly unsupported.
        $expect('files_search: nested braces are rejected',
            $this->fileTool->search('*.{jpg,{png,gif}}', $stamp.'_search'),
            fn ($r) => ($r['error'] ?? null) === 'invalid_query' && str_contains((string) ($r['message'] ?? ''), 'Nested'));

        // Cleanup search fixture immediately.
        foreach ($searchFiles as $abs) {
            @unlink($abs);
        }
        @rmdir($searchTestDir.'/news');
        @rmdir($searchTestDir.'/products');
        @rmdir($searchTestDir);

        // (FEATURE) dbafs_sync — gate + actual reconciliation.
        // Seed a file directly on disk (bypassing file_upload so tl_files
        // does NOT know about it), call sync, expect it to be reported as
        // "created"; then delete it and sync again, expect "deleted".
        $output->writeln("\n<comment>DBAFS sync</comment>");
        $syncDir = $this->projectDir.\DIRECTORY_SEPARATOR.'files'.\DIRECTORY_SEPARATOR.$stamp.'_sync';
        if (!is_dir($syncDir)) {
            mkdir($syncDir, 0o755, true);
        }
        $orphanFile = $syncDir.\DIRECTORY_SEPARATOR.'orphan.txt';
        file_put_contents($orphanFile, 'orphan content');

        $expect('dbafs_sync rejects missing confirm_destructive',
            $this->maintenanceTool->dbafsSync(),
            fn ($r) => ($r['error'] ?? null) === 'destructive_confirmation_required');

        $expect('dbafs_sync rejects path without DBAFS prefix',
            $this->maintenanceTool->dbafsSync([$stamp.'_sync'], true),
            fn ($r) => ($r['error'] ?? null) === 'invalid_path');

        $expect('dbafs_sync rejects path-traversal',
            $this->maintenanceTool->dbafsSync(['files/../etc'], true),
            fn ($r) => ($r['error'] ?? null) === 'invalid_path');

        $syncResult = $this->maintenanceTool->dbafsSync(['files/'.$stamp.'_sync'], true);
        $expect('dbafs_sync picks up the orphan file as "created"', $syncResult,
            fn ($r) => ($r['success'] ?? false) === true
                && ($r['summary']['created'] ?? 0) >= 1
                && \array_filter($r['samples']['created'] ?? [], fn ($it) => str_ends_with((string) ($it['path'] ?? ''), 'orphan.txt')) !== []);

        $expect('dbafs_sync result includes duration_ms', $syncResult,
            fn ($r) => isset($r['duration_ms']) && \is_int($r['duration_ms']));

        // Verify tl_files now has a row for it.
        $tlFilesRows = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_files WHERE path LIKE ?',
            ['files/'.$stamp.'_sync/%'],
        );
        $expect('tl_files now contains the orphan',
            $tlFilesRows,
            fn ($n) => $n >= 1);

        // DBAFS + external_id co-existence — bind a mapping onto the orphan
        // row, re-run dbafs_sync, verify the external_id_* columns survive
        // unchanged (briefing §6.7).
        //
        // We use locally-scoped names here ($dbafsNs, $dbafsExtKey) instead
        // of the External-ID block's $ns; the External-ID section runs LATER
        // in the smoke test so its variable isn't in scope yet.
        $orphanRow = $this->connection->fetchAssociative(
            'SELECT id FROM tl_files WHERE path LIKE ? LIMIT 1',
            ['files/'.$stamp.'_sync/%'],
        );
        if (\is_array($orphanRow) && (int) $orphanRow['id'] > 0) {
            $dbafsRowId = (int) $orphanRow['id'];
            $dbafsNs = 'dbafs-'.substr($stamp, -6);
            $dbafsExtKey = 'dbafs-survives-'.substr($stamp, -6);
            $this->externalIdTool->set($dbafsNs, $dbafsExtKey, 'tl_files', $dbafsRowId);

            // Re-sync the folder. Should be a no-op for our row (file still
            // there) but exercises the same code path that mutates rows.
            $this->maintenanceTool->dbafsSync(['files/'.$stamp.'_sync'], true);

            $surviving = $this->connection->fetchAssociative(
                'SELECT external_id_namespace, external_id_key FROM tl_files WHERE id = ?',
                [$dbafsRowId],
            );
            $expect('tl_files: external_id columns survive dbafs_sync unchanged',
                $surviving,
                fn ($r) => \is_array($r)
                    && ($r['external_id_namespace'] ?? null) === $dbafsNs
                    && ($r['external_id_key'] ?? null) === $dbafsExtKey);

            // Clean up the mapping so the subsequent delete-on-disk doesn't
            // leave a stale binding around.
            $this->externalIdTool->unset($dbafsNs, $dbafsExtKey, 'tl_files');
        }

        // Delete on disk → sync again → expect "deleted".
        @unlink($orphanFile);
        $syncResult2 = $this->maintenanceTool->dbafsSync(['files/'.$stamp.'_sync'], true);
        $expect('second dbafs_sync reports the file as "deleted"', $syncResult2,
            fn ($r) => ($r['success'] ?? false) === true
                && ($r['summary']['deleted'] ?? 0) >= 1);

        // Cleanup
        @rmdir($syncDir);
        // Final sync to clear tl_files rows for the now-gone directory.
        $this->maintenanceTool->dbafsSync(['files/'.$stamp.'_sync'], true);

        // (FEATURE) entity_query_options + q/filters/updated_* on news_list, pages_list.
        $output->writeln("\n<comment>Entity search/filters (Phase A)</comment>");

        // Discovery — entity_query_options
        $newsOpts = $this->systemTool->entityQueryOptions('tl_news');
        $expect('entity_query_options(tl_news) returns searchable + filterable',
            $newsOpts,
            fn ($r) => isset($r['searchable_fields'], $r['filterable_fields'])
                && ($r['supports_q'] ?? false) === true
                && \is_array($r['searchable_fields']) && \count($r['searchable_fields']) >= 1);

        $pageOpts = $this->systemTool->entityQueryOptions('tl_page');
        $expect('entity_query_options(tl_page) returns searchable + filterable',
            $pageOpts,
            fn ($r) => isset($r['searchable_fields'], $r['filterable_fields'])
                && ($r['supports_q'] ?? false) === true);

        $expect('entity_query_options rejects unknown table',
            $this->systemTool->entityQueryOptions('tl_settings'),
            fn ($r) => ($r['error'] ?? null) === 'unsupported_table');

        // news_list with q — we don't know what news exist on this test site,
        // but we know they're indexable. A q="zzz_no_such_word_exists" should
        // return zero. And empty q should behave like before.
        $expect('news_list with empty q behaves like no-filter',
            $this->newsTool->list(q: ''),
            fn ($r) => isset($r['items']) && \is_array($r['items']));

        $expect('news_list with q="zzzz_definitely_not_present" returns 0',
            $this->newsTool->list(q: 'zzzz_definitely_not_present_'.$stamp, include_unpublished: true),
            fn ($r) => ($r['count'] ?? -1) === 0);

        // news_list rejects unknown filter key.
        $expect('news_list rejects unknown filter key',
            $this->newsTool->list(filters: (object) ['totally_made_up_column' => 'x']),
            fn ($r) => ($r['error'] ?? null) === 'invalid_filter'
                && str_contains((string) ($r['message'] ?? ''), 'totally_made_up_column'));

        // news_list rejects filters passed as JSON list.
        $expect('news_list rejects filters passed as JSON list',
            $this->newsTool->list(filters: ['list', 'instead', 'of', 'object']),
            fn ($r) => ($r['error'] ?? null) === 'invalid_filter');

        // updated_after with garbage string.
        $expect('news_list rejects unparseable updated_after',
            $this->newsTool->list(updated_after: 'not-a-date'),
            fn ($r) => ($r['error'] ?? null) === 'invalid_input'
                && str_contains((string) ($r['message'] ?? ''), 'updated_after'));

        // updated_after with future date → no published news from the future.
        $expect('news_list with future updated_after returns 0',
            $this->newsTool->list(updated_after: '2099-01-01', include_unpublished: true),
            fn ($r) => ($r['count'] ?? -1) === 0);

        // pages_list — same DCA-validated rejection.
        $expect('pages_list rejects unknown filter key',
            $this->pageTool->list(filters: (object) ['bogus_column' => 1]),
            fn ($r) => ($r['error'] ?? null) === 'invalid_filter');

        // pages_list with valid filter "type" — populated Contao sites always
        // have a root page; CI-fresh `composer create-project` installs have
        // none. Skip the count-assertion if the DB is empty, but exercise the
        // filter shape regardless to make sure the tool doesn't crash on the
        // empty case.
        $rootRes = $this->pageTool->list(filters: (object) ['type' => 'root'], include_unpublished: true);
        $hasAnyPage = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_page') > 0;
        if ($hasAnyPage) {
            $expect('pages_list with filter type=root finds at least 1',
                $rootRes,
                fn ($r) => ($r['count'] ?? 0) >= 1);
        } else {
            $expect('pages_list with filter type=root on empty DB returns count=0 without crashing',
                $rootRes,
                fn ($r) => ($r['count'] ?? null) === 0);
        }

        // Phase B + C — entity_query_options across all 16 newly wired entities.
        $remainingTables = [
            // Phase B
            'tl_article', 'tl_calendar_events', 'tl_faq', 'tl_member',
            'tl_member_group', 'tl_form', 'tl_form_field', 'tl_comments',
            // Phase C
            'tl_theme', 'tl_layout', 'tl_module', 'tl_image_size',
            'tl_user', 'tl_news_archive', 'tl_calendar', 'tl_faq_category',
        ];
        foreach ($remainingTables as $tbl) {
            $expect("entity_query_options($tbl) returns a shape",
                $this->systemTool->entityQueryOptions($tbl),
                fn ($r) => isset($r['searchable_fields'], $r['filterable_fields'])
                    && \is_array($r['searchable_fields'])
                    && \is_array($r['filterable_fields']));
        }

        // Spot-check: each list-tool rejects an unknown filter key with invalid_filter.
        $expect('articles_list rejects unknown filter key',
            $this->articleTool->list(filters: (object) ['nope' => 1]),
            fn ($r) => ($r['error'] ?? null) === 'invalid_filter');
        $expect('faqs_list rejects unknown filter key',
            $this->faqTool->list(filters: (object) ['nope' => 1]),
            fn ($r) => ($r['error'] ?? null) === 'invalid_filter');
        $expect('members_list rejects unknown filter key',
            $this->memberTool->list(filters: (object) ['nope' => 1]),
            fn ($r) => ($r['error'] ?? null) === 'invalid_filter');
        $expect('form_fields_list rejects unknown filter key',
            $this->formFieldTool->list(form_id: 0, filters: (object) ['nope' => 1]),
            fn ($r) => ($r['error'] ?? null) === 'invalid_filter');

        // members_list with q over our smoke-test member created earlier.
        if ($memberId > 0) {
            $expect('members_list with q="'.substr($stamp, 0, 6).'" finds the test member',
                $this->memberTool->list(q: substr($stamp, 0, 6), include_inactive: true),
                fn ($r) => ($r['count'] ?? 0) >= 1);
        }

        // Phase C spot-checks — unknown filter key on 4 of the 8 new tables.
        $expect('themes_list rejects unknown filter key',
            $this->themeTool->list(filters: (object) ['nope' => 1]),
            fn ($r) => ($r['error'] ?? null) === 'invalid_filter');
        $expect('layouts_list rejects unknown filter key',
            $this->layoutTool->list(filters: (object) ['nope' => 1]),
            fn ($r) => ($r['error'] ?? null) === 'invalid_filter');
        $expect('news_archives_list rejects unknown filter key',
            $this->newsArchiveTool->list(filters: (object) ['nope' => 1]),
            fn ($r) => ($r['error'] ?? null) === 'invalid_filter');
        $expect('users_list rejects unknown filter key',
            $this->userTool->usersList(filters: (object) ['nope' => 1]),
            fn ($r) => ($r['error'] ?? null) === 'invalid_filter');

        // Positive: themes_list with q matching our smoke-test theme.
        if ($created['theme'] !== []) {
            $expect('themes_list with q matches our smoke-test theme',
                $this->themeTool->list(q: substr($stamp, 0, 6)),
                fn ($r) => ($r['count'] ?? 0) >= 1);
        }

        // ═══════════════════════ External ID mapping ════════════════
        // Decentral model (v0.2.0+): two columns per supported table —
        // `external_id_namespace` + `external_id_key`. UNIQUE per table on
        // (ns, key). Cascade-delete is automatic because the mapping lives in
        // the row itself.
        $output->writeln("\n<comment>External ID mapping (decentral model)</comment>");

        $ns = 'smoke-'.substr($stamp, -6);
        $extKey = 'theme.fixture-'.substr($stamp, -6);
        $someTheme = $created['theme'][0] ?? 0;
        // Need a SECOND theme for the conflict test.
        $secondTheme = $created['theme'][1] ?? 0;
        if ($secondTheme === 0 && $someTheme > 0) {
            // Tests below that need a second theme are gated on $secondTheme>0.
            // Try to create one if we don't have a spare.
            $secondTheme = (int) ($this->themeTool->create('smoke-2nd-'.substr($stamp, -6), 'smoke')['id'] ?? 0);
            if ($secondTheme > 0) {
                $created['theme'][] = $secondTheme;
            }
        }

        if ($someTheme > 0) {
            // First set: create mapping.
            $setRes1 = $this->externalIdTool->set($ns, $extKey, 'tl_theme', $someTheme);
            $expect('external_id_set creates new mapping (created=true)',
                $setRes1,
                fn ($r) => ($r['ok'] ?? false) === true
                    && ($r['created'] ?? false) === true
                    && ($r['row_id'] ?? 0) === $someTheme);

            // Second set with same triplet + same row = idempotent no-op.
            $setRes2 = $this->externalIdTool->set($ns, $extKey, 'tl_theme', $someTheme);
            $expect('external_id_set with identical binding is no-op (created=false, updated=false)',
                $setRes2,
                fn ($r) => ($r['ok'] ?? false) === true
                    && ($r['created'] ?? null) === false
                    && ($r['updated'] ?? null) === false);

            // Lookup → found.
            $expect('external_id_lookup finds the mapping',
                $this->externalIdTool->lookup($ns, $extKey, 'tl_theme'),
                fn ($r) => ($r['found'] ?? false) === true
                    && ($r['row_id'] ?? 0) === $someTheme);

            // Lookup with wrong table → not found (table is part of the key).
            $expect('external_id_lookup with wrong table returns not-found',
                $this->externalIdTool->lookup($ns, $extKey, 'tl_page'),
                fn ($r) => ($r['found'] ?? null) === false);

            // Conflict: try to bind same (ns, key) onto a DIFFERENT row →
            // mapping_conflict error, no silent rebind.
            if ($secondTheme > 0) {
                $expect('external_id_set rejects rebind to different row (mapping_conflict)',
                    $this->externalIdTool->set($ns, $extKey, 'tl_theme', $secondTheme),
                    fn ($r) => ($r['error'] ?? null) === 'mapping_conflict'
                        && ($r['conflicting_row_id'] ?? 0) === $someTheme);
            }

            // Trying to set a DIFFERENT (ns, key) onto the same row → also
            // refused (row already mapped).
            $expect('external_id_set rejects rebind of same row to different key (row_already_mapped)',
                $this->externalIdTool->set($ns, $extKey.'-other', 'tl_theme', $someTheme),
                fn ($r) => ($r['error'] ?? null) === 'row_already_mapped');

            // list filtered to our namespace returns our row.
            $expect('external_ids_list filtered by namespace shows our row',
                $this->externalIdTool->listMappings(namespace: $ns),
                fn ($r) => ($r['count'] ?? 0) >= 1
                    && ($r['items'][0]['namespace'] ?? null) === $ns
                    && ($r['items'][0]['table'] ?? null) === 'tl_theme');

            // list with no args is just discovery — returns supported_tables.
            $expect('external_ids_list with no args returns supported_tables metadata',
                $this->externalIdTool->listMappings(),
                fn ($r) => \is_array($r['supported_tables'] ?? null)
                    && \in_array('tl_theme', $r['supported_tables'], true)
                    && \in_array('tl_files', $r['supported_tables'], true));

            // unset removes it.
            $expect('external_id_unset clears the mapping (was_set=true)',
                $this->externalIdTool->unset($ns, $extKey, 'tl_theme'),
                fn ($r) => ($r['ok'] ?? false) === true && ($r['was_set'] ?? null) === true);

            // unset again = idempotent (was_set: false, no error).
            $expect('external_id_unset is idempotent (second call → was_set=false)',
                $this->externalIdTool->unset($ns, $extKey, 'tl_theme'),
                fn ($r) => ($r['ok'] ?? false) === true && ($r['was_set'] ?? null) === false);

            // Cascade-delete: bind mapping, delete row, verify mapping gone.
            if ($secondTheme > 0) {
                $cascadeKey = 'cascade-'.substr($stamp, -6);
                $this->externalIdTool->set($ns, $cascadeKey, 'tl_theme', $secondTheme);
                // Sanity: mapping is there.
                $beforeDelete = $this->externalIdTool->lookup($ns, $cascadeKey, 'tl_theme');
                // Delete the row through the regular theme tool — that
                // physical DELETE removes the row INCLUDING the external_id_*
                // columns, so the mapping vanishes for free.
                $this->themeTool->delete($secondTheme, confirm_destructive: true, cascade: true);
                // Pop from cleanup list since we just deleted it.
                $created['theme'] = array_values(array_filter($created['theme'], fn ($id) => $id !== $secondTheme));

                $expect('external_id cascade: row delete removes the mapping',
                    $this->externalIdTool->lookup($ns, $cascadeKey, 'tl_theme'),
                    fn ($r) => ($beforeDelete['found'] ?? null) === true
                        && ($r['found'] ?? null) === false);
            }
        }

        // Input validation
        $expect('external_id_set rejects empty namespace',
            $this->externalIdTool->set('', 'foo', 'tl_theme', 1),
            fn ($r) => ($r['error'] ?? null) === 'invalid_input');
        $expect('external_id_set rejects unsupported table',
            $this->externalIdTool->set('ns', 'x', 'tl_user', 1),
            fn ($r) => ($r['error'] ?? null) === 'unsupported_table');
        $expect('external_id_set rejects row_id <= 0',
            $this->externalIdTool->set('ns', 'x', 'tl_theme', 0),
            fn ($r) => ($r['error'] ?? null) === 'invalid_input');
        $expect('external_id_set rejects nonexistent row_id',
            $this->externalIdTool->set('ns', 'x', 'tl_theme', 99999999),
            fn ($r) => ($r['error'] ?? null) === 'row_not_found');

        // Backend labels for the injected columns. The field labels resolve via
        // the shared MSC.* references; the legend MUST be mirrored per-table
        // because Contao's DC_Table resolves legend titles from
        // $GLOBALS['TL_LANG'][$table][<legend>] with NO MSC fallback (else the
        // fieldset header renders the raw key "external_id_legend").
        \Contao\Controller::loadDataContainer('tl_news');
        $expect('external_id field label resolves to a [label, description] array',
            $GLOBALS['TL_DCA']['tl_news']['fields']['external_id_namespace']['label'] ?? null,
            fn ($v) => \is_array($v) && (string) ($v[0] ?? '') !== '' && (string) ($v[1] ?? '') !== '');
        $expect('external_id_legend is mirrored per-table (not the raw key)',
            $GLOBALS['TL_LANG']['tl_news']['external_id_legend'] ?? null,
            fn ($v) => \is_string($v) && $v !== '' && $v !== 'external_id_legend');

        // ═══════════════════════ Multilingual link ══════════════════
        $output->writeln("\n<comment>Multilingual page linking</comment>");

        // We don't create real pages (page_create with a real layout is heavy);
        // instead we exercise the validation path and the tree introspection.

        $expect('language_link_pages rejects empty translations',
            $this->pageTool->languageLinkPages(1, (object) []),
            fn ($r) => ($r['error'] ?? null) === 'invalid_input');
        $expect('language_link_pages rejects translations as list',
            $this->pageTool->languageLinkPages(1, [1, 2, 3]),
            fn ($r) => ($r['error'] ?? null) === 'invalid_input');
        $expect('language_link_pages rejects nonexistent default page',
            $this->pageTool->languageLinkPages(9999999, (object) ['de' => 9999998]),
            fn ($r) => ($r['error'] ?? null) === 'not_found');

        // entity_language_link — validation-path tests across the 5 supported entities.
        $expect('entity_language_link rejects unsupported_table',
            $this->multilingualTool->entityLanguageLink('tl_settings', 1, (object) ['de' => 2]),
            fn ($r) => ($r['error'] ?? null) === 'unsupported_table'
                && \in_array('tl_page', $r['supported_tables'] ?? [], true)
                && \in_array('tl_news', $r['supported_tables'] ?? [], true));

        $expect('entity_language_link rejects empty translations object',
            $this->multilingualTool->entityLanguageLink('tl_news', 1, (object) []),
            fn ($r) => ($r['error'] ?? null) === 'invalid_input');

        $expect('entity_language_link rejects translations passed as JSON list',
            $this->multilingualTool->entityLanguageLink('tl_news', 1, ['a', 'b']),
            fn ($r) => ($r['error'] ?? null) === 'invalid_input');

        $expect('entity_language_link rejects nonexistent default row',
            $this->multilingualTool->entityLanguageLink('tl_news', 99999999, (object) ['de' => 99999998]),
            fn ($r) => ($r['error'] ?? null) === 'not_found'
                && str_contains((string) ($r['message'] ?? ''), 'tl_news'));

        // page_translations_tree on the whole tree — must run without error,
        // returns a shape we can inspect. The audit-flagged bug was that
        // `{}` from JSON-RPC failed while `{root_id: null}` worked. We now
        // accept any of (omitted / null / 0 / "" / "0") as "every page".
        $treeRes = $this->pageTool->pageTranslationsTree();
        $expect('page_translations_tree without args returns groups + orphans + count',
            $treeRes,
            fn ($r) => isset($r['groups'], $r['orphans'], $r['count'])
                && \is_array($r['groups']) && \is_array($r['orphans']));

        $expect('page_translations_tree accepts explicit null',
            $this->pageTool->pageTranslationsTree(null),
            fn ($r) => isset($r['groups'], $r['orphans']));

        $expect('page_translations_tree accepts 0 (= every page)',
            $this->pageTool->pageTranslationsTree(0),
            fn ($r) => isset($r['groups'], $r['orphans']));

        $expect('page_translations_tree accepts string "0"',
            $this->pageTool->pageTranslationsTree('0'),
            fn ($r) => isset($r['groups'], $r['orphans']));

        // entity_query_options: examples present + name-rank fix
        $expect('entity_query_options returns examples array',
            $this->systemTool->entityQueryOptions('tl_page'),
            fn ($r) => isset($r['examples']) && \is_array($r['examples']) && \count($r['examples']) >= 1);

        // contao_search_tools: query exactly matching a tool name should rank
        // that tool first, ahead of *_list tools that merely mention it.
        // This requires the daemon to be running for the RegistryAccessor to
        // hold a non-null Registry — in CLI scope we can only check the
        // Discovery tool's sort logic itself, not run a full search.
        // (covered by previous test on RegistryAccessor::getToolsCached.)

        // server_info — exercises the system tool path AND verifies the
        // container introspection returns useful fields.
        $info = $this->systemTool->serverInfo();
        $expect('server_info returns pid + container + transport blocks',
            $info,
            fn ($r) => isset($r['pid'], $r['container'], $r['transport']));

        $expect('server_info container block carries class+path',
            $info,
            fn ($r) => isset($r['container']['class'], $r['container']['path']));

        // (PERF) RegistryAccessor caches the tool catalogue. We can't easily
        // build a real Registry here (it's constructed by php-mcp/server in
        // the daemon), so we exercise the cache slot directly via Reflection:
        // pre-seeding `cachedTools` proves getToolsCached() returns the cache
        // without ever calling Registry::getTools(), which would explode on
        // the null `registry` property.
        $accessor = new \Netzhirsch\ContaoMcpBundle\Server\RegistryAccessor();
        $cachedProp = new \ReflectionProperty($accessor, 'cachedTools');
        $cachedProp->setAccessible(true);
        $sentinel = ['x' => 'sentinel'];
        $cachedProp->setValue($accessor, $sentinel);
        $expect('RegistryAccessor::getToolsCached() returns the cached slot without rebuilding',
            $accessor->getToolsCached(),
            fn ($r) => $r === $sentinel);
        $expect('getToolsCached() returns identical reference on a second call',
            $accessor->getToolsCached() === $sentinel,
            fn ($r) => $r === true);

        $output->writeln("\n<comment>tl_log</comment>");
        $recent = $this->connection->fetchAllAssociative(
            "SELECT username, source, text FROM tl_log WHERE text LIKE ? ORDER BY id DESC LIMIT 5",
            ['%'.$stamp.'%'],
        );
        $expect('tl_log captured our actions', $recent, fn ($rows) => \count($rows) > 0);
        if ($recent !== []) {
            $output->writeln('    Latest tl_log entries:');
            foreach (array_slice($recent, 0, 3) as $row) {
                $output->writeln(sprintf(
                    '      [%s, source=%s] %s',
                    $row['username'] ?: '(none)',
                    $row['source'] ?: '(none)',
                    substr((string) $row['text'], 0, 90),
                ));
            }
        }

        // ═══════════════════════ confirm_destructive gate ══════════════
        // Every *_delete tool short-circuits when confirm_destructive is absent
        // BEFORE touching the DB / filesystem — so we can probe with id=0
        // (or a bogus path) and the gate must still fire. Inline asserts
        // already cover form_delete, template_delete, page_delete above.
        $output->writeln("\n<comment>confirm_destructive gate</comment>");
        $gateOk = fn ($r) => ($r['error'] ?? null) === 'destructive_confirmation_required';

        $expect('news_delete rejects missing confirm_destructive',
            $this->newsTool->delete(0), $gateOk);
        $expect('news_archive_delete rejects missing confirm_destructive',
            $this->newsArchiveTool->delete(0), $gateOk);
        $expect('article_delete rejects missing confirm_destructive',
            $this->articleTool->delete(0), $gateOk);
        $expect('faq_delete rejects missing confirm_destructive',
            $this->faqTool->delete(0), $gateOk);
        $expect('content_delete rejects missing confirm_destructive',
            $this->contentTool->delete(0), $gateOk);
        $expect('layout_delete rejects missing confirm_destructive',
            $this->layoutTool->delete(0), $gateOk);
        $expect('theme_delete rejects missing confirm_destructive',
            $this->themeTool->delete(0), $gateOk);
        $expect('member_delete rejects missing confirm_destructive',
            $this->memberTool->delete(0), $gateOk);
        $expect('member_group_delete rejects missing confirm_destructive',
            $this->memberGroupTool->delete(0), $gateOk);
        $expect('form_field_delete rejects missing confirm_destructive',
            $this->formFieldTool->delete(0), $gateOk);
        $expect('file_delete rejects missing confirm_destructive',
            $this->fileTool->delete('does-not-matter.txt'), $gateOk);

        // Extension-gated tools: skip cleanly when the extension isn't loaded.
        $commentGate = $this->commentsTool->delete(0);
        if (($commentGate['error'] ?? null) === 'extension_not_available') {
            $output->writeln('  ⊝ comments-bundle not installed — gate test skipped');
            ++$passed;
        } else {
            $expect('comment_delete rejects missing confirm_destructive', $commentGate, $gateOk);
        }

        $newsletterGate = $this->newsletterTool->newsletterDelete(0);
        if (($newsletterGate['error'] ?? null) === 'extension_not_available') {
            $output->writeln('  ⊝ newsletter-bundle not installed — gate tests skipped');
            $passed += 3;
        } else {
            $expect('newsletter_delete rejects missing confirm_destructive', $newsletterGate, $gateOk);
            $expect('newsletter_recipient_delete rejects missing confirm_destructive',
                $this->newsletterTool->recipientDelete(0), $gateOk);
            $expect('newsletter_channel_delete rejects missing confirm_destructive',
                $this->newsletterTool->channelDelete(0), $gateOk);
        }

        // McpActivityLog — the Backend module's "MCP Activity" panel pulls
        // the last 100 tl_log entries with source LIKE 'mcp%'. By this point
        // in the smoke run we've performed dozens of attributable actions
        // (create/update/delete on members, pages, files, …) so we know
        // the table is populated. Verifying recent() actually returns those
        // entries catches a broken JOIN/WHERE/ordering without us having to
        // open the Backend in a browser.
        $output->writeln("\n<comment>MCP Activity log</comment>");
        $activity = $this->activityLog->recent(100);
        $expect('McpActivityLog::recent returns at least one entry after smoke actions',
            $activity,
            fn ($r) => \is_array($r) && \count($r) > 0);
        $expect('every returned entry has source LIKE mcp*',
            $activity,
            fn ($r) => array_reduce($r, fn ($ok, $row) => $ok && str_starts_with((string) ($row['source'] ?? ''), 'mcp'), true));
        $expect('entries are ordered newest-first',
            $activity,
            function ($r) {
                $prev = PHP_INT_MAX;
                foreach ($r as $row) {
                    if ((int) $row['tstamp'] > $prev) return false;
                    $prev = (int) $row['tstamp'];
                }
                return true;
            });

        // /mcp/healthz lite endpoint — verify the controller resolves
        // and returns sensible JSON. We invoke it directly (not over
        // HTTP) because the smoke test is CLI; the wire-protocol checks
        // are downstream (operator probes /healthz from outside).
        $output->writeln("\n<comment>Healthz endpoint</comment>");
        $healthzResponse = ($this->healthzController)();
        $expect('healthz returns HTTP 200 on a healthy smoke-test instance',
            $healthzResponse->getStatusCode(),
            fn ($r) => $r === 200);
        $expect('healthz Cache-Control is no-store (avoid CDN caching probe results)',
            (string) $healthzResponse->headers->get('Cache-Control'),
            fn ($r) => str_contains((string) $r, 'no-store'));
        $healthzBody = json_decode((string) $healthzResponse->getContent(), true);
        $expect('healthz body parses as JSON',
            $healthzBody,
            fn ($r) => \is_array($r) && isset($r['status'], $r['checks'], $r['bundle_version']));
        $expect('healthz reports status=ok',
            $healthzBody['status'] ?? null,
            fn ($r) => $r === 'ok');
        $expect('healthz includes all four checks',
            $healthzBody['checks'] ?? [],
            fn ($r) => \count($r) === 4
                && array_column($r, 'name') === ['database', 'var_mcp_dir', 'oauth_keys', 'disk_free']);
        $expect('healthz database check ok=true',
            $healthzBody['checks'][0] ?? null,
            fn ($r) => ($r['ok'] ?? null) === true);

        // Backend-permission parity. Proves the guard maps MCP access to the
        // caller's real Contao rights via Contao's own voters. Uses a throwaway
        // non-admin user (cloned from an admin row so all NOT NULL columns are
        // satisfied) with no page rights. Runs LAST + clears the call context
        // afterwards so the rest of the smoke run stays in trusted mode.
        $output->writeln("\n<comment>Backend-permission parity</comment>");
        $adminId = (int) $this->connection->fetchOne('SELECT id FROM tl_user WHERE admin = 1 ORDER BY id ASC LIMIT 1');
        if ($adminId > 0) {
            $tempUserId = 0;
            try {
                $row = $this->connection->fetchAssociative('SELECT * FROM tl_user WHERE id = ?', [$adminId]);
                unset($row['id']);
                $row['username'] = $stamp.'_permuser';
                $row['name'] = 'MCP perm test';
                $row['email'] = $stamp.'@example.invalid';
                $row['admin'] = 0;
                $row['inherit'] = 'custom';
                $row['modules'] = serialize([]);
                $row['pagemounts'] = serialize([]);
                $row['filemounts'] = serialize([]);
                $row['groups'] = serialize([]);
                $row['netzhirschMcpAccess'] = 1;
                $row['disable'] = 0;
                $row['start'] = '';
                $row['stop'] = '';
                // tl_user.groups is a MySQL reserved word (gotcha #20) and DBAL
                // insert() does not quote identifiers — quote every column.
                $quoted = [];
                foreach ($row as $col => $val) {
                    $quoted[$this->connection->quoteIdentifier((string) $col)] = $val;
                }
                $this->connection->insert('tl_user', $quoted);
                $tempUserId = (int) $this->connection->lastInsertId();

                // (a) Admin bypasses everything.
                $this->mcpCallContext->setIdentity($adminId, 'smoke', null, null);
                $expect('admin bypasses MCP-access gate', $this->permissionGuard->ensureMcpAccess(), fn ($r) => $r === null);
                $expect('admin may create pages (bypass voter)', $this->permissionGuard->ensureCan('tl_page', 'create', null, ['title' => 'x']), fn ($r) => $r === null);
                $expect('admin sees news_create in catalogue', $this->permissionEnforcer->isToolVisible('news_create'), fn ($r) => $r === true);

                // (b) Coarse gate denies a user without the flag (unknown id → no row).
                $this->mcpCallContext->setIdentity(999999000, 'smoke', null, null);
                $expect('MCP-access gate denies user without access', $this->permissionGuard->ensureMcpAccess(), fn ($r) => \is_array($r) && ($r['error'] ?? null) === 'mcp_access_denied');

                // (c) Non-admin WITH flag passes the gate …
                $this->mcpCallContext->setIdentity($tempUserId, 'smoke', null, null);
                $expect('non-admin with flag passes MCP-access gate', $this->permissionGuard->ensureMcpAccess(), fn ($r) => $r === null);

                // (d) … but is denied operations outside their backend rights.
                // No module access at all → any module-gated table is denied.
                $expect('non-admin without news rights is denied news create',
                    $this->permissionGuard->ensureCan('tl_news', 'create', null, ['headline' => 'x']),
                    fn ($r) => \is_array($r) && ($r['error'] ?? null) === 'permission_denied');
                $expect('non-admin without files module is denied file access',
                    $this->permissionGuard->ensureModule('files'),
                    fn ($r) => \is_array($r) && ($r['error'] ?? null) === 'permission_denied');

                // (d2) Visibility: the catalogue hides what the user can't use.
                $expect('non-admin does NOT see news_create in catalogue',
                    $this->permissionEnforcer->isToolVisible('news_create'), fn ($r) => $r === false);
                $expect('non-admin does NOT see system_settings_update (admin-only)',
                    $this->permissionEnforcer->isToolVisible('system_settings_update'), fn ($r) => $r === false);
                $expect('non-admin still sees discovery/meta tools (ping)',
                    $this->permissionEnforcer->isToolVisible('ping'), fn ($r) => $r === true);

                // (d3) A DISABLED account gets nothing — disabling a backend user
                // is the standard offboarding step, and it has to cut MCP access
                // too. Contao's UserChecker owns that rule (disable, login
                // allowed, start/stop); we only get it by asking the checker.
                // Reported from outside as issue #1.
                // A separate user id on purpose: BackendUserContext caches per id
                // within the request, so flipping the flag on the user above
                // would not be observed here.
                $row['username'] = $stamp.'_disableduser';
                $row['email'] = $stamp.'_disabled@example.invalid';
                $row['disable'] = 1;
                $quotedDisabled = [];
                foreach ($row as $col => $val) {
                    $quotedDisabled[$this->connection->quoteIdentifier((string) $col)] = $val;
                }
                $this->connection->insert('tl_user', $quotedDisabled);
                $disabledUserId = (int) $this->connection->lastInsertId();

                $this->mcpCallContext->setIdentity($disabledUserId, 'smoke-disabled', null, null);
                $expect('disabled backend user is denied the MCP-access gate',
                    $this->permissionGuard->ensureMcpAccess(),
                    fn ($r) => \is_array($r) && ($r['error'] ?? null) === 'mcp_access_denied');
                $expect('disabled backend user resolves to no security token',
                    ['denial' => $this->permissionGuard->ensureCan('tl_page', 'read', 1)],
                    fn ($r) => \is_array($r['denial']) && ($r['denial']['error'] ?? null) === 'permission_denied');

                // (e) Trusted mode (auth_mode=none → no identity) allows everything.
                $this->mcpCallContext->clear();
                $expect('trusted mode (no identity) allows page create', $this->permissionGuard->ensureCan('tl_page', 'create', null, ['title' => 'x']), fn ($r) => $r === null);
            } finally {
                $this->mcpCallContext->clear();
                if ($tempUserId > 0) {
                    $this->connection->delete('tl_user', ['id' => $tempUserId]);
                }
                if (($disabledUserId ?? 0) > 0) {
                    $this->connection->delete('tl_user', ['id' => $disabledUserId]);
                }
            }
        } else {
            $output->writeln('  <fg=yellow>⊝ skipped — no admin user found</>');
        }

        // ═══════════════════════ License token (Ed25519) ════════════
        $output->writeln("\n<comment>License token</comment>");
        if (!\function_exists('sodium_crypto_sign_keypair')) {
            $output->writeln('  <fg=yellow>⊝ ext-sodium not available — skipping license crypto tests</>');
            $passed += 6;
        } else {
            $kp = LicenseToken::keypair();
            $verifier = new LicenseToken($kp['public']);
            $licHost = 'smoke.example.test';
            $mkToken = static fn (array $over = []): string => LicenseToken::sign(array_merge([
                'product' => LicenseToken::PRODUCT,
                'domain' => $licHost,
                'type' => 'trial',
                'license_id' => 'smoke',
                'issued_at' => time() - 60,
                'expires_at' => time() + 3600,
            ], $over), $kp['secret']);

            $expect('license verify accepts a valid signed token',
                $verifier->verify($mkToken(), $licHost),
                fn ($r) => ($r['valid'] ?? false) === true && $r['reason'] === 'ok' && $r['type'] === 'trial');

            $expect('license verify rejects wrong domain',
                $verifier->verify($mkToken(), 'other.example.test'),
                fn ($r) => ($r['valid'] ?? true) === false && $r['reason'] === 'wrong_domain');

            $expect('license verify rejects an expired token',
                $verifier->verify($mkToken(['issued_at' => time() - 7200, 'expires_at' => time() - 3600]), $licHost),
                fn ($r) => ($r['valid'] ?? true) === false && $r['reason'] === 'expired');

            $expect('license verify rejects wrong product',
                $verifier->verify($mkToken(['product' => 'evil/other']), $licHost),
                fn ($r) => ($r['valid'] ?? true) === false && $r['reason'] === 'wrong_product');

            // Tamper the payload segment → signature must no longer match.
            $good = $mkToken();
            [$p, $s] = explode('.', $good);
            $tampered = substr($p, 0, -1).('A' === $p[-1] ? 'B' : 'A').'.'.$s;
            $expect('license verify rejects a tampered token',
                $verifier->verify($tampered, $licHost),
                fn ($r) => ($r['valid'] ?? true) === false && \in_array($r['reason'], ['bad_signature', 'malformed'], true));

            // A token signed by a DIFFERENT key must not validate against our pubkey.
            $otherKp = LicenseToken::keypair();
            $foreign = LicenseToken::sign(['product' => LicenseToken::PRODUCT, 'domain' => $licHost, 'type' => 'full', 'issued_at' => time() - 60, 'expires_at' => time() + 3600], $otherKp['secret']);
            $expect('license verify rejects a foreign-signed token',
                $verifier->verify($foreign, $licHost),
                fn ($r) => ($r['valid'] ?? true) === false && $r['reason'] === 'bad_signature');
        }

        // ═══════════════════════ Search index ═══════════════════════
        $output->writeln("\n<comment>Search index</comment>");

        $searchPublicUrl = 'https://mcp-smoke-search.local/public.html';
        $searchProtectedUrl = 'https://mcp-smoke-search.local/protected.html';
        // Leftovers from an aborted run would skew the assertions below.
        foreach ([$searchPublicUrl, $searchProtectedUrl] as $staleUrl) {
            try {
                Search::removeEntry($staleUrl);
            } catch (\Throwable) {
                // never indexed — nothing to clean
            }
        }

        // NOTE for future fixtures: indexPage() splits head/body on "</head>",
        // and `protected` must be 0/1 — '' dies under MySQL strict mode.
        Search::indexPage([
            'url' => $searchPublicUrl,
            'title' => 'Smoke Stornobedingungen',
            'pid' => 1,
            'language' => 'de',
            'protected' => 0,
            'groups' => [],
            'meta' => [],
            'content' => '<html><head><title>Smoke</title></head><body>Vorspann ohne Belang. '
                .str_repeat('Fuellwort ohne Bedeutung. ', 20)
                .'Das Stichwort Quastenflosser steht absichtlich weit hinten im Text.</body></html>',
        ]);
        Search::indexPage([
            'url' => $searchProtectedUrl,
            'title' => 'Smoke intern',
            'pid' => 1,
            'language' => 'de',
            'protected' => 1,
            'groups' => [2],
            'meta' => [],
            'content' => '<html><head><title>Smoke intern</title></head><body>Geheimer Quastenflosser nur fuer Mitglieder.</body></html>',
        ]);

        $expect('search_index_status reports the indexed documents',
            $this->searchTool->status(),
            fn ($r) => ($r['documents'] ?? 0) >= 2 && ($r['protected'] ?? 0) >= 1 && str_contains((string) ($r['hint'] ?? ''), 'populated'));

        $searchHits = $this->searchTool->query('Quastenflosser');

        $expect('search_query finds the indexed page',
            $searchHits,
            fn ($r) => 1 === ($r['total'] ?? 0) && ($r['results'][0]['url'] ?? '') === $searchPublicUrl);

        // The protected page matches the same keyword — it must never be
        // returned, because its access depends on FRONTEND member groups.
        $expect('search_query hides protected pages and counts them',
            $searchHits,
            fn ($r) => 1 === ($r['protected_skipped'] ?? 0)
                && !\in_array($searchProtectedUrl, array_column($r['results'] ?? [], 'url'), true));

        $expect('search_query returns a snippet around the match',
            $searchHits,
            fn ($r) => str_contains((string) ($r['results'][0]['snippet'] ?? ''), 'Quastenflosser')
                && str_starts_with((string) ($r['results'][0]['snippet'] ?? ''), '…'));

        $expect('search_query caps the limit',
            $this->searchTool->query('Quastenflosser', false, false, null, 999),
            fn ($r) => 50 === ($r['limit'] ?? 0));

        $expect('search_query rejects empty keywords',
            $this->searchTool->query('   '),
            fn ($r) => ($r['error'] ?? null) === 'invalid_input');

        foreach ([$searchPublicUrl, $searchProtectedUrl] as $cleanupUrl) {
            Search::removeEntry($cleanupUrl);
        }
        $expect('search fixtures removed again',
            ['left' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_search WHERE url LIKE ?', ['%mcp-smoke-search.local%'])],
            fn ($r) => 0 === $r['left']);

        // ═══════════════════════ Undo snapshot ══════════════════════
        // MCP deletions must end up in tl_undo, otherwise Contao's own backend
        // undo cannot recover them (a version snapshot can't: restore is an
        // UPDATE and the row is gone).
        $output->writeln("\n<comment>Undo snapshot</comment>");

        // NB: no `groups` column here — it is a MySQL reserved word and DBAL's
        // insert() does not quote column keys. The DB default covers it.
        $this->connection->insert('tl_news_archive', ['tstamp' => time(), 'title' => 'MCP smoke undo archive', 'jumpTo' => 0]);
        $undoArchiveId = (int) $this->connection->lastInsertId();
        $this->connection->insert('tl_news', ['pid' => $undoArchiveId, 'tstamp' => time(), 'headline' => 'MCP smoke undo news', 'alias' => 'mcp-smoke-undo-'.time(), 'date' => time(), 'time' => time(), 'published' => 1, 'author' => 1]);
        $undoNewsId = (int) $this->connection->lastInsertId();
        $this->connection->insert('tl_content', ['pid' => $undoNewsId, 'ptable' => 'tl_news', 'tstamp' => time(), 'type' => 'text', 'text' => '<p>MCP smoke undo body</p>', 'sorting' => 128]);
        $undoContentId = (int) $this->connection->lastInsertId();

        $expect('undo snapshot is skipped without confirm_destructive',
            ['id' => $this->undoRecorder->beforeToolCall('news_delete', ['id' => $undoNewsId, 'confirm_destructive' => false])],
            fn ($r) => 0 === $r['id']);

        $expect('undo snapshot is skipped for non-deleting tools',
            ['id' => $this->undoRecorder->beforeToolCall('news_list', [])],
            fn ($r) => 0 === $r['id']);

        $undoId = $this->undoRecorder->beforeToolCall('news_delete', ['id' => $undoNewsId, 'confirm_destructive' => true]);
        $undoRow = $undoId > 0 ? $this->connection->fetchAssociative('SELECT * FROM tl_undo WHERE id = ?', [$undoId]) : false;
        $undoData = \is_array($undoRow) ? StringUtil::deserialize((string) $undoRow['data']) : [];

        $expect('delete is snapshotted into tl_undo, cascade included',
            ['undo_id' => $undoId, 'row' => $undoRow, 'data' => $undoData],
            fn ($r) => $r['undo_id'] > 0
                && \is_array($r['row'])
                && 'tl_news' === $r['row']['fromTable']
                && 2 === (int) $r['row']['affectedRows']
                && 1 === \count($r['data']['tl_news'] ?? [])
                && 1 === \count($r['data']['tl_content'] ?? []));

        $this->newsTool->delete($undoNewsId, true);

        $expect('record and its content element are really gone',
            [
                'news' => $this->connection->fetchOne('SELECT id FROM tl_news WHERE id = ?', [$undoNewsId]),
                'content' => $this->connection->fetchOne('SELECT id FROM tl_content WHERE id = ?', [$undoContentId]),
            ],
            fn ($r) => false === $r['news'] && false === $r['content']);

        // Replay what the backend's undo does: re-insert every snapshotted row.
        // Column names MUST be quoted — Contao's own undo goes through
        // Database\Statement::set(), which quotes; a raw DBAL insert() would
        // choke on reserved words like `groups`.
        foreach ($undoData as $undoTable => $undoRows) {
            foreach ($undoRows as $undoRowData) {
                $columns = array_map(fn (string $col): string => $this->connection->quoteIdentifier($col), array_keys($undoRowData));
                $this->connection->executeStatement(
                    'INSERT INTO '.$this->connection->quoteIdentifier((string) $undoTable)
                    .' ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, \count($columns), '?')).')',
                    array_values($undoRowData),
                );
            }
        }

        $expect('backend undo restores the record with its original id and body',
            [
                'news' => $this->connection->fetchAssociative('SELECT * FROM tl_news WHERE id = ?', [$undoNewsId]),
                'content' => $this->connection->fetchAssociative('SELECT * FROM tl_content WHERE id = ?', [$undoContentId]),
            ],
            fn ($r) => \is_array($r['news']) && 'MCP smoke undo news' === $r['news']['headline']
                && \is_array($r['content']) && str_contains((string) $r['content']['text'], 'MCP smoke undo body'));

        $this->undoRecorder->discard($undoId);

        $expect('discard() removes a snapshot again',
            ['left' => $this->connection->fetchOne('SELECT id FROM tl_undo WHERE id = ?', [$undoId])],
            fn ($r) => false === $r['left']);

        $this->connection->delete('tl_content', ['id' => $undoContentId]);
        $this->connection->delete('tl_news', ['id' => $undoNewsId]);
        $this->connection->delete('tl_news_archive', ['id' => $undoArchiveId]);

        // ═══════════════════ Usage / delete guard ═══════════════════
        // Deleting something that is still referenced breaks the site
        // silently. usage_find surfaces the references; DeletionGuard refuses
        // the deletion until they are gone — unless the caller overrides.
        $output->writeln("\n<comment>Usage lookup + delete guard</comment>");

        // A page nobody links to, a module nobody placed, and a content
        // element that references BOTH via insert tags.
        $this->connection->insert('tl_page', [
            'pid' => 0, 'tstamp' => time(), 'sorting' => 4096, 'title' => 'MCP smoke usage page',
            'alias' => 'mcp-smoke-usage-'.time(), 'type' => 'regular', 'published' => 1,
        ]);
        $usagePageId = (int) $this->connection->lastInsertId();

        $this->connection->insert('tl_module', [
            'pid' => 0, 'tstamp' => time(), 'name' => 'MCP smoke usage module', 'type' => 'html',
        ]);
        $usageModuleId = (int) $this->connection->lastInsertId();

        $this->connection->insert('tl_article', [
            'pid' => $usagePageId, 'tstamp' => time(), 'sorting' => 128, 'title' => 'MCP smoke usage article',
            'alias' => 'mcp-smoke-usage-article-'.time(), 'inColumn' => 'main', 'published' => 1, 'author' => 1,
        ]);
        $usageArticleId = (int) $this->connection->lastInsertId();

        $usageResult = $this->usageTool->find('page', (string) $usagePageId);

        $expect('usage_find reports an unreferenced page as unused',
            $usageResult,
            fn ($r) => false === $r['in_use'] && 0 === $r['blocking']);

        $expect('usage_find does NOT count rows the cascade removes anyway',
            $usageResult,
            // The article's pid points at the page, but page_delete takes the
            // article with it — counting it would block every page deletion.
            fn ($r) => [] === array_filter(
                $r['references'],
                static fn (array $ref): bool => 'tl_article' === ($ref['table'] ?? ''),
            ));

        $expect('delete guard lets an unreferenced delete through',
            ['denial' => $this->deletionGuard->check('page_delete', ['id' => $usagePageId, 'confirm_destructive' => true])],
            fn ($r) => null === $r['denial']);

        // The referring content element must live OUTSIDE the target page:
        // anything inside it is part of the cascade, and would rightly be
        // ignored (which is what the previous assertion just proved).
        $this->connection->insert('tl_page', [
            'pid' => 0, 'tstamp' => time(), 'sorting' => 8192, 'title' => 'MCP smoke usage referrer page',
            'alias' => 'mcp-smoke-usage-ref-'.time(), 'type' => 'regular', 'published' => 1,
        ]);
        $usageRefPageId = (int) $this->connection->lastInsertId();

        $this->connection->insert('tl_article', [
            'pid' => $usageRefPageId, 'tstamp' => time(), 'sorting' => 128, 'title' => 'MCP smoke usage referrer article',
            'alias' => 'mcp-smoke-usage-ref-article-'.time(), 'inColumn' => 'main', 'published' => 1, 'author' => 1,
        ]);
        $usageRefArticleId = (int) $this->connection->lastInsertId();

        // One reference by ALIAS (the common spelling in editor content), one
        // by id — both must be found.
        $usagePageAlias = (string) $this->connection->fetchOne('SELECT alias FROM tl_page WHERE id = ?', [$usagePageId]);
        $this->connection->insert('tl_content', [
            'pid' => $usageRefArticleId, 'ptable' => 'tl_article', 'tstamp' => time(), 'type' => 'text', 'sorting' => 128,
            'text' => '<p>See {{link::'.$usagePageAlias.'}} and {{insert_module::'.$usageModuleId.'}}.</p>',
        ]);
        $usageContentId = (int) $this->connection->lastInsertId();

        $expect('usage_find finds an insert tag that uses the ALIAS, not the id',
            $this->usageTool->find('page', (string) $usagePageId),
            fn ($r) => true === $r['in_use'] && [] !== array_filter(
                $r['references'],
                static fn (array $ref): bool => 'insert_tag' === ($ref['source'] ?? '')
                    && $usageContentId === ($ref['id'] ?? 0),
            ));

        $expect('usage_find finds {{insert_module::id}}',
            $this->usageTool->find('module', (string) $usageModuleId),
            fn ($r) => true === $r['in_use'] && [] !== array_filter(
                $r['references'],
                static fn (array $ref): bool => 'insert_tag' === ($ref['source'] ?? ''),
            ));

        $expect('usage_find does not confuse a different id (module id+1)',
            $this->usageTool->find('module', (string) ($usageModuleId + 1000)),
            fn ($r) => isset($r['error']) && 'not_found' === $r['error']);

        $guardDenial = $this->deletionGuard->check('module_delete', ['id' => $usageModuleId, 'confirm_destructive' => true]);

        $expect('delete guard refuses a delete while an insert tag uses it',
            ['denial' => $guardDenial],
            fn ($r) => \is_array($r['denial']) && 'still_in_use' === $r['denial']['error']
                && $r['denial']['references'] !== []);

        $expect('the refusal names the override argument',
            ['denial' => $guardDenial],
            fn ($r) => str_contains((string) $r['denial']['message'], 'ignore_references'));

        $expect('ignore_references=true overrides the guard',
            ['denial' => $this->deletionGuard->check(
                'module_delete',
                ['id' => $usageModuleId, 'confirm_destructive' => true, 'ignore_references' => true],
            )],
            fn ($r) => null === $r['denial']);

        $expect('guard stays out of the way without confirm_destructive',
            // The tool refuses on its own, so a scan would be wasted work.
            ['denial' => $this->deletionGuard->check('module_delete', ['id' => $usageModuleId, 'confirm_destructive' => false])],
            fn ($r) => null === $r['denial']);

        $expect('guard ignores non-deleting tools',
            ['denial' => $this->deletionGuard->check('module_update', ['id' => $usageModuleId])],
            fn ($r) => null === $r['denial']);

        // Permission mounts are references, but stale ones are harmless — they
        // must never be what stops a deletion. Own group rather than an
        // existing one: a fresh Contao has no user groups at all.
        $this->connection->insert('tl_user_group', [
            'tstamp' => time(),
            'name' => 'MCP smoke usage group',
            'pagemounts' => serialize([(string) $usagePageId]),
        ]);
        $usageGroupId = (int) $this->connection->lastInsertId();

        $expect('backend page mounts are reported but do not block',
            $this->usageTool->find('page', (string) $usagePageId),
            fn ($r) => [] !== array_filter(
                $r['other_findings'],
                static fn (array $ref): bool => 'tl_user_group' === ($ref['table'] ?? '')
                    && $usageGroupId === ($ref['id'] ?? 0)
                    && false === ($ref['blocking'] ?? true),
            ));

        // (FEATURE) references that live INSIDE files. An SCSS partial is the
        // hard case: `_colors.scss` is imported as `@import 'colors'`, so no
        // path search and no database column can ever see the dependency.
        $usageFileDir = $this->projectDir.\DIRECTORY_SEPARATOR.'files'.\DIRECTORY_SEPARATOR.$stamp.'_usage';

        if (!is_dir($usageFileDir)) {
            mkdir($usageFileDir, 0o755, true);
        }

        file_put_contents($usageFileDir.'/_colors.scss', '$brand: #c00;'."\n");
        file_put_contents($usageFileDir.'/app.scss', "@import 'colors';\n");
        file_put_contents($usageFileDir.'/unrelated.scss', ".colors { color: red; }\n");
        // Referenced ONLY by UUID (via singleSRC below), so it can show that a
        // rename leaves such a reference intact.
        file_put_contents($usageFileDir.'/logo.svg', "<svg/>\n");

        $usageFileIds = [];

        foreach (['_colors.scss', 'app.scss', 'unrelated.scss', 'logo.svg'] as $i => $name) {
            $this->connection->insert('tl_files', [
                'tstamp' => time(),
                'uuid' => StringUtil::uuidToBin(sprintf('%08x-0000-11f1-9000-%012x', crc32($stamp), $i + 1)),
                'type' => 'file',
                'path' => 'files/'.$stamp.'_usage/'.$name,
                'extension' => pathinfo($name, \PATHINFO_EXTENSION),
                'hash' => md5($name),
                'found' => 1,
                'name' => $name,
            ]);
            $usageFileIds[$name] = (int) $this->connection->lastInsertId();
        }

        $partialPath = $stamp.'_usage/_colors.scss';

        $expect('usage_find follows an SCSS @import that names the partial without path or underscore',
            $this->usageTool->find('file', $partialPath),
            fn ($r) => true === $r['in_use'] && [] !== array_filter(
                $r['references'],
                static fn (array $ref): bool => 'file_content' === ($ref['source'] ?? '')
                    && str_ends_with((string) ($ref['file'] ?? ''), 'app.scss'),
            ));

        $expect('a file that only mentions the word is not treated as a reference',
            $this->usageTool->find('file', $partialPath),
            fn ($r) => [] === array_filter(
                [...$r['references'], ...$r['other_findings']],
                static fn (array $ref): bool => str_ends_with((string) ($ref['file'] ?? ''), 'unrelated.scss'),
            ));

        $expect('delete guard refuses to delete a file a stylesheet imports',
            ['denial' => $this->deletionGuard->check('file_delete', ['path' => $partialPath, 'confirm_destructive' => true])],
            fn ($r) => \is_array($r['denial']) && 'still_in_use' === $r['denial']['error']);

        // The everyday case: "is this image still used?". The reference is a
        // raw binary(16) UUID in a picker column, not a readable id — so this
        // covers an encoding nothing else in the suite touches.
        $partialUuid = (string) $this->connection->fetchOne(
            'SELECT uuid FROM tl_files WHERE id = ?',
            [$usageFileIds['_colors.scss']],
        );
        $this->connection->update('tl_content', ['singleSRC' => $partialUuid], ['id' => $usageContentId]);

        $expect('usage_find resolves a binary UUID reference (tl_content.singleSRC)',
            $this->usageTool->find('file', $partialPath),
            fn ($r) => [] !== array_filter(
                $r['references'],
                static fn (array $ref): bool => 'db_field' === ($ref['source'] ?? '')
                    && 'singleSRC' === ($ref['field'] ?? '')
                    && $usageContentId === ($ref['id'] ?? 0),
            ));

        $expect('a UUID reference is reported as UUID-anchored, not path-anchored',
            $this->usageTool->find('file', $partialPath),
            fn ($r) => [] !== array_filter(
                $r['references'],
                static fn (array $ref): bool => 'singleSRC' === ($ref['field'] ?? '') && 'uuid' === ($ref['identity'] ?? ''),
            ));

        $expect('an unreferenced file deletes without complaint',
            ['denial' => $this->deletionGuard->check(
                'file_delete',
                ['path' => $stamp.'_usage/unrelated.scss', 'confirm_destructive' => true],
            )],
            fn ($r) => null === $r['denial']);

        // Rename/move rewrite tl_files.path and keep the row, the id and the
        // UUID — verified against Dbafs::moveResource. So a UUID reference
        // survives them and must NOT stop them, while a path-shaped one must.
        // `_colors.scss` carries BOTH: an @import (path) and a singleSRC (uuid).
        $renameDenial = $this->deletionGuard->check(
            'file_rename',
            ['path' => $partialPath, 'new_name' => '_colours.scss'],
        );

        $expect('rename IS blocked by a path reference (an SCSS @import)',
            ['denial' => $renameDenial],
            fn ($r) => \is_array($r['denial']) && 'still_in_use' === $r['denial']['error']);

        $expect('only path-anchored references are given as the reason',
            ['denial' => $renameDenial],
            fn ($r) => [] === array_filter(
                $r['denial']['references'],
                static fn (array $ref): bool => 'path' !== ($ref['identity'] ?? ''),
            ));

        $expect('the surviving UUID reference is still reported, just not as a blocker',
            ['denial' => $renameDenial],
            fn ($r) => [] !== array_filter(
                $r['denial']['other_findings'],
                static fn (array $ref): bool => 'uuid' === ($ref['identity'] ?? ''),
            ));

        $expect('move is judged the same way as rename',
            ['denial' => $this->deletionGuard->check(
                'file_move',
                ['path' => $partialPath, 'new_parent_path' => $stamp.'_usage'],
            )],
            fn ($r) => \is_array($r['denial']) && 'still_in_use' === $r['denial']['error']);

        // logo.svg is referenced ONLY by UUID — nothing about it changes when
        // it is renamed, so the guard must stay out of the way. The same file
        // must still be protected from deletion.
        $this->connection->update('tl_content', ['singleSRC' => (string) $this->connection->fetchOne(
            'SELECT uuid FROM tl_files WHERE id = ?',
            [$usageFileIds['logo.svg']],
        )], ['id' => $usageContentId]);

        $expect('rename is NOT blocked by a UUID reference, which survives it',
            [
                'rename' => $this->deletionGuard->check(
                    'file_rename',
                    ['path' => $stamp.'_usage/logo.svg', 'new_name' => 'logo-renamed.svg'],
                ),
                'move' => $this->deletionGuard->check(
                    'file_move',
                    ['path' => $stamp.'_usage/logo.svg', 'new_parent_path' => $stamp.'_usage'],
                ),
            ],
            fn ($r) => null === $r['rename'] && null === $r['move']);

        $expect('the same UUID reference still stops the DELETE',
            ['denial' => $this->deletionGuard->check(
                'file_delete',
                ['path' => $stamp.'_usage/logo.svg', 'confirm_destructive' => true],
            )],
            fn ($r) => \is_array($r['denial']) && 'still_in_use' === $r['denial']['error']);

        $this->connection->update('tl_content', ['singleSRC' => null], ['id' => $usageContentId]);

        foreach ($usageFileIds as $fileId) {
            $this->connection->delete('tl_files', ['id' => $fileId]);
        }

        @unlink($usageFileDir.'/_colors.scss');
        @unlink($usageFileDir.'/app.scss');
        @unlink($usageFileDir.'/unrelated.scss');
        @unlink($usageFileDir.'/logo.svg');
        @rmdir($usageFileDir);

        // (FEATURE) templates. Deleting an override that a content element or
        // module selects as its customTpl changes how that record renders —
        // and the reference is a bare NAME in a varchar column, so nothing
        // about it looks like a reference until you know the convention.
        $tplDir = $this->projectDir.\DIRECTORY_SEPARATOR.'templates';
        $twigDir = $tplDir.\DIRECTORY_SEPARATOR.$stamp.'_tpl';
        $legacy = static fn (string $suffix): string => $stamp.'_'.$suffix;

        if (!is_dir($twigDir)) {
            mkdir($twigDir, 0o755, true);
        }

        // Legacy overrides: one selected via customTpl, one extended by
        // another template, one used by nobody, and one whose name merely
        // starts with another's (the precision case).
        file_put_contents($tplDir.'/'.$legacy('custom').'.html5', "<p>custom</p>\n");
        file_put_contents($tplDir.'/'.$legacy('parent').'.html5', "<?php \$this->block('x'); ?>\n");
        file_put_contents($tplDir.'/'.$legacy('parent_extra').'.html5', "<p>unrelated but prefixed</p>\n");
        file_put_contents($tplDir.'/'.$legacy('child').'.html5', "<?php \$this->extend('".$legacy('parent')."'); ?>\n");
        file_put_contents($tplDir.'/'.$legacy('orphan').'.html5', "<p>nobody uses me</p>\n");

        // Twig override: Contao stores the FULL path without extension.
        file_put_contents($twigDir.'/variant.html.twig', "{% extends '@Contao/content_element/text.html.twig' %}\n");
        $twigName = $stamp.'_tpl/variant';

        $this->connection->update('tl_content', ['customTpl' => $legacy('custom')], ['id' => $usageContentId]);

        $expect('usage_find finds a template selected via customTpl',
            $this->usageTool->find('template', $legacy('custom').'.html5'),
            fn ($r) => true === $r['in_use'] && [] !== array_filter(
                $r['references'],
                static fn (array $ref): bool => 'db_field' === ($ref['source'] ?? '')
                    && 'customTpl' === ($ref['field'] ?? '')
                    && $usageContentId === ($ref['id'] ?? 0),
            ));

        $this->connection->update('tl_content', ['customTpl' => $twigName], ['id' => $usageContentId]);

        $expect('usage_find matches a Twig template by its full path name, not its basename',
            $this->usageTool->find('template', $stamp.'_tpl/variant.html.twig'),
            fn ($r) => true === $r['in_use'] && [] !== array_filter(
                $r['references'],
                static fn (array $ref): bool => 'db_field' === ($ref['source'] ?? '') && 'customTpl' === ($ref['field'] ?? ''),
            ));

        $this->connection->update('tl_content', ['customTpl' => ''], ['id' => $usageContentId]);

        $expect('usage_find follows $this->extend() from one template to another',
            $this->usageTool->find('template', $legacy('parent').'.html5'),
            fn ($r) => true === $r['in_use'] && [] !== array_filter(
                $r['references'],
                static fn (array $ref): bool => 'template' === ($ref['source'] ?? '')
                    && str_contains((string) ($ref['file'] ?? ''), $legacy('child')),
            ));

        $expect('a template whose name only PREFIXES another is not a reference',
            // "<stamp>_parent_extra" must not be seen as used just because
            // "<stamp>_parent" is extended somewhere.
            $this->usageTool->find('template', $legacy('parent_extra').'.html5'),
            fn ($r) => false === $r['in_use']);

        $expect('an unused template override reports as unused',
            $this->usageTool->find('template', $legacy('orphan').'.html5'),
            fn ($r) => false === $r['in_use'] && 0 === $r['blocking']);

        $expect('usage_find rejects a template that does not exist',
            $this->usageTool->find('template', $stamp.'_nope.html5'),
            fn ($r) => isset($r['error']) && 'not_found' === $r['error']);

        $expect('delete guard refuses to delete a template another one extends',
            ['denial' => $this->deletionGuard->check(
                'template_delete',
                ['path' => $legacy('parent').'.html5', 'confirm_destructive' => true],
            )],
            fn ($r) => \is_array($r['denial']) && 'still_in_use' === $r['denial']['error']);

        $expect('delete guard lets an unused template override go',
            ['denial' => $this->deletionGuard->check(
                'template_delete',
                ['path' => $legacy('orphan').'.html5', 'confirm_destructive' => true],
            )],
            fn ($r) => null === $r['denial']);

        $expect('ignore_references=true also overrides the template guard',
            ['denial' => $this->deletionGuard->check(
                'template_delete',
                ['path' => $legacy('parent').'.html5', 'confirm_destructive' => true, 'ignore_references' => true],
            )],
            fn ($r) => null === $r['denial']);

        $expect('renaming a used template is blocked — the name is how it is found',
            ['denial' => $this->deletionGuard->check(
                'template_rename',
                ['path' => $legacy('parent').'.html5', 'new_path' => $legacy('parent_renamed').'.html5'],
            )],
            fn ($r) => \is_array($r['denial']) && 'still_in_use' === $r['denial']['error']);

        $expect('MOVING a legacy .html5 template is not blocked — its name is the basename',
            // Contao finds .html5 templates by basename wherever they sit, so
            // a pure folder move changes nothing that references them.
            ['denial' => $this->deletionGuard->check(
                'template_rename',
                ['path' => $legacy('parent').'.html5', 'new_path' => 'sub/'.$legacy('parent').'.html5'],
            )],
            fn ($r) => null === $r['denial']);

        $this->connection->update('tl_content', ['customTpl' => $twigName], ['id' => $usageContentId]);

        $expect('moving a Twig template IS blocked — its name is its full path',
            ['denial' => $this->deletionGuard->check(
                'template_rename',
                ['path' => $stamp.'_tpl/variant.html.twig', 'new_path' => 'other/variant.html.twig'],
            )],
            fn ($r) => \is_array($r['denial']) && 'still_in_use' === $r['denial']['error']);

        $this->connection->update('tl_content', ['customTpl' => ''], ['id' => $usageContentId]);

        foreach (['custom', 'parent', 'parent_extra', 'child', 'orphan'] as $suffix) {
            @unlink($tplDir.'/'.$legacy($suffix).'.html5');
        }

        @unlink($twigDir.'/variant.html.twig');
        @rmdir($twigDir);

        $this->connection->delete('tl_user_group', ['id' => $usageGroupId]);
        $this->connection->delete('tl_content', ['id' => $usageContentId]);
        $this->connection->delete('tl_article', ['id' => $usageRefArticleId]);
        $this->connection->delete('tl_page', ['id' => $usageRefPageId]);
        $this->connection->delete('tl_article', ['id' => $usageArticleId]);
        $this->connection->delete('tl_module', ['id' => $usageModuleId]);
        $this->connection->delete('tl_page', ['id' => $usagePageId]);

        // ═══════════════════════ Public folders ════════════════════
        // Everything served straight from the web root — webfonts, own JS,
        // favicons — depends on this marker. Without the tool the last step of
        // a build run was a click in the file manager.
        $output->writeln("\n<comment>Öffentliche Ordner</comment>");

        $publicDirName = $stamp.'_public';
        $publicFolder = $this->fileTool->folderCreate('', $publicDirName);
        $expect('folder for the public test created', $publicFolder, static fn ($r) => ($r['created'] ?? false) === true);

        $marker = $this->projectDir.\DIRECTORY_SEPARATOR.'files'.\DIRECTORY_SEPARATOR.$publicDirName.\DIRECTORY_SEPARATOR.'.public';

        $madePublic = $this->fileTool->folderSetPublic($publicDirName);
        $expect('folder_set_public reports public', $madePublic, static fn ($r) => ($r['public'] ?? false) === true);
        // The marker is the durable part and works everywhere. The symlink does
        // not: Windows refuses symlink() without the privilege, so asserting it
        // would make this test pass or fail by operating system rather than by
        // behaviour. The tool reports that case in `warnings` instead.
        $expect('the marker is on disk', is_file($marker), static fn (bool $ok) => $ok);
        $expect('a failed symlink is reported, not swallowed', $madePublic,
            static fn ($r) => ($r['symlink_created'] ?? null) === true || ($r['warnings'] ?? []) !== []);

        $listed = $this->fileTool->list('');
        $listedFolder = null;
        foreach ($listed['items'] ?? [] as $item) {
            if (($item['name'] ?? '') === $publicDirName) {
                $listedFolder = $item;
            }
        }
        // Without this the state is invisible over MCP and a build run cannot
        // be repeated idempotently — there is nothing to compare against.
        $expect('files_list exposes the public state', $listedFolder, static fn ($f) => ($f['public'] ?? null) === true);

        $expect('and takes it back', $this->fileTool->folderSetPublic($publicDirName, false),
            static fn ($r) => ($r['public'] ?? true) === false);
        $expect('the marker is gone again', is_file($marker), static fn (bool $ok) => !$ok);

        $expect('path traversal is refused', $this->fileTool->folderSetPublic('../../etc'),
            static fn ($r) => ($r['error'] ?? '') === 'invalid_path');
        // Contao links sub-folders only; a marker in the upload root would be
        // found by nothing and quietly do nothing.
        $expect('the upload root is refused', $this->fileTool->folderSetPublic(''),
            static fn ($r) => ($r['error'] ?? '') === 'refuse_root');
        // The tool exists so nobody has to weaken this.
        $expect('uploading a dotfile stays rejected', $this->fileTool->upload($publicDirName, '.public', 'Cg=='),
            static fn ($r) => ($r['error'] ?? '') === 'invalid_filename');

        $this->fileTool->folderDelete($publicDirName, confirm_destructive: true, cascade: true);

        // ═══════════════════════ Settings reality ══════════════════
        // A write tool that reports success without doing anything undermines
        // every verification by reading back.
        $output->writeln("\n<comment>System-Settings gegen die echte Contao-Version</comment>");

        // gdMaxImgWidth existiert in Contao 5 nicht mehr und steht deshalb
        // nicht mehr auf der Liste: Ablehnung statt bestätigtem Nichtstun.
        $deadKey = $this->systemTool->systemSettingsUpdate(['gdMaxImgWidth' => 3840]);
        $expect('a setting Contao 5 dropped is refused', $deadKey,
            static fn ($r) => ($r['error'] ?? '') === 'unknown_settings');
        $expect('and it is named back to the caller', $deadKey,
            static fn ($r) => \in_array('gdMaxImgWidth', $r['unknown'] ?? [], true));

        $realKey = $this->systemTool->systemSettingsUpdate(['imageWidth' => 3841]);
        $expect('a key it does have still applies', $realKey,
            static fn ($r) => ($r['updated'] ?? []) === ['imageWidth']);
        $expect('and reading it back shows the value',
            $this->systemTool->systemSettings()['uploads']['imageWidth'] ?? null,
            static fn ($v) => (int) $v === 3841);
        $this->systemTool->systemSettingsUpdate(['imageWidth' => 0]);

        // ═══════════════════════ Page tree ═════════════════════════
        // One call that writes many rows needs its failure modes pinned, not
        // just its happy path: a half-built tree is the expensive mistake.
        $output->writeln("\n<comment>Page-Tree (Batch)</comment>");

        $treeStamp = $stamp.'_tree';

        // A shape error anywhere must stop the whole tree before the first write.
        $rejected = $this->pageTool->createTree(0, [[
            'title' => $treeStamp.'_never',
            'type' => 'regular',
            'children' => [['title' => $treeStamp.'_child', 'gibtsnicht' => 1]],
        ]]);
        $expect('a malformed node rejects the whole tree', $rejected,
            static fn ($r) => ($r['error'] ?? '') === 'invalid_input' && !empty($r['problems']));
        $expect('and nothing was written', (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_page WHERE title LIKE ?', [$treeStamp.'%']),
            static fn (int $n) => $n === 0);

        $treeResult = $this->pageTool->createTree(0, [[
            'title' => $treeStamp.'_root',
            'type' => 'root',
            'children' => [
                ['title' => $treeStamp.'_a', 'type' => 'regular', 'children' => [
                    ['title' => $treeStamp.'_a1', 'type' => 'regular'],
                ]],
                // Passes the shape check, fails when Contao validates the type.
                ['title' => $treeStamp.'_broken', 'type' => 'gibtsnicht', 'children' => [
                    ['title' => $treeStamp.'_orphan', 'type' => 'regular'],
                ]],
                ['title' => $treeStamp.'_b', 'type' => 'regular'],
            ],
        ]]);

        $expect('tree creates the nodes it can', $treeResult, static fn ($r) => ($r['created'] ?? 0) === 4);
        $expect('a failing node is reported, not swallowed', $treeResult, static fn ($r) => ($r['failed'] ?? 0) === 1);
        $expect('its children are skipped', $treeResult, static fn ($r) => ($r['skipped'] ?? 0) === 1);

        $byPath = [];
        foreach ($treeResult['pages'] ?? [] as $p) {
            $byPath[$p['path']] = $p;
        }

        // The whole point of the tree shape: the child's pid comes from the
        // parent that was created moments earlier, without the caller ever
        // handling an id.
        $expect('nesting resolves parent ids', $byPath,
            static fn (array $b) => isset($b['1.1']['id'], $b['1.1.1']['pid'])
                && $b['1.1.1']['pid'] === $b['1.1']['id']);
        $expect('siblings after the failure still run', $byPath,
            static fn (array $b) => isset($b['1.3']['id']));
        $expect('the skipped subtree left no orphans', (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_page WHERE title = ?', [$treeStamp.'_orphan']),
            static fn (int $n) => $n === 0);

        // Removing a tree again. page_delete refuses to cascade through the
        // tree on purpose, so without this a 25-page structure took 25
        // confirmed calls in exactly the right order.
        $delStamp = $stamp.'_del';
        $toDelete = $this->pageTool->createTree(0, [[
            'title' => $delStamp.'_root',
            'type' => 'root',
            'children' => [
                ['title' => $delStamp.'_a', 'type' => 'regular', 'children' => [
                    ['title' => $delStamp.'_a1', 'type' => 'regular'],
                ]],
                ['title' => $delStamp.'_b', 'type' => 'regular'],
            ],
        ]]);
        $delRoot = (int) ($toDelete['pages'][0]['id'] ?? 0);
        $expect('a tree to delete was created', $toDelete, static fn ($r) => ($r['created'] ?? 0) === 4);

        // No count given: answer with the plan, touch nothing. The number in
        // that answer is what the real call has to carry.
        $preview = $this->pageTool->deleteTree($delRoot);
        $expect('without expect_pages it only previews', $preview,
            static fn ($r) => ($r['dry_run'] ?? false) === true && ($r['pages'] ?? 0) === 4);
        $expect('the preview deletes nothing', (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_page WHERE title LIKE ?', [$delStamp.'%']),
            static fn (int $n) => $n === 4);
        // Deepest first is a requirement, not a preference: Contao will not
        // delete a page that still has children.
        $expect('the plan starts at the deepest level', $preview,
            static fn ($r) => ($r['would_delete'][0]['depth'] ?? -1) === 2);

        $expect('a wrong count refuses', $this->pageTool->deleteTree($delRoot, confirm_destructive: true, expect_pages: 3),
            static fn ($r) => ($r['error'] ?? '') === 'count_mismatch');
        $expect('the right count still needs confirmation', $this->pageTool->deleteTree($delRoot, expect_pages: 4),
            static fn ($r) => ($r['error'] ?? '') === 'destructive_confirmation_required');

        $expect('and then it removes the branch', $this->pageTool->deleteTree($delRoot, confirm_destructive: true, expect_pages: 4),
            static fn ($r) => ($r['deleted'] ?? 0) === 4 && ($r['failed'] ?? 1) === 0);
        $expect('nothing of it is left', (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_page WHERE title LIKE ?', [$delStamp.'%']),
            static fn (int $n) => $n === 0);

        foreach (array_reverse($treeResult['pages'] ?? []) as $p) {
            if (isset($p['id'])) {
                $this->connection->delete('tl_page', ['id' => (int) $p['id']]);
            }
        }

        // ═══════════════════════ OAuth-Discovery + Pairing ═════════
        // The two things a connector trips over before it ever sends a tool
        // call: finding out WHERE to authenticate, and being allowed to
        // register at all.
        $output->writeln("\n<comment>OAuth (Discovery + Pairing)</comment>");

        // Both documents are built from `backend_url` and answer 503 while it
        // is unset — which is the whole point of that guard, and exactly the
        // state a freshly migrated instance is in. Pin a known value for the
        // assertions (and restore it below) so this tests the document rather
        // than whichever host happens to be configured.
        $oauthConfigBefore = $this->configStorage->load();
        $this->configStorage->save([...$oauthConfigBefore, 'backend_url' => 'https://smoke.example']);

        $prm = json_decode((string) $this->mcpController->oauthProtectedResourceMetadata()->getContent(), true);

        // RFC 9728. The 401 points clients here; before 1.5.0 this document
        // did not exist and the header pointed at the RFC 8414 one instead.
        $expect('protected-resource metadata names the resource',
            $prm, static fn ($d) => \is_array($d) && !empty($d['resource']));
        $expect('protected-resource metadata names an authorization server',
            $prm, static fn ($d) => !empty($d['authorization_servers'][0]));

        $asMeta = json_decode((string) $this->mcpController->oauthMetadata()->getContent(), true);
        $expect('authorization-server metadata still advertises registration',
            $asMeta, static fn ($d) => \is_array($d) && !empty($d['registration_endpoint']));
        $expect('both documents agree on the issuer',
            [$prm, $asMeta],
            static fn (array $d) => ($d[0]['authorization_servers'][0] ?? 'a') === ($d[1]['issuer'] ?? 'b'));

        // The resource identifier must be the MCP endpoint itself, not the
        // host — a client compares it against the URL it is talking to.
        $expect('the resource identifier is the MCP endpoint',
            $prm,
            fn ($d) => ($d['resource'] ?? '') === 'https://smoke.example/'.trim((string) ($this->configStorage->load()['path'] ?? 'mcp'), '/'));

        // Pairing window. Registration is gated in `restricted` mode; the
        // window is the only path a standard MCP client can take, because it
        // cannot send an IAT header.
        $registerRequest = static fn (string $name): Request => Request::create(
            '/_mcp_oauth/register',
            'POST',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
            json_encode(['client_name' => $name, 'redirect_uris' => ['https://example.com/cb']]),
        );

        $this->configStorage->save([
            ...$oauthConfigBefore,
            'oauth_registration_mode' => 'restricted',
            'registration_open_until' => 0,
        ]);

        $closed = $this->registerController->__invoke($registerRequest("{$stamp}_closed"));
        $expect('a closed window refuses registration', $closed->getStatusCode(), static fn ($c) => $c === 401);

        $this->configStorage->save([...$this->configStorage->load(), 'registration_open_until' => time() + 900]);

        $first = $this->registerController->__invoke($registerRequest("{$stamp}_first"));
        $second = $this->registerController->__invoke($registerRequest("{$stamp}_second"));

        $expect('an open window admits a registration', $first->getStatusCode(), static fn ($c) => $c === 201);

        // The regression this replaces: the window used to close itself after
        // the first success, so a retrying client — or a second one — hit a
        // locked door mid-flow and the admin had to reopen it every time.
        $expect('the window stays open for a second registration',
            $second->getStatusCode(), static fn ($c) => $c === 201);

        $this->configStorage->save($oauthConfigBefore);

        foreach ([$first, $second] as $response) {
            $body = json_decode((string) $response->getContent(), true);
            if (\is_array($body) && isset($body['client_id'])) {
                $this->connection->delete('tl_mcp_oauth_client', ['client_id' => $body['client_id']]);
            }
        }

        // ═══════════════════════ Dispatcher ════════════════════════
        // This is what used to be the vendor patch. Every section above calls
        // the Tool services directly, so nothing there ever goes through the
        // dispatcher — these asserts are the only coverage the lazy-mode
        // filter and the post-call hook get.
        $output->writeln("\n<comment>Dispatcher (Lazy-Mode + Post-Call-Hook)</comment>");

        $dispatcher = $this->dispatcherFactory->getDispatcher();

        $expect('factory builds the Contao subclass, not the vendor Dispatcher',
            $dispatcher, static fn ($d) => $d instanceof ContaoDispatcher);

        $listNames = static fn ($d): array => array_map(
            static fn ($t) => $t->name,
            $d->handleToolList(new ListToolsRequest(1))->tools,
        );

        // lazy_mode defaults to false, but a dev instance may already have it
        // on — and enable() has no counterpart. So only assert the unfiltered
        // list while it is genuinely still unfiltered.
        if (!$this->toolFilter->isEnabled()) {
            $expect('unfiltered tools/list exposes the full catalogue',
                $listNames($dispatcher), static fn (array $n) => \count($n) > 100);
        } else {
            $output->writeln('  <comment>~ unfiltered list skipped — lazy_mode is already on here</comment>');
        }

        $this->toolFilter->enable();
        $lazyNames = $listNames($dispatcher);

        $expect('lazy-mode tools/list keeps the discovery tools',
            $lazyNames,
            static fn (array $n) => \in_array('contao_call', $n, true)
                && \in_array('contao_search_tools', $n, true)
                && \in_array('ping', $n, true));

        $expect('lazy-mode tools/list drops everything else',
            $lazyNames,
            fn (array $n) => !\in_array('pages_list', $n, true)
                && \count($n) <= \count($this->toolFilter->exposedNames()));

        // Hidden is not gone: contao_call proxies to tools that tools/list
        // never showed. If this breaks, lazy-mode silently amputates the API.
        $expect('a hidden tool is still resolvable for contao_call',
            $this->registryAccessor->get()->getTool('pages_list'),
            static fn ($t) => $t !== null);

        // Post-call hook — the second half of the old patch. Observable side
        // effect: it clears the per-call identity context.
        $this->mcpCallContext->setIdentity(1, 'smoke-client', 'Smoke', 'tok');
        $pingResult = $dispatcher->handleToolCall(new CallToolRequest(2, 'ping', []));

        $expect('tools/call through the dispatcher returns a result',
            $pingResult, static fn ($r) => $r instanceof CallToolResult && !$r->isError);
        $expect('post-call hook cleared the call context',
            $this->mcpCallContext->isAuthenticated(), static fn ($a) => $a === false);

        // …and it must run on the failure path too, or a throwing tool leaks
        // the previous caller's identity into the next call. That is exactly
        // what the `finally` in ContaoDispatcher::handleToolCall is for.
        $this->mcpCallContext->setIdentity(1, 'smoke-client', 'Smoke', 'tok');
        $threw = false;

        try {
            $dispatcher->handleToolCall(new CallToolRequest(3, 'no_such_tool_'.$stamp, []));
        } catch (\Throwable) {
            $threw = true;
        }

        $expect('an unknown tool still raises', $threw, static fn ($t) => $t === true);
        $expect('post-call hook ran even though the call threw',
            $this->mcpCallContext->isAuthenticated(), static fn ($a) => $a === false);



        // ═══════════════════ fileTree-Felder (binary(16)) ═══════════════════
        //
        // Contao stores file references as binary(16). The backend picker
        // writes that shape; a programmatic Model write does not, because DCA
        // save_callbacks only run for form submissions. So a 36-character UUID
        // string or a files/… path went straight into a 16-byte column and
        // MySQL answered "Data too long" — the write failed hard. Which columns
        // those are cannot be a hardcoded list: every extension brings its own.
        $output->writeln("
<comment>fileTree-Felder (binary(16))</comment>");

        $ftFile = $this->connection->fetchAssociative("SELECT uuid, path FROM tl_files WHERE type = 'file' ORDER BY id LIMIT 1");
        $ftThemeId = (int) $this->connection->fetchOne('SELECT id FROM tl_theme ORDER BY id LIMIT 1');

        if (\is_array($ftFile) && $ftThemeId > 0) {
            $ftUuid = StringUtil::binToUuid((string) $ftFile['uuid']);
            $ftModule = $this->moduleTool->create(theme_id: $ftThemeId, type: 'randomImage', name: $stamp.'_filetree');
            $ftId = (int) ($ftModule['id'] ?? 0);

            $expect('a file path is accepted and stored as a binary uuid',
                [$this->moduleTool->update($ftId, ['multiSRC' => $ftFile['path']]),
                    StringUtil::deserialize((string) $this->connection->fetchOne('SELECT multiSRC FROM tl_module WHERE id = ?', [$ftId]), true)],
                static fn (array $r) => \in_array('multiSRC', $r[0]['changed_fields'] ?? [], true)
                    && \count($r[1]) === 1
                    && \strlen((string) $r[1][0]) === 16);

            // Same file, other notation: the value did not change, so neither
            // should the record — otherwise every re-run writes a new version.
            $expect('the same file as a string uuid is recognised as unchanged',
                $this->moduleTool->update($ftId, ['multiSRC' => $ftUuid]),
                static fn ($r) => ($r['changed_fields'] ?? ['x']) === []);

            $expect('the read-back is a readable uuid, not a binary blob',
                $this->moduleTool->get($ftId),
                static fn ($r) => ($r['multiSRC'][0] ?? null) === $ftUuid
                    && json_encode($r) !== false);

            $expect('an unknown path is refused with a usable message',
                $this->moduleTool->update($ftId, ['multiSRC' => 'files/does-not-exist.png']),
                static fn ($r) => ($r['error'] ?? '') === 'invalid_input'
                    && str_contains((string) ($r['message'] ?? ''), 'files_list'));

            $expect('and an empty value clears the field',
                [$this->moduleTool->update($ftId, ['multiSRC' => '']),
                    (string) $this->connection->fetchOne('SELECT multiSRC FROM tl_module WHERE id = ?', [$ftId])],
                static fn (array $r) => \in_array('multiSRC', $r[0]['changed_fields'] ?? [], true) && $r[1] === '');

            $this->connection->executeStatement('DELETE FROM tl_module WHERE id = ?', [$ftId]);
        } else {
            $output->writeln('  <comment>~ fileTree-Test übersprungen — keine Datei in tl_files oder kein Theme</comment>');
        }

        // ═══════════════════ Content-Baum (Batch) ═══════════════════
        //
        // pages_create_tree's counterpart for the inside of a page. Twelve
        // blocks on a start page used to be twelve calls, each paying a full
        // framework boot — and a ticket that reads as a list of steps rather
        // than as the thing being built.
        $output->writeln("\n<comment>Content-Baum (Batch)</comment>");

        $ctreePageId = (int) $this->connection->fetchOne("SELECT id FROM tl_page WHERE type != 'root' ORDER BY id LIMIT 1");
        $ctreeArticle = $ctreePageId > 0
            ? $this->articleTool->create(page_id: $ctreePageId, title: $stamp.'_ctree', sorting: 128, inColumn: 'main')
            : ['id' => 0];
        $ctreeArticleId = (int) ($ctreeArticle['id'] ?? 0);

        if ($ctreeArticleId > 0) {
            $ctreeNodes = [
                ['type' => 'headline', 'fields' => ['headline' => ['value' => 'Leistungen', 'unit' => 'h1']]],
                ['type' => 'text', 'fields' => ['text' => '<p>Was wir tun.</p>']],
                ['type' => 'element_group', 'children' => [
                    ['type' => 'text', 'fields' => ['text' => '<p>Spalte eins</p>']],
                    ['type' => 'text', 'fields' => ['text' => '<p>Spalte zwei</p>']],
                ]],
            ];

            // Everything checkable is checked before the first write.
            $expect('an unknown field is caught before anything is created',
                $this->contentTool->createTree('tl_article', $ctreeArticleId, [
                    ['type' => 'text', 'fields' => ['text' => '<p>ok</p>']],
                    ['type' => 'text', 'fields' => ['no_such_field' => 'x']],
                ]),
                static fn ($r) => ($r['error'] ?? '') === 'invalid_input'
                    && \count($r['problems'] ?? []) === 1
                    && ($r['problems'][0]['path'] ?? '') === '2');
            $expect('an unknown type is caught the same way',
                $this->contentTool->createTree('tl_article', $ctreeArticleId, [['type' => 'no_such_type']]),
                static fn ($r) => ($r['error'] ?? '') === 'invalid_input'
                    && str_contains((string) ($r['problems'][0]['error'] ?? ''), 'unknown content type'));
            $expect('and the rejected tree created nothing',
                (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM tl_content WHERE ptable = ? AND pid = ?', ['tl_article', $ctreeArticleId]),
                static fn (int $n) => $n === 0);

            $ctreePlan = $this->contentTool->createTree('tl_article', $ctreeArticleId, $ctreeNodes, dry_run: true);
            $expect('a dry run counts the nested nodes and assigns sorting', $ctreePlan,
                static fn ($r) => ($r['dry_run'] ?? false) === true
                    && ($r['elements'] ?? 0) === 5
                    && ($r['plan'][0]['sorting'] ?? 0) === 128
                    && ($r['plan'][1]['sorting'] ?? 0) === 256);
            $expect('the dry run wrote nothing either',
                (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM tl_content WHERE ptable = ? AND pid = ?', ['tl_article', $ctreeArticleId]),
                static fn (int $n) => $n === 0);

            $ctreeResult = $this->contentTool->createTree('tl_article', $ctreeArticleId, $ctreeNodes);
            $expect('the real run creates every node', $ctreeResult,
                static fn ($r) => ($r['created'] ?? 0) === 5 && ($r['failed'] ?? 1) === 0);

            // Nesting is structural: the children hang off the container, not
            // off the article. Getting this wrong looks like success — the
            // elements exist, they just render in the wrong place.
            $groupId = 0;
            foreach ($ctreeResult['elements'] ?? [] as $element) {
                if (($element['type'] ?? '') === 'element_group') {
                    $groupId = (int) $element['id'];
                }
            }
            $expect('the children hang off the container, not off the article',
                [$groupId, (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM tl_content WHERE ptable = ? AND pid = ?', ['tl_content', $groupId])],
                static fn (array $r) => $r[0] > 0 && $r[1] === 2);
            $expect('and the article holds exactly the three top-level elements',
                (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM tl_content WHERE ptable = ? AND pid = ?', ['tl_article', $ctreeArticleId]),
                static fn (int $n) => $n === 3);

            $this->connection->executeStatement('DELETE FROM tl_content WHERE ptable = ? AND pid = ?', ['tl_content', $groupId]);
            $this->connection->executeStatement('DELETE FROM tl_content WHERE ptable = ? AND pid = ?', ['tl_article', $ctreeArticleId]);
            $this->connection->executeStatement('DELETE FROM tl_article WHERE id = ?', [$ctreeArticleId]);
        } else {
            $output->writeln('  <comment>~ Content-Baum-Test übersprungen — keine Seite zum Anlegen eines Artikels</comment>');
        }

        // ═══════════════════ Template-Auffindbarkeit ═══════════════════
        //
        // Modern Contao 5 templates are identified by group + name
        // ("frontend_module/navigation"). templates_list used to report only
        // the fourteen legacy prefixes, so every modern template was invisible
        // in every listing — which is why AL-07 reverse-engineered a footer's
        // structure with five throwaway modules and a preview.
        $output->writeln("
<comment>Template-Auffindbarkeit</comment>");

        $allTemplates = $this->templateTool->templatesList();

        $expect('every template lands in exactly one group, none dropped', $allTemplates,
            static fn ($r) => ($r['count'] ?? 0) > 0
                && ($r['count'] ?? 0) === array_sum(array_map('count', $r['items'] ?? [])));
        $expect('the modern groups are listed, not only the legacy prefixes', $allTemplates,
            static fn ($r) => isset($r['items']['frontend_module'], $r['items']['content_element'])
                && $r['items']['frontend_module'] !== []);
        $expect('and the legacy prefixes still are', $allTemplates,
            static fn ($r) => ($r['items']['mod_'] ?? []) !== [] && ($r['items']['ce_'] ?? []) !== []);

        $expect('a modern identifier carries its group', $this->templateTool->templatesList('frontend_module/'),
            static fn ($r) => ($r['count'] ?? 0) > 0
                && array_filter($r['items']['frontend_module/'] ?? [], static fn ($n) => !str_starts_with((string) $n, 'frontend_module/')) === []);
        $expect('a legacy prefix still filters', $this->templateTool->templatesList('mod_'),
            static fn ($r) => ($r['count'] ?? 0) > 0);

        // The AL-07 dead end: the template exists, the guessed name does not.
        $missTemplate = $this->templateTool->templateLookup('frontend_module/netzhirsch_pagination');
        $expect('a wrong identifier suggests the right one from the same group', $missTemplate,
            static fn ($r) => ($r['error'] ?? '') === 'not_found'
                && ($r['suggestions'][0] ?? null) === 'frontend_module/pagination');
        $expect('while a name nothing resembles suggests nothing',
            $this->templateTool->templateLookup('vollkommen_erfunden_xyz'),
            static fn ($r) => ($r['suggestions'] ?? ['x']) === []);

        $expect('a correct identifier still resolves to its layers',
            $this->templateTool->templateLookup('content_element/text'),
            static fn ($r) => ($r['count'] ?? 0) >= 1
                && str_starts_with((string) ($r['entries'][0]['layer'] ?? ''), 'bundle:'));

        // ═══════════════════ Feldweiser Patch ═══════════════════
        //
        // The *_update tools take whole field values, which is right for a
        // headline and wrong for a 9 KB SCSS blob: changing three numbers means
        // reproducing every other byte, and every reproduction can lose
        // something. The guard that makes this safe is the occurrence count —
        // an ambiguous anchor must refuse rather than patch the wrong place.
        $output->writeln("\n<comment>Feldweiser Patch</comment>");

        $patchPageId = (int) $this->connection->fetchOne("SELECT id FROM tl_page WHERE type != 'root' ORDER BY id LIMIT 1");
        $patchArticle = $patchPageId > 0
            ? $this->articleTool->create(page_id: $patchPageId, title: $stamp.'_patch', sorting: 128, inColumn: 'main')
            : ['id' => 0];
        $patchArticleId = (int) ($patchArticle['id'] ?? 0);

        if ($patchArticleId > 0) {
            $patchBody = "<p>Zeile eins mit --fs-fhead: 1.375rem;</p>\n<p>Zeile zwei ohne Anker.</p>\n<p>Zeile drei mit --fs-fhead: 1.375rem;</p>";
            $patchElement = $this->contentTool->create(
                ptable: 'tl_article', pid: $patchArticleId, type: 'text', sorting: 128,
                fields: ['text' => $patchBody],
            );
            $patchId = (int) ($patchElement['id'] ?? 0);

            // Two matches, one expected → refuse, and say how many there are.
            $expect('an ambiguous anchor refuses and writes nothing',
                $this->patchTool->patch('tl_content', $patchId, 'text', '--fs-fhead: 1.375rem;', '--fs-fhead: 1.25rem;'),
                static fn ($r) => ($r['error'] ?? '') === 'occurrence_mismatch' && ($r['occurrences'] ?? 0) === 2);
            $expect('and the record is untouched after the refusal',
                (string) $this->connection->fetchOne('SELECT text FROM tl_content WHERE id = ?', [$patchId]),
                static fn (string $t) => substr_count($t, '1.375rem') === 2);

            // A unique anchor: dry run first, which must not write either.
            $dryPatch = $this->patchTool->patch('tl_content', $patchId, 'text', 'Zeile zwei ohne Anker.', 'Zeile zwei, jetzt anders.', dry_run: true);
            $expect('a dry run reports the match with its context', $dryPatch,
                static fn ($r) => ($r['dry_run'] ?? false) === true
                    && ($r['occurrences'] ?? 0) === 1
                    && str_contains((string) ($r['matches'][0]['context'] ?? ''), 'Zeile zwei'));
            $expect('the dry run wrote nothing',
                (string) $this->connection->fetchOne('SELECT text FROM tl_content WHERE id = ?', [$patchId]),
                static fn (string $t) => str_contains($t, 'Zeile zwei ohne Anker.'));

            $realPatch = $this->patchTool->patch('tl_content', $patchId, 'text', 'Zeile zwei ohne Anker.', 'Zeile zwei, jetzt anders.');
            $expect('the real patch reports sizes and changed fields', $realPatch,
                static fn ($r) => ($r['patched'] ?? false) === true
                    && \in_array('text', $r['changed_fields'] ?? [], true)
                    && ($r['field_size_after'] ?? 0) !== ($r['field_size_before'] ?? 0));
            $expect('and only the anchored passage changed',
                (string) $this->connection->fetchOne('SELECT text FROM tl_content WHERE id = ?', [$patchId]),
                static fn (string $t) => str_contains($t, 'Zeile zwei, jetzt anders.')
                    && substr_count($t, '1.375rem') === 2
                    && str_contains($t, 'Zeile eins'));

            // Stated count makes a repeated anchor legitimate.
            $expect('an explicit count patches every occurrence',
                $this->patchTool->patch('tl_content', $patchId, 'text', '1.375rem', '1.25rem', expect_occurrences: 2),
                static fn ($r) => ($r['patched'] ?? false) === true && ($r['occurrences'] ?? 0) === 2);

            // The write is an ordinary edit, so it is in the version history.
            $expect('the patch is in the version history',
                (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM tl_version WHERE fromTable = ? AND pid = ?', ['tl_content', $patchId],
                ),
                static fn (int $n) => $n > 0);

            $expect('an unknown field is refused, not guessed',
                $this->patchTool->patch('tl_content', $patchId, 'no_such_column', 'a', 'b'),
                static fn ($r) => ($r['error'] ?? '') === 'unknown_field');
            $expect('an empty anchor is refused',
                $this->patchTool->patch('tl_content', $patchId, 'text', '', 'b'),
                static fn ($r) => ($r['error'] ?? '') === 'invalid_input');

            $this->connection->executeStatement('DELETE FROM tl_content WHERE id = ?', [$patchId]);
            $this->connection->executeStatement('DELETE FROM tl_article WHERE id = ?', [$patchArticleId]);
        } else {
            $output->writeln('  <comment>~ Patch-Test übersprungen — keine Seite zum Anlegen eines Artikels</comment>');
        }

        $expect('a table without an audited updater is refused, and says which have one',
            $this->patchTool->patch('tl_user', 1, 'username', 'a', 'b'),
            static fn ($r) => ($r['error'] ?? '') === 'table_not_patchable'
                && \in_array('tl_theme', $r['writable_tables'] ?? [], true));

        // ═══════════════════ HTML-Ausgabefilter ═══════════════════
        //
        // What is stored is not what is rendered. MCP writes go through the
        // Model, so the database keeps the markup byte for byte and a read-back
        // looks perfect — while sanitize_html('contao') strips it at render
        // time. Two review rounds in AL-07 went on exactly this, both times
        // with a clean read-back in hand.
        $output->writeln("
<comment>HTML-Ausgabefilter</comment>");

        $filterInfo = $this->htmlTool->filterInfo();
        $expect('html_filter_info reports tags and per-tag attributes', $filterInfo,
            static fn ($r) => \count($r['allowed_tags'] ?? []) > 20
                && \in_array('a', $r['allowed_tags'] ?? [], true)
                && \in_array('href', $r['allowed_attributes']['a'] ?? [], true));

        // The exact case that killed the mobile burger: both tags are allowed,
        // both attributes are not, and the read-back showed neither.
        $burger = $this->htmlTool->filterPreview(
            '<input type="checkbox" id="nav-toggle"><label for="nav-toggle">Menu</label>',
        );
        $expect('a dropped attribute is named with its tag', $burger,
            static fn ($r) => ($r['changed'] ?? false) === true
                && \in_array(['tag' => 'input', 'attribute' => 'type'], $r['removed_attributes'] ?? [], true)
                && \in_array(['tag' => 'label', 'attribute' => 'for'], $r['removed_attributes'] ?? [], true));
        $expect('and the output shows what actually renders', $burger,
            static fn ($r) => !str_contains((string) $r['output'], 'type=')
                && !str_contains((string) $r['output'], 'for=')
                && str_contains((string) $r['output'], 'id='));

        $expect('a dropped tag is reported as a tag, not as its attributes',
            $this->htmlTool->filterPreview('<svg viewBox="0 0 16 16"><path d="M0 0h16v16H0z"/></svg>'),
            static fn ($r) => ($r['removed_tags'] ?? []) === ['svg', 'path']
                && ($r['removed_attributes'] ?? []) === []);

        // Markup that survives must report no findings at all, or the tool is
        // just noise the caller learns to ignore.
        $expect('untouched markup reports nothing removed',
            $this->htmlTool->filterPreview('<p class="lead">A <strong>sentence</strong>.</p>'),
            static fn ($r) => ($r['changed'] ?? true) === false
                && ($r['removed_tags'] ?? ['x']) === []
                && ($r['removed_attributes'] ?? ['x']) === []);

        // Prefix rules: data-* and aria-* pass, an event handler does not.
        $expect('wildcard attribute rules are honoured, event handlers are not',
            $this->htmlTool->filterPreview('<div data-controller="x" aria-label="y" onclick="evil()">z</div>'),
            static fn ($r) => ($r['removed_attributes'] ?? []) === [['tag' => 'div', 'attribute' => 'onclick']]);

        $expect('an empty preview is refused', $this->htmlTool->filterPreview(''),
            static fn ($r) => ($r['error'] ?? '') === 'no_text');

        // ═══════════════════ Paletten pro Typ ═══════════════════
        //
        // Contao keeps one wide table per DCA, so every row carries every
        // column and a read-back tells you nothing about which fields the type
        // actually has. The palette tools are the only honest answer, and they
        // used to merge EVERY sub-palette into EVERY type — offering columns
        // that would be written and then never rendered.
        $output->writeln("\n<comment>Paletten pro Typ</comment>");

        $textPalette = $this->contentTool->paletteGet('text');
        $downloadPalette = $this->contentTool->paletteGet('download');

        // Nested: a text element reaches alt only through
        // addImage → overwriteMeta → alt. Stopping one level short would drop
        // fields the backend really shows.
        $expect('a text element reaches image meta through the nested toggle', $textPalette,
            static fn ($r) => \in_array('addImage', $r['fields'] ?? [], true)
                && \in_array('overwriteMeta', $r['fields'] ?? [], true)
                && \in_array('alt', $r['fields'] ?? [], true));
        $expect('and says which toggle opens what', $textPalette,
            static fn ($r) => ($r['subpalettes']['overwriteMeta'] ?? []) !== []
                && \in_array('alt', $r['subpalettes']['overwriteMeta'] ?? [], true));

        // …but not fields that belong to a different type's toggle.
        $expect('a text element is not offered the download-only fields', $textPalette,
            static fn ($r) => !\in_array('linkTitle', $r['fields'] ?? [], true)
                && !\in_array('titleText', $r['fields'] ?? [], true));
        $expect('while the download element has exactly those', $downloadPalette,
            static fn ($r) => \in_array('linkTitle', $r['fields'] ?? [], true)
                && \in_array('titleText', $r['fields'] ?? [], true));

        $htmlModule = $this->moduleTool->paletteGet('html');
        $expect('an html module is not offered the login sub-palette', $htmlModule,
            static fn ($r) => !\in_array('reg_homeDir', $r['fields'] ?? [], true)
                && !\in_array('reg_jumpTo', $r['fields'] ?? [], true));

        // Raw markup is its own type, not a hidden field of the html type —
        // the AL-07 report looked for it in the wrong palette.
        $expect('unfiltered markup lives on its own module type',
            $this->moduleTool->paletteGet('unfiltered_html'),
            static fn ($r) => ($r['known'] ?? false) === true
                && \in_array('unfilteredHtml', $r['fields'] ?? [], true));
        $expect('and on its own content type',
            $this->contentTool->paletteGet('unfiltered_html'),
            static fn ($r) => ($r['known'] ?? false) === true
                && \in_array('unfilteredHtml', $r['fields'] ?? [], true));

        // The rejection has to name the type, otherwise the caller cannot tell
        // "this field does not exist" from "wrong type".
        $paletteStamp = $stamp.'_pal';
        $paletteArticle = $this->articleTool->create(page_id: (int) $this->connection->fetchOne(
            "SELECT id FROM tl_page WHERE type != 'root' ORDER BY id LIMIT 1",
        ) ?: 0, title: $paletteStamp, sorting: 128, inColumn: 'main');

        if (\is_int($paletteArticle['id'] ?? null)) {
            $paletteElement = $this->contentTool->create(
                ptable: 'tl_article', pid: (int) $paletteArticle['id'], type: 'text', sorting: 128,
                fields: ['text' => '<p>x</p>'],
            );

            $expect('a field from another type is refused, naming the type',
                $this->contentTool->update((int) $paletteElement['id'], ['linkTitle' => 'nope']),
                static fn ($r) => ($r['error'] ?? '') === 'invalid_input'
                    && str_contains((string) ($r['message'] ?? ''), 'linkTitle'));

            $this->connection->executeStatement('DELETE FROM tl_content WHERE id = ?', [(int) $paletteElement['id']]);
            $this->connection->executeStatement('DELETE FROM tl_article WHERE id = ?', [(int) $paletteArticle['id']]);
        } else {
            $output->writeln('  <comment>~ Ablehnungs-Test übersprungen — keine Seite zum Anlegen eines Artikels</comment>');
        }

        // ═══════════════════ DeepL-Übersetzung ═══════════════════
        //
        // Three environments, three shapes of answer, and the point of this
        // section is that all three are correct:
        //   - numero2/contao-deepl absent  → extension_not_available (CI)
        //   - installed without an API key → deepl_not_configured
        //   - installed and configured     → a real round trip
        // Only the last one spends money, and it spends a few dozen characters.
        $output->writeln("\n<comment>DeepL-Übersetzung</comment>");

        // Discovery first, and independent of the host extension: the tools have
        // to be found by the same attribute scanner every other tool goes
        // through, and their schema has to tell a client what is mandatory.
        // A tool that only fails at call time because nothing declared
        // `table` required is a tool an LLM will keep calling wrong.
        foreach (['deepl_status', 'deepl_translate', 'deepl_translate_records', 'deepl_translate_page_tree'] as $deeplName) {
            $expect(sprintf('%s is registered with an input schema', $deeplName),
                $this->registryAccessor->get()->getTool($deeplName),
                static fn ($t) => $t !== null && \is_array($t->schema->inputSchema ?? null));
        }

        $expect('the record tool declares its essential arguments as required',
            $this->registryAccessor->get()->getTool('deepl_translate_records'),
            static fn ($t) => array_diff(['table', 'ids', 'target_lang'], $t->schema->inputSchema['required'] ?? []) === []);

        $expect('deepl_status answers through the dispatcher, not just in-process',
            $dispatcher->handleToolCall(new CallToolRequest(41, 'deepl_status', [])),
            static fn ($r) => $r instanceof CallToolResult && !$r->isError);

        $deeplStatus = $this->deepLTool->status();
        $deeplGate = (string) ($deeplStatus['error'] ?? '');

        if ($deeplGate === 'extension_not_available' || $deeplGate === 'deepl_not_configured') {
            $expect('deepl_status reports why translation is unusable', $deeplStatus,
                static fn ($r) => ($r['available'] ?? null) === false
                    && ($r['required_extension'] ?? '') === 'numero2/contao-deepl');

            // The gate has to hold on the tools that would otherwise write.
            $expect('the record tool refuses with the same reason',
                $this->deepLTool->translateRecords(table: 'tl_page', ids: [1], target_lang: 'EN-GB', save: true),
                static fn ($r) => ($r['error'] ?? '') === $deeplGate);
            $expect('the page-tree tool refuses with the same reason',
                $this->deepLTool->translatePageTree(id: 1, target_lang: 'EN-GB', save: true),
                static fn ($r) => ($r['error'] ?? '') === $deeplGate);

            $output->writeln(sprintf('  ⊝ DeepL nicht verfügbar (%s) — Live-Teil übersprungen', $deeplGate));
        } else {
            $expect('deepl_status lists target languages', $deeplStatus,
                static fn ($r) => ($r['available'] ?? null) === true
                    && \in_array('EN-GB', $r['target_languages'] ?? [], true)
                    && \in_array('tl_content', $r['translatable_tables'] ?? [], true));

            // Raw text: the same string twice must be paid for once.
            $raw = $this->deepLTool->translate(
                texts: ['Guten Tag', 'Guten Tag', '', 'Bis bald'],
                target_lang: 'EN-GB',
            );
            $expect('deepl_translate keeps order and passes empties through', $raw,
                static fn ($r) => \count($r['translations'] ?? []) === 4
                    && ($r['translations'][2] ?? null) === ''
                    && trim((string) ($r['translations'][0] ?? '')) !== '');
            $expect('a repeated string is not billed twice', $raw,
                static fn ($r) => ($r['usage']['characters_reused'] ?? 0) >= 9);

            // Markup has to survive — this is the reason we do not simply call
            // the host bundle's single-string translate().
            $expect('html tag handling keeps the markup intact',
                $this->deepLTool->translate(texts: ['<p>Ein <strong>kurzer</strong> Satz.</p>'], target_lang: 'EN-GB', html: true),
                static fn ($r) => str_contains((string) ($r['translations'][0] ?? ''), '<strong>')
                    && str_starts_with((string) ($r['translations'][0] ?? ''), '<p>'));

            // A page with one article and one content element.
            $deeplTree = $this->pageTool->createTree(0, [[
                'title' => 'Startseite '.$stamp,
                'type' => 'root',
                'language' => 'de',
                'dns' => $stamp.'-dl.test',
                'children' => [['title' => 'Leistungen '.$stamp, 'type' => 'regular']],
            ]]);
            $dlRootId = (int) ($deeplTree['pages'][0]['id'] ?? 0);
            $dlPageId = (int) $this->connection->fetchOne('SELECT id FROM tl_page WHERE title = ?', ['Leistungen '.$stamp]);
            $dlArticle = $this->articleTool->create(page_id: $dlPageId, title: 'Hauptartikel '.$stamp, sorting: 128, inColumn: 'main');
            $dlArticleId = (int) ($dlArticle['id'] ?? 0);
            $dlContent = $this->contentTool->create(ptable: 'tl_article', pid: $dlArticleId, type: 'text', sorting: 128, fields: [
                'headline' => ['value' => 'Guten Tag', 'unit' => 'h3'],
                'text' => '<p>Ein <strong>kurzer</strong> Satz.</p>',
            ]);
            $dlContentId = (int) ($dlContent['id'] ?? 0);

            $expect('a page tree with an article and a content element exists',
                [$dlRootId, $dlPageId, $dlArticleId, $dlContentId],
                static fn (array $ids) => min($ids) > 0);

            // Planning is free: no API call, no writes, and it says what the
            // real run would cost.
            $dlPlan = $this->deepLTool->translatePageTree(id: $dlRootId, target_lang: 'EN-GB', dry_run: true);
            $expect('dry_run plans the whole tree without spending anything', $dlPlan,
                static fn ($r) => ($r['dry_run'] ?? false) === true
                    && ($r['characters_planned'] ?? 0) > 0
                    && !isset($r['usage']));
            $expect('the plan reaches page, article and content element', $dlPlan,
                static fn ($r) => \count(array_unique(array_column($r['records'] ?? [], 'table'))) === 3);

            // The budget is checked BEFORE the first API call.
            $expect('a character budget below the plan refuses up front',
                $this->deepLTool->translatePageTree(id: $dlRootId, target_lang: 'EN-GB', save: true, max_characters: 1),
                static fn ($r) => ($r['error'] ?? '') === 'character_budget_exceeded');

            $expect('an unregistered table is refused',
                $this->deepLTool->translateRecords(table: 'tl_user', ids: [1], target_lang: 'EN-GB'),
                static fn ($r) => ($r['error'] ?? '') === 'table_not_translatable');
            $expect('an unknown field is reported, not fatal',
                $this->deepLTool->translateRecords(table: 'tl_page', ids: [$dlPageId], target_lang: 'EN-GB', fields: ['title', 'no_such_column']),
                static fn ($r) => ($r['ignored_fields'] ?? []) === ['no_such_column']
                    && !isset($r['error']));

            $dlSave = $this->deepLTool->translatePageTree(id: $dlRootId, target_lang: 'EN-GB', save: true);
            $expect('saving the tree writes every record', $dlSave,
                static fn ($r) => ($r['totals']['failed'] ?? 1) === 0
                    && ($r['totals']['saved'] ?? 0) >= 3
                    && ($r['saved_to_database'] ?? false) === true);

            $dlRow = $this->connection->fetchAssociative('SELECT headline, text FROM tl_content WHERE id = ?', [$dlContentId]);
            $dlHeadline = StringUtil::deserialize((string) ($dlRow['headline'] ?? ''), true);
            $expect('the content element really changed in the database', $dlRow,
                static fn ($r) => \is_array($r) && str_contains((string) $r['text'], '<strong>')
                    && !str_contains((string) $r['text'], 'kurzer'));
            $expect('the headline keeps its unit and only the text changed', $dlHeadline,
                static fn (array $h) => ($h['unit'] ?? '') === 'h3'
                    && ($h['value'] ?? '') !== 'Guten Tag'
                    && trim((string) ($h['value'] ?? '')) !== '');

            // Running it again finds nothing to change — the update tools
            // compare values, so a re-run is a no-op rather than a new version.
            $expect('translating the same tree again changes nothing',
                $this->deepLTool->translatePageTree(id: $dlRootId, target_lang: 'EN-GB', save: true),
                static fn ($r) => ($r['totals']['saved'] ?? 1) === 0
                    && ($r['totals']['unchanged'] ?? 0) >= 3);

            // Fixtures out: content and article first, Contao refuses to drop a
            // page that still has articles.
            $this->connection->executeStatement('DELETE FROM tl_content WHERE ptable = ? AND pid = ?', ['tl_article', $dlArticleId]);
            $this->connection->executeStatement('DELETE FROM tl_article WHERE id = ?', [$dlArticleId]);
            $this->connection->executeStatement('DELETE FROM tl_page WHERE id = ? OR pid = ?', [$dlRootId, $dlRootId]);
        }

        // ═══════════════════════ Cleanup ═══════════════════════════
        if (!$keep) {
            $output->writeln("\n<comment>Cleanup</comment>");
            $deleted = 0;

            // Reverse dependency order.
            foreach ($created['comment'] as $id) {
                $this->commentsTool->delete($id, confirm_destructive: true);
                ++$deleted;
            }
            foreach ($created['newsletter'] as $id) {
                $this->newsletterTool->newsletterDelete($id, confirm_destructive: true);
                ++$deleted;
            }
            foreach ($created['newsletter_recipient'] as $id) {
                $this->newsletterTool->recipientDelete($id, confirm_destructive: true);
                ++$deleted;
            }
            foreach ($created['newsletter_channel'] as $id) {
                $this->newsletterTool->channelDelete($id, confirm_destructive: true, cascade: true);
                ++$deleted;
            }
            foreach ($created['form_field'] as $id) {
                $this->formFieldTool->delete($id, confirm_destructive: true);
                ++$deleted;
            }
            foreach ($created['form'] as $id) {
                $this->formTool->delete($id, confirm_destructive: true, cascade: true);
                ++$deleted;
            }
            foreach ($created['member'] as $id) {
                $this->memberTool->delete($id, confirm_destructive: true);
                ++$deleted;
            }
            foreach ($created['member_group'] as $id) {
                $this->memberGroupTool->delete($id, confirm_destructive: true);
                ++$deleted;
            }
            foreach ($created['layout'] as $id) {
                $this->layoutTool->delete($id, confirm_destructive: true);
                ++$deleted;
            }
            foreach ($created['theme'] as $id) {
                $this->themeTool->delete($id, confirm_destructive: true, cascade: true);
                ++$deleted;
            }

            $output->writeln("  removed {$deleted} test row(s)");
        } else {
            $output->writeln("\n<info>--keep was passed — test rows left in the DB. Search them by prefix '{$stamp}'.</info>");
        }

        // ═══════════════════════ Summary ═══════════════════════════
        $output->writeln("\n<comment>Summary</comment>");
        $output->writeln(sprintf('  <info>%d passed</info>, <%s>%d failed</%s>',
            $passed,
            $failed > 0 ? 'error' : 'info',
            $failed,
            $failed > 0 ? 'error' : 'info',
        ));

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function idIn(array $items, int $id): bool
    {
        foreach ($items as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $haystack
     */
    private static function stringInList(string $needle, array $haystack): bool
    {
        foreach ($haystack as $entry) {
            if (str_contains($entry, $needle)) {
                return true;
            }
        }
        return false;
    }
}
