<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Psr\Log\LoggerInterface;

/**
 * Retry wrapper for transient DBAL exceptions — deadlocks and lock-wait
 * timeouts. Both surface as `Doctrine\DBAL\Exception\RetryableException`
 * subclasses (DeadlockException, LockWaitTimeoutException), which DBAL
 * deliberately marks for caller-side retry: the database itself has
 * already rolled the transaction back; the operation just needs to be
 * re-attempted against a fresh transaction.
 *
 * Used to wrap `Connection::transactional(...)` calls in the cascade-
 * delete tools, where two concurrent operators can lock-step around
 * tl_content / tl_version rows and trigger MySQL's deadlock detector.
 * Without retry the LLM caller sees a hard SQL error; with retry the
 * second attempt usually wins (the first commit holders have released
 * their locks by then).
 *
 * Backoff schedule: 50ms → 200ms → 500ms base + 0-50ms jitter. Three
 * retries by default = ~750ms-1s maximum delay. Short enough to stay
 * inside any reasonable MCP-call timeout, long enough to outlast the
 * typical lock contention.
 *
 * Does NOT retry on generic DBAL exceptions (connection-lost, SQL syntax,
 * constraint violation, …) — those would just fail again on retry. Only
 * the explicit RetryableException marker is honoured.
 */
final class DbalRetry
{
    private const DEFAULT_MAX_RETRIES = 3;
    private const BACKOFF_MS = [50, 200, 500];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Wrap `Connection::transactional($op)` with retry-on-deadlock.
     * Most callers want this rather than the lower-level
     * {@see withRetry()} — DBAL handles the BEGIN/COMMIT/ROLLBACK
     * lifecycle inside.
     *
     * @template T
     *
     * @param callable(Connection): T $op
     *
     * @return T
     */
    public function transactional(Connection $connection, callable $op): mixed
    {
        return $this->withRetry(
            static fn () => $connection->transactional($op),
            self::DEFAULT_MAX_RETRIES,
        );
    }

    /**
     * Run any callable with retry-on-RetryableException. The callable
     * must be idempotent on its own — we don't unwind state between
     * attempts beyond what the database does on rollback.
     *
     * @template T
     *
     * @param callable(): T $op
     *
     * @return T
     */
    public function withRetry(callable $op, int $maxRetries = self::DEFAULT_MAX_RETRIES): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $op();
            } catch (RetryableException $e) {
                if ($attempt >= $maxRetries) {
                    // Exhausted retries. Surface the original exception
                    // so the caller sees the same shape it would have
                    // without the wrapper.
                    $this->logger->warning(sprintf(
                        'DbalRetry: %d/%d attempts failed; surfacing original exception (%s)',
                        $attempt + 1,
                        $maxRetries + 1,
                        $e::class,
                    ));
                    throw $e;
                }

                $delayMs = self::BACKOFF_MS[$attempt] + random_int(0, 50);
                $this->logger->info(sprintf(
                    'DbalRetry: attempt %d failed with %s — retrying in %dms',
                    $attempt + 1,
                    $e::class,
                    $delayMs,
                ));
                usleep($delayMs * 1000);
                ++$attempt;
            }
        }
    }
}
