<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Command;

use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\OAuth\InitialAccessTokenManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cleans out expired and revoked OAuth records so the DB doesn't grow
 * unbounded. Safe to run repeatedly. Drop in a cron / Contao job for
 * production:
 *
 *   contao:mcp:oauth:cleanup --quiet     # daily
 */
#[AsCommand(
    name: 'contao:mcp:oauth:cleanup',
    description: 'Purges expired OAuth authorization codes, access tokens, refresh tokens, and Initial Access Tokens.',
)]
final class McpOAuthCleanupCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly InitialAccessTokenManager $iat,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = time();
        // Keep a small grace window in case a client is still mid-refresh.
        $cutoff = $now - 86400;

        $codes = $this->connection->executeStatement(
            'DELETE FROM tl_mcp_oauth_authcode WHERE expires_at < ? OR is_revoked = 1',
            [$cutoff],
        );
        $access = $this->connection->executeStatement(
            'DELETE FROM tl_mcp_oauth_access_token WHERE expires_at < ?',
            [$cutoff],
        );
        $refresh = $this->connection->executeStatement(
            'DELETE FROM tl_mcp_oauth_refresh_token WHERE expires_at < ? OR is_revoked = 1',
            [$cutoff],
        );
        $iats = $this->iat->purgeExpired();

        $output->writeln(sprintf(
            'Purged: %d auth codes, %d access tokens, %d refresh tokens, %d initial access tokens.',
            $codes, $access, $refresh, $iats,
        ));

        return Command::SUCCESS;
    }
}
