<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Psr\Log\LoggerInterface;

/**
 * The answer a tool gives when a write blew up in a way it cannot explain.
 *
 * Tools used to hand the caller `$e->getMessage()` plus `$e::class` verbatim.
 * A DBAL message is not a sentence about the caller's input — it can carry the
 * statement, the values bound to it, and on a connection failure the host, the
 * database and the user ("Access denied for user 'x'@'y'"). The dispatcher one
 * level up has been returning an opaque reference for exactly that reason since
 * the beginning; the tools below it kept talking.
 *
 * What the caller gets instead: the same `error` code as before — so anything
 * branching on `save_failed` keeps working — a reference that matches a log
 * entry, and the SQLSTATE when there is one.
 *
 * SQLSTATE stays because dropping it would have made this a pure downgrade for
 * the agent. It is a five-character standard code: `23000` is a constraint,
 * `22007` a value the column will not take, `HY000` the catch-all. Enough to
 * decide "my input was wrong" versus "the server has a problem", and it carries
 * no table, no value and no host.
 */
final class ToolError
{
    /**
     * @param string $error   the machine-readable code the tool already used
     *                        ('save_failed', 'delete_failed', …) — unchanged,
     *                        because callers branch on it
     * @param string $context what was being attempted, for the log line
     *
     * @return array{error: string, message: string, sqlstate?: string}
     */
    public static function opaque(LoggerInterface $logger, \Throwable $e, string $error, string $context): array
    {
        // Short and random: long enough to find one line in a log, not a value
        // anybody needs to guess.
        $reference = bin2hex(random_bytes(4));

        $logger->error($context, ['exception' => $e, 'reference' => $reference, 'error' => $error]);

        $out = [
            'error' => $error,
            'message' => \sprintf(
                'The operation failed (reference %s). The technical detail is in the Contao application log — it is deliberately not returned here.',
                $reference,
            ),
        ];

        $sqlState = self::sqlState($e);
        if ($sqlState !== null) {
            $out['sqlstate'] = $sqlState;
        }

        return $out;
    }

    /**
     * Walks the exception chain: DBAL wraps the driver exception, and the code
     * only lives on the inner one.
     */
    private static function sqlState(\Throwable $e): ?string
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            $state = null;

            if ($cur instanceof DbalDriverException || $cur instanceof DriverException) {
                $state = $cur->getSQLState();
            }

            if (\is_string($state) && preg_match('/^[A-Za-z0-9]{5}$/', $state) === 1) {
                return $state;
            }
        }

        return null;
    }
}
