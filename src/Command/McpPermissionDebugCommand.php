<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Command;

use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Security\BackendUserContext;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionEnforcer;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\McpCallContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Operator diagnostic: shows exactly which MCP permission checks pass or fail
 * for a given backend user, and why. Read-only — touches no data.
 *
 *   contao-console contao:mcp:permission-debug <username>
 *   contao-console contao:mcp:permission-debug            (lists non-admins)
 */
#[AsCommand(
    name: 'contao:mcp:permission-debug',
    description: 'Explains MCP permission decisions for a backend user (why a tool is hidden/denied).',
)]
final class McpPermissionDebugCommand extends Command
{
    /** Representative tools to probe across the common groups. */
    private const PROBE = [
        'news_list' => ['tl_news', 'read'],
        'news_create' => ['tl_news', 'create'],
        'news_delete' => ['tl_news', 'delete'],
        'news_archives_list' => ['tl_news_archive', 'read'],
        'pages_list' => ['tl_page', 'read'],
        'member_create' => ['tl_member', 'create'],
        'system_settings_update' => [null, null],
        'file_upload' => [null, null],
        'ping' => [null, null],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly McpCallContext $callContext,
        private readonly BackendUserContext $userContext,
        private readonly McpPermissionGuard $guard,
        private readonly McpPermissionEnforcer $enforcer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::OPTIONAL, 'Backend username to inspect.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = $input->getArgument('username');

        if (!\is_string($username) || $username === '') {
            $this->listNonAdmins($io);

            return Command::SUCCESS;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT id, username, name, admin, inherit, netzhirschMcpAccess, '.$this->connection->quoteIdentifier('groups').' FROM tl_user WHERE username = ?',
            [$username],
        );
        if ($row === false) {
            $io->error(sprintf('No backend user "%s".', $username));

            return Command::FAILURE;
        }

        $userId = (int) $row['id'];
        $io->title(sprintf('MCP permissions for #%d "%s"', $userId, $username));

        // Raw account flags.
        $io->section('Account');
        $io->listing([
            'admin: '.(((int) $row['admin']) === 1 ? 'YES (bypasses all checks)' : 'no'),
            'inherit: '.($row['inherit'] ?: '(empty)').'  — group rights only merge when this is "group" or "extend"',
            'netzhirschMcpAccess (own): '.(((int) $row['netzhirschMcpAccess']) === 1 ? 'on' : 'off'),
            'groups: '.implode(', ', $this->groupIds($row['groups'])) ?: 'groups: (none)',
        ]);

        // Group flags.
        $groupIds = $this->groupIds($row['groups']);
        if ($groupIds !== []) {
            $ph = implode(',', array_fill(0, \count($groupIds), '?'));
            $groups = $this->connection->fetchAllAssociative(
                "SELECT id, name, netzhirschMcpAccess, disable FROM tl_user_group WHERE id IN ($ph)",
                $groupIds,
            );
            $io->section('Groups');
            foreach ($groups as $g) {
                $io->writeln(sprintf(
                    '  #%d "%s" — MCP-Access: %s, %s',
                    (int) $g['id'], (string) $g['name'],
                    ((int) $g['netzhirschMcpAccess']) === 1 ? 'on' : 'off',
                    ((int) $g['disable']) === 1 ? 'DISABLED' : 'active',
                ));
            }
        }

        // Effective (group-merged) permissions as the guard sees them.
        $this->callContext->setIdentity($userId, 'debug', null, null);
        try {
            $token = $this->userContext->tokenFor($userId);
            $user = $token?->getUser();
            $io->section('Effective permissions (after group merge)');
            if ($user === null) {
                $io->warning('Could not load the backend user via the provider — token is null.');
            } else {
                $io->listing([
                    'modules: '.$this->fmt($user->modules ?? null),
                    'news (allowed archives): '.$this->fmt($user->news ?? null),
                    'cud (create/delete per table): '.$this->fmt($user->cud ?? null),
                    'alexf (editable excluded fields): '.\count((array) ($user->alexf ?? [])).' field(s)',
                ]);
            }

            // Coarse gate.
            $io->section('1) MCP access gate');
            $accessDenial = $this->guard->ensureMcpAccess();
            $io->writeln($accessDenial === null
                ? '  <fg=green>PASS</> — user may use the MCP server'
                : '  <fg=red>BLOCKED</> — '.($accessDenial['message'] ?? ''));

            // Per-tool probes.
            $io->section('2) Per-tool decisions');
            foreach (self::PROBE as $tool => [$table, $op]) {
                $visible = $this->enforcer->isToolVisible($tool);

                // Probe record-scoped reads/deletes with a REAL row id so the
                // row-/parent-level voter (e.g. news archive access) actually
                // runs. A record-free probe skips it and looks misleadingly
                // green — which is exactly what hides "can list, but can't open
                // a single record" setups.
                $args = [];
                $note = '';
                $isListing = str_ends_with($tool, '_list') || str_ends_with($tool, '_tree');
                if ($op === 'create' || $op === 'update') {
                    $args = ['headline' => 'x', 'title' => 'x'];
                } elseif (\in_array($op, ['read', 'delete'], true) && $table !== null && !$isListing) {
                    // Record-scoped read/delete → probe with a real row id so the
                    // row-/parent-level voter actually runs.
                    $sampleId = $this->sampleId($table);
                    if ($sampleId !== null) {
                        $args = ['id' => $sampleId];
                        $note = ' (probed row #'.$sampleId.')';
                    } else {
                        $note = ' (no row to probe → table-level only)';
                    }
                }
                // List/tree tools stay record-less (table-level) — exactly how
                // they are really invoked (module-gated, not per-record).

                $callDenial = $this->enforcer->check($tool, $args);
                $callTxt = $callDenial === null ? '<fg=green>allowed</>' : '<fg=red>denied</> ('.($callDenial['error'] ?? '').')';
                $io->writeln(sprintf(
                    '  %-24s visible: %-3s  call: %s%s',
                    $tool,
                    $visible ? 'yes' : '<fg=yellow>no</>',
                    $callTxt,
                    $note,
                ));
            }
        } finally {
            $this->callContext->clear();
        }

        $io->newLine();
        $io->writeln('<comment>Reading the result:</comment> if the access gate is BLOCKED → set "MCP-Server-Zugriff erlauben" on the user or an active group. If news shows visible:no / denied but you granted news rights → check that "inherit" is group/extend (so group rights merge) and that the news module + allowed archives + cud are actually set on the SAME source (user vs group) the inherit points to. Note: record-scoped probes now use a REAL row id (shown as "probed row #…"); a "denied" there despite list rights usually means no edit access to the parent (e.g. tl_news needs access to its news archive).');

        return Command::SUCCESS;
    }

    /**
     * First existing row id of a table (trusted PROBE table, not user input),
     * or null if the table is empty. Used to exercise row-/parent-level voters.
     */
    private function sampleId(string $table): ?int
    {
        try {
            $id = $this->connection->fetchOne("SELECT id FROM $table ORDER BY id LIMIT 1");
        } catch (\Throwable) {
            return null;
        }

        return ($id === false || $id === null) ? null : (int) $id;
    }

    private function listNonAdmins(SymfonyStyle $io): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT username, name, netzhirschMcpAccess FROM tl_user WHERE admin = 0 ORDER BY username',
        );
        if ($rows === []) {
            $io->warning('No non-admin backend users found.');

            return;
        }
        $io->title('Non-admin backend users');
        $io->table(
            ['username', 'name', 'MCP-Access'],
            array_map(static fn (array $r): array => [
                (string) $r['username'],
                (string) $r['name'],
                ((int) $r['netzhirschMcpAccess']) === 1 ? 'on' : 'off',
            ], $rows),
        );
        $io->writeln('Run again with a username to see the full permission breakdown.');
    }

    /**
     * @return list<string>
     */
    private function groupIds(mixed $raw): array
    {
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = @unserialize($raw, ['allowed_classes' => false]);

        return \is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    private function fmt(mixed $value): string
    {
        if (empty($value)) {
            return '(none)';
        }
        if (\is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        return (string) $value;
    }
}
