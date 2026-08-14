<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Command;

use Netzhirsch\ContaoMcpBundle\OAuth\KeyManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Operator-facing key rotation for OAuth JWT signing keys.
 *
 * Default behaviour: rotate if `var/mcp/oauth/private.pem` is older than
 * 90 days; prune `previous_*.pem` if older than 30 days. Drop-in for a
 * monthly cron:
 *
 *   contao:mcp:oauth:rotate-keys --quiet
 *
 * Manual force-rotation (e.g. after a suspected key compromise):
 *
 *   contao:mcp:oauth:rotate-keys --force
 *
 * The dual-key validator ({@see \Netzhirsch\ContaoMcpBundle\Service\McpOAuthValidator})
 * accepts tokens signed under EITHER the new or the previous key for
 * the duration of the grace window (default 30 days, matches refresh-
 * token TTL). After --prune-old, only the new key is honoured.
 */
#[AsCommand(
    name: 'contao:mcp:oauth:rotate-keys',
    description: 'Rotates the OAuth RSA keypair. Old key is kept for a grace window so existing tokens stay valid.',
)]
final class McpOAuthRotateKeysCommand extends Command
{
    public function __construct(
        private readonly KeyManager $keys,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'max-age',
                null,
                InputOption::VALUE_REQUIRED,
                'Rotate the current key when it is older than this. Accepts seconds or PHP-relative-time strings ("90 days", "12h"). Default 90 days.',
                '90 days',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Rotate the current key regardless of its age (use after a suspected compromise).',
            )
            ->addOption(
                'prune-old',
                null,
                InputOption::VALUE_REQUIRED,
                'Delete the previous keypair if older than this. Accepts seconds or PHP-relative-time strings. Default 30 days (matches refresh-token TTL).',
                '30 days',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report what WOULD happen — do not write any files.',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $maxAgeSeconds = self::parseDuration((string) $input->getOption('max-age'));
        $pruneSeconds = self::parseDuration((string) $input->getOption('prune-old'));
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        $currentAge = $this->keys->currentKeyAgeSeconds();
        $previousAge = $this->keys->previousKeyAgeSeconds();

        $io->writeln(sprintf(
            'Current key age: %s',
            $currentAge === null ? '<not present>' : self::formatAge($currentAge),
        ));
        $io->writeln(sprintf(
            'Previous key age: %s',
            $previousAge === null ? '<not present>' : self::formatAge($previousAge),
        ));
        $io->writeln(sprintf('Rotation threshold (--max-age): %s', self::formatAge($maxAgeSeconds)));
        $io->writeln(sprintf('Prune threshold (--prune-old): %s', self::formatAge($pruneSeconds)));
        $io->newLine();

        // Decide on rotation.
        $shouldRotate = $force || ($currentAge !== null && $currentAge >= $maxAgeSeconds);
        if (!$shouldRotate) {
            $io->writeln('<fg=gray>→ Rotation skipped: current key is younger than the threshold.</>');
        } else {
            if ($dryRun) {
                $io->writeln('<fg=yellow>→ Would rotate now (dry-run, no files written).</>');
            } else {
                $result = $this->keys->rotate();
                $io->success(sprintf(
                    'Rotated. Previous key archived (was %s old); new key at %s.',
                    self::formatAge((int) ($result['previous_age_seconds'] ?? 0)),
                    $result['new_path'],
                ));
            }
        }

        // Decide on prune.
        if ($previousAge === null && !$shouldRotate) {
            // Nothing to prune now AND we didn't just create one.
            $io->writeln('<fg=gray>→ Prune skipped: no previous key present.</>');
        } elseif ($dryRun) {
            $io->writeln('<fg=yellow>→ Would prune previous key if older than threshold (dry-run).</>');
        } else {
            // Re-read after rotation may have just created one. The NEW
            // previous key is the one we just rotated out, age ≈ 0 →
            // pruneOldKeys will leave it alone.
            $pruneResult = $this->keys->pruneOldKeys($pruneSeconds);
            if ($pruneResult['pruned']) {
                $io->success(sprintf(
                    'Pruned previous key (was %s old). Tokens signed under it can no longer be validated.',
                    self::formatAge((int) ($pruneResult['age_seconds'] ?? 0)),
                ));
            } else {
                $io->writeln(sprintf(
                    '<fg=gray>→ Prune skipped: %s</>',
                    $pruneResult['reason'] ?? 'unknown',
                ));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Accepts:
     *   - pure integer seconds: "3600"
     *   - PHP-relative-time strings: "90 days", "12h", "30 minutes"
     */
    private static function parseDuration(string $raw): int
    {
        $raw = trim($raw);
        if (ctype_digit($raw)) {
            return (int) $raw;
        }
        $now = time();
        $parsed = strtotime('+'.$raw, $now);
        if ($parsed === false || $parsed <= $now) {
            throw new \InvalidArgumentException(sprintf(
                'Could not parse duration "%s". Use a positive integer (seconds) or a PHP-relative-time string ("90 days", "12h").',
                $raw,
            ));
        }
        return $parsed - $now;
    }

    private static function formatAge(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }
        if ($seconds < 3600) {
            return sprintf('%dm', (int) round($seconds / 60));
        }
        if ($seconds < 86400) {
            return sprintf('%.1fh', $seconds / 3600);
        }
        return sprintf('%.1fd', $seconds / 86400);
    }
}
