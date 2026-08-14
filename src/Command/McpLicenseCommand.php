<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Command;

use Netzhirsch\ContaoMcpBundle\License\LicenseGate;
use Netzhirsch\ContaoMcpBundle\License\LicenseStore;
use Netzhirsch\ContaoMcpBundle\License\LicenseToken;
use Netzhirsch\ContaoMcpBundle\License\RenewalClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * License administration for the MCP server (trial → paid subscription model).
 *
 *   contao:mcp:license status              show the current license state
 *   contao:mcp:license activate <token>    store a signed license token
 *   contao:mcp:license trial <email>       request a trial from the license server
 *   contao:mcp:license renew               force a subscription-token renewal
 *   contao:mcp:license keygen              VENDOR: generate an Ed25519 keypair
 *
 * Renewal normally runs by itself — {@see \Netzhirsch\ContaoMcpBundle\Cron\LicenseRenewalCron}
 * refreshes the token (and picks up revocations) on the Contao cron. This
 * command's `renew` is the manual/forced variant for debugging or a bespoke
 * system cron.
 */
#[AsCommand(
    name: 'contao:mcp:license',
    description: 'Manage the Contao MCP server license (status, activate, trial, renew, keygen).',
)]
final class McpLicenseCommand extends Command
{
    public function __construct(
        private readonly LicenseStore $store,
        private readonly LicenseGate $gate,
        private readonly RenewalClient $renewalClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'status | activate | trial | renew | keygen')
            ->addArgument('value', InputArgument::OPTIONAL, 'Token (activate) or e-mail (trial)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');
        $value = (string) ($input->getArgument('value') ?? '');

        return match ($action) {
            'status' => $this->status($io),
            'activate' => $this->activate($io, $value),
            'trial' => $this->trial($io, $value),
            'renew' => $this->renew($io),
            'keygen' => $this->keygen($io),
            default => $this->unknown($io, $action),
        };
    }

    private function status(SymfonyStyle $io): int
    {
        $s = $this->gate->state();
        $io->title('MCP license status');
        $io->definitionList(
            ['Active' => $s['active'] ? 'yes' : 'no'],
            ['Type' => $s['type'] !== '' ? $s['type'] : '—'],
            ['Reason' => $s['reason']],
            ['Expires' => $s['expires_at'] > 0 ? date('Y-m-d H:i', $s['expires_at']) : '—'],
            ['Days left' => (string) $s['days_left']],
            ['In grace' => $s['in_grace'] ? 'yes' : 'no'],
        );

        return self::SUCCESS;
    }

    private function activate(SymfonyStyle $io, string $token): int
    {
        if ('' === trim($token)) {
            $io->error('Pass the license token: contao:mcp:license activate <token>');

            return self::INVALID;
        }
        if (!$this->store->setToken($token)) {
            $io->error('Could not write var/mcp/license.json — check permissions.');

            return self::FAILURE;
        }
        $io->success('Token stored.');

        return $this->status($io);
    }

    private function trial(SymfonyStyle $io, string $email): int
    {
        if ('' === trim($email)) {
            $io->error('Pass the account e-mail: contao:mcp:license trial <email>');

            return self::INVALID;
        }
        $result = $this->renewalClient->startTrial($email);
        if (!($result['ok'] ?? false)) {
            $io->error(sprintf('Trial request failed (%s): %s', $result['error'] ?? '?', $result['message'] ?? ''));

            return self::FAILURE;
        }
        $io->success('Trial activated.');

        return $this->status($io);
    }

    private function renew(SymfonyStyle $io): int
    {
        $result = $this->renewalClient->renew(true);
        if (!($result['ok'] ?? false)) {
            // Not fatal: the stored token stays valid until it actually expires.
            $io->warning(sprintf('Renewal skipped/failed (%s): %s', $result['error'] ?? '?', $result['message'] ?? ''));

            return self::SUCCESS;
        }
        $io->success('License renewed.');

        return $this->status($io);
    }

    private function keygen(SymfonyStyle $io): int
    {
        if (!\function_exists('sodium_crypto_sign_keypair')) {
            $io->error('PHP ext-sodium is required for keygen.');

            return self::FAILURE;
        }
        $keys = LicenseToken::keypair();

        // Self-check: sign + verify a throwaway payload so a broken keypair
        // surfaces here rather than in production.
        $probe = LicenseToken::sign(['product' => LicenseToken::PRODUCT, 'domain' => 'example.test', 'type' => 'trial', 'issued_at' => time(), 'expires_at' => time() + 60], $keys['secret']);
        $ok = (new LicenseToken($keys['public']))->verify($probe, 'example.test');

        $io->title('VENDOR — Ed25519 license keypair');
        $io->writeln('<info>PUBLIC</info> (paste into LicenseToken::VENDOR_PUBLIC_KEY_B64, safe to ship):');
        $io->writeln('  '.$keys['public']);
        $io->newLine();
        $io->writeln('<comment>SECRET</comment> (license server ONLY — never ship, never commit):');
        $io->writeln('  '.$keys['secret']);
        $io->newLine();
        $io->writeln('Self-check sign+verify: '.(($ok['valid'] ?? false) ? '<info>OK</info>' : '<error>FAILED</error>'));

        return ($ok['valid'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    private function unknown(SymfonyStyle $io, string $action): int
    {
        $io->error(sprintf('Unknown action "%s". Use: status | activate | trial | renew | keygen.', $action));

        return self::INVALID;
    }
}
