<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service;

use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Netzhirsch\ContaoMcpBundle\Service\ToolError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * A DBAL message is not a sentence about the caller's input. It can carry the
 * statement, the values bound to it, and on a connection failure the host, the
 * database and the user. The dispatcher has answered with an opaque reference
 * since the beginning; the tools below it used to hand the whole thing over.
 */
#[CoversClass(ToolError::class)]
final class ToolErrorTest extends TestCase
{
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{string, array<mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
    }

    public function testTheRawMessageDoesNotReachTheCaller(): void
    {
        $logger = $this->logger();
        $secret = "Access denied for user 'contao'@'db-01.internal' (using password: YES)";

        $result = ToolError::opaque($logger, new \RuntimeException($secret), 'save_failed', 'news_update failed');

        self::assertSame('save_failed', $result['error'], 'the code callers branch on must not change');
        self::assertStringNotContainsString('db-01.internal', $result['message']);
        self::assertStringNotContainsString('contao', $result['message']);
        self::assertArrayNotHasKey('class', $result);
    }

    /**
     * Opaque to the caller, not to the operator: the reference is what turns
     * "it failed" into one grep in the application log.
     */
    public function testTheReferenceInTheAnswerMatchesTheLogEntry(): void
    {
        $logger = $this->logger();

        $result = ToolError::opaque($logger, new \RuntimeException('boom'), 'save_failed', 'news_update failed');

        self::assertCount(1, $logger->records);
        $reference = (string) $logger->records[0][1]['reference'];
        self::assertNotSame('', $reference);
        self::assertStringContainsString($reference, $result['message']);
        self::assertSame('news_update failed', $logger->records[0][0]);
    }

    /**
     * SQLSTATE stays, or this would be a pure downgrade for the agent: five
     * standard characters that separate "my input was wrong" from "the server
     * has a problem", carrying no table, no value and no host.
     */
    public function testTheSqlStateSurvivesTheWrapping(): void
    {
        $driver = new PdoDriverException('Incorrect integer value', '22007', 1366);
        $wrapped = new \RuntimeException('save failed', 0, $driver);

        $result = ToolError::opaque($this->logger(), $wrapped, 'save_failed', 'ctx');

        self::assertSame('22007', $result['sqlstate'] ?? null);
    }

    public function testAnErrorWithoutASqlStateSimplyHasNone(): void
    {
        $result = ToolError::opaque($this->logger(), new \RuntimeException('no database involved'), 'save_failed', 'ctx');

        self::assertArrayNotHasKey('sqlstate', $result);
    }

    public function testTwoFailuresGetDifferentReferences(): void
    {
        $logger = $this->logger();

        $a = ToolError::opaque($logger, new \RuntimeException('x'), 'save_failed', 'ctx');
        $b = ToolError::opaque($logger, new \RuntimeException('x'), 'save_failed', 'ctx');

        self::assertNotSame($a['message'], $b['message']);
    }
}
