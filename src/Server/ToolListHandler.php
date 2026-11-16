<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Server;

use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;

/**
 * `tools/list` with the Lazy-Mode filter applied.
 *
 * Lazy-Mode hides most tools from `tools/list` so they don't cost system
 * prompt on every turn. Hidden is not gone — the registry still finds them,
 * so `contao_call` can invoke any of them.
 *
 * Filtering happens BEFORE paging, otherwise the cursor would count tools
 * that were never shown and page 2 could come back empty. Registered ahead of
 * the SDK's own handler, which it replaces.
 */
final class ToolListHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly RegistryAccessor $registryAccessor,
        private readonly ToolFilter $toolFilter,
        private readonly LoggerInterface $logger,
        private readonly int $pageSize = 500,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof ListToolsRequest);

        $exposed = array_filter(
            $this->registryAccessor->getToolsCached(),
            fn (string $name): bool => $this->toolFilter->isExposed($name),
            \ARRAY_FILTER_USE_KEY,
        );

        $offset = $this->decodeOffset($request->cursor);
        $page = \array_slice($exposed, $offset, max(1, $this->pageSize));

        return new Response($request->getId(), new ListToolsResult(
            array_values($page),
            self::encodeNextOffset($offset, \count($page), \count($exposed)),
        ));
    }

    /**
     * Cursor format `base64("offset=N")`, the one php-mcp issued, so a cursor
     * handed out before an update still resolves.
     */
    private function decodeOffset(?string $cursor): int
    {
        if (null === $cursor) {
            return 0;
        }

        $decoded = base64_decode($cursor, true);

        if (false !== $decoded && 1 === preg_match('/^offset=(\d+)$/', $decoded, $matches)) {
            return (int) $matches[1];
        }

        $this->logger->warning('Received invalid pagination cursor.', ['cursor' => $cursor]);

        return 0;
    }

    private static function encodeNextOffset(int $offset, int $returned, int $total): ?string
    {
        $next = $offset + $returned;

        return $returned > 0 && $next < $total ? base64_encode("offset={$next}") : null;
    }
}
