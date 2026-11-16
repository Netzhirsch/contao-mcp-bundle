<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Server;

use Contao\Model\Registry;
use Psr\Log\LoggerInterface;

/**
 * Post-tool-call cleanup.
 *
 * {@see ToolCallHandler} invokes this after every successful or failed
 * `tools/call`. Two things would otherwise pile up inside one request that
 * runs many calls (a batch, a bulk import):
 *
 *   1. {@see \Contao\Model\Registry} is a process-singleton that caches every
 *      loaded Model row by primary key. Symfony's `kernel.reset` listener
 *      clears it at HTTP-request boundaries, not between the calls of one
 *      request, so it grows with every `news_list`/`pages_get` until the
 *      memory limit hits.
 *
 *   2. The {@see \Netzhirsch\ContaoMcpBundle\Service\McpCallContext} carries
 *      the OAuth-authenticated identity for the current call. It MUST be
 *      cleared between calls — otherwise call B inherits call A's identity if
 *      its own auth layer didn't refresh the context (defense-in-depth).
 *
 * The hook runs in a `finally` block, so it executes even when a tool throws.
 * Errors inside the hook itself are caught by the caller so one broken reset
 * doesn't poison the next call.
 */
final class PostCallHook
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly \Netzhirsch\ContaoMcpBundle\Service\McpCallContext $callContext,
    ) {
    }

    public function afterToolCall(string $toolName): void
    {
        // 1. Drop cached Model rows. Registry::reset() is a no-op-cheap call
        //    when the registry is empty; on a busy `*_list` it frees the bulk
        //    of accumulated Model memory.
        if (class_exists(Registry::class, false)) {
            try {
                Registry::getInstance()->reset();
            } catch (\Throwable $e) {
                // Registry might be in an inconsistent state during shutdown.
                // Logging keeps the hook contract intact — we never re-throw.
                $this->logger->debug(sprintf('Model Registry reset failed for %s: %s', $toolName, $e->getMessage()));
            }
        }

        // 2. Clear the per-call identity context. The next call must re-populate
        //    it via the OAuth validator (see McpController::handle).
        $this->callContext->clear();
    }
}
