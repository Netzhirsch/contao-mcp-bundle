<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Server;

use Netzhirsch\ContaoMcpBundle\Service\UnknownArgumentGuard;
use PhpMcp\Schema\Content\TextContent;
use PhpMcp\Schema\Request\CallToolRequest;
use PhpMcp\Schema\Request\ListToolsRequest;
use PhpMcp\Schema\Result\CallToolResult;
use PhpMcp\Schema\Result\ListToolsResult;
use PhpMcp\Server\Configuration;
use PhpMcp\Server\Dispatcher;
use PhpMcp\Server\Registry;
use PhpMcp\Server\Session\SubscriptionManager;
use PhpMcp\Server\Utils\SchemaValidator;
use Psr\Log\LoggerInterface;

/**
 * The two behaviours this bundle adds on top of php-mcp/server's Dispatcher.
 *
 * They used to be a vendor patch, and that patch was a genuine problem: applying
 * it needs `cweagans/composer-patches`, a Composer PLUGIN. Composer refuses to
 * run un-allowed plugins, and the Contao Manager cannot add anything to the
 * root `allow-plugins` — so installing this bundle the way most people install
 * Contao extensions died with "contains a Composer plugin which is blocked by
 * your allow-plugins config". A subclass costs nothing and removes that whole
 * class of failure: plain `composer require`, nothing to configure.
 *
 * Subclassing works because `Dispatcher` is neither final nor closed: the
 * handlers are public and its state is protected.
 */
final class ContaoDispatcher extends Dispatcher
{
    public function __construct(
        Configuration $configuration,
        Registry $registry,
        SubscriptionManager $subscriptionManager,
        ?SchemaValidator $schemaValidator,
        private readonly ToolFilter $toolFilter,
        private readonly PostCallHook $postCallHook,
        private readonly UnknownArgumentGuard $unknownArgumentGuard,
        private readonly LoggerInterface $mcpLogger,
    ) {
        parent::__construct($configuration, $registry, $subscriptionManager, $schemaValidator);
    }

    /**
     * Lazy-Mode: hide most tools from `tools/list` so they don't cost system
     * prompt on every turn. Hidden is not gone — `Registry::getTool()` still
     * finds them, so `contao_call` can invoke any of them.
     *
     * Filtering happens BEFORE paging, otherwise the cursor would count tools
     * that were never shown and page 2 could come back empty.
     */
    public function handleToolList(ListToolsRequest $request): ListToolsResult
    {
        $limit = $this->configuration->paginationLimit;
        $offset = self::decodeOffset($request->cursor, $this->mcpLogger);

        $allItems = array_filter(
            $this->registry->getTools(),
            fn (string $name): bool => $this->toolFilter->isExposed($name),
            ARRAY_FILTER_USE_KEY,
        );

        $pagedItems = \array_slice($allItems, $offset, $limit);

        return new ListToolsResult(
            array_values($pagedItems),
            self::encodeNextOffset($offset, \count($pagedItems), \count($allItems)),
        );
    }

    /**
     * Clears Contao's Model registry after every call. Cheap under PHP-FPM,
     * which recycles the worker anyway — it earns its place by keeping a
     * single request that runs hundreds of tool calls (a bulk import) from
     * accumulating every loaded Model until the memory limit hits.
     */
    public function handleToolCall(CallToolRequest $request): CallToolResult
    {
        // A parameter the tool does not have used to be dropped without a
        // word, so the call reported success while changing nothing.
        // php-mcp's generated schema never sets `additionalProperties`,
        // so the validation pass in the parent waves it through. Refuse
        // here instead -- before the parent runs, and therefore before
        // McpController takes its undo snapshot for a delete.
        $arguments = \is_array($request->arguments) ? $request->arguments : [];
        if ($unknownArgs = $this->unknownArgumentGuard->check($request->name, $arguments)) {
            return new CallToolResult(
                [new TextContent((string) json_encode($unknownArgs))],
                true,
            );
        }

        try {
            return parent::handleToolCall($request);
        } finally {
            try {
                $this->postCallHook->afterToolCall($request->name);
            } catch (\Throwable $e) {
                $this->mcpLogger->warning('Post-call hook failed.', ['tool' => $request->name, 'exception' => $e]);
            }
        }
    }

    /**
     * Same cursor format as the parent (`base64("offset=N")`), reimplemented
     * because the parent's helpers are private. Kept byte-compatible so a
     * cursor issued before an update still resolves.
     */
    private static function decodeOffset(?string $cursor, LoggerInterface $logger): int
    {
        if (null === $cursor) {
            return 0;
        }

        $decoded = base64_decode($cursor, true);

        if (false !== $decoded && 1 === preg_match('/^offset=(\d+)$/', $decoded, $matches)) {
            return (int) $matches[1];
        }

        $logger->warning('Received invalid pagination cursor.', ['cursor' => $cursor]);

        return 0;
    }

    private static function encodeNextOffset(int $offset, int $returned, int $total): ?string
    {
        $next = $offset + $returned;

        return $returned > 0 && $next < $total ? base64_encode("offset={$next}") : null;
    }
}
