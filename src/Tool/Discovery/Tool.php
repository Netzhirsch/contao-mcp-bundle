<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Discovery;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionEnforcer;
use Netzhirsch\ContaoMcpBundle\Service\DeletionGuard;
use Netzhirsch\ContaoMcpBundle\Service\UnknownArgumentGuard;
use Netzhirsch\ContaoMcpBundle\Service\UndoRecorder;
use Netzhirsch\ContaoMcpBundle\Server\RegistryAccessor;
use Netzhirsch\ContaoMcpBundle\Server\ToolGroups;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use PhpMcp\Server\Attributes\McpTool;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lazy-Mode discovery facade.
 *
 * When the server is started in lazy-mode (either transport — daemon or
 * controller), only these three tools are exposed in `tools/list`:
 *   - contao_search_tools  — full-text search across all 100+ registered tools
 *   - contao_describe_tool — full JSON schema + description for one tool
 *   - contao_call          — proxy that invokes any registered tool by name
 *
 * Hidden tools remain callable through `contao_call` (and, for scripts /
 * Inspector, directly via tools/call). The benefit: Claude's system prompt
 * only carries three small schemas instead of 100+, freeing ~10 KB of
 * context per turn.
 *
 * Group taxonomy is derived from the tool's name prefix
 * (`news_*` → news, `layout_*` → layout, …) so we don't need a separate
 * grouping registry.
 */
final class Tool
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly RegistryAccessor $registryAccessor,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly McpPermissionEnforcer $permissionEnforcer,
        private readonly UndoRecorder $undoRecorder,
        private readonly DeletionGuard $deletionGuard,
        private readonly UnknownArgumentGuard $unknownArgumentGuard,
    ) {
    }

    /**
     * @return array{matches: list<array<string, mixed>>, count: int, total_tools: int, query: string, group: ?string}
     */
    #[McpTool(
        name: 'contao_search_tools',
        description: <<<'DESC'
            Lazy-Mode discovery. Searches the catalogue of all available Contao MCP tools
            for matches against `query` (case-insensitive substring match against tool name
            AND description). Optional `group` restricts results to one taxonomy bucket
            (news, page, article, content, calendar, faq, theme, layout, module, image_size,
            template, file, url_rewrite, user, member, system, oauth, discovery).

            Returns up to `limit` matches as {name, group, description_excerpt}. Pass the
            name to contao_describe_tool for the full schema, or to contao_call to invoke.

            Hint: in Lazy-Mode `tools/list` only shows the three discovery tools; this
            tool is the way to find anything else.
        DESC,
    )]
    public function searchTools(string $query, ?string $group = null, int $limit = 20): array
    {
        $allTools = $this->registryAccessor->getToolsCached();
        $needle = mb_strtolower(trim($query));
        $groupFilter = $group !== null && $group !== '' ? mb_strtolower($group) : null;

        $limit = max(1, min($limit, 100));

        // Score every candidate before truncating to limit, so a tool whose
        // NAME matches the query never gets pushed out by tools that only
        // mention the query string in their description. Ranks:
        //   3 — name == query  (exact match)
        //   2 — name starts with query (prefix match)
        //   1 — name contains query (substring match)
        //   0 — only description contains query
        // Matches with the same rank keep alphabetical order (stable sort).
        $scored = [];
        foreach ($allTools as $name => $tool) {
            $toolGroup = ToolGroups::groupOf((string) $name);
            if ($groupFilter !== null && $toolGroup !== $groupFilter) {
                continue;
            }

            // Per-user visibility: don't surface tools the caller can't use.
            if (!$this->permissionEnforcer->isToolVisible((string) $name)) {
                continue;
            }

            $description = (string) ($tool->description ?? '');
            $lcName = mb_strtolower((string) $name);
            $lcDesc = mb_strtolower($description);

            $rank = -1;
            if ($needle === '') {
                $rank = 0;
            } elseif ($lcName === $needle) {
                $rank = 3;
            } elseif (str_starts_with($lcName, $needle)) {
                $rank = 2;
            } elseif (str_contains($lcName, $needle)) {
                $rank = 1;
            } elseif (str_contains($lcDesc, $needle)) {
                $rank = 0;
            }

            if ($rank < 0) {
                continue;
            }

            $scored[] = [
                'rank' => $rank,
                'name' => (string) $name,
                'group' => $toolGroup,
                'description' => $description,
            ];
        }

        // Sort: higher rank first, then alphabetical for stable output.
        usort($scored, static function (array $a, array $b): int {
            return $b['rank'] <=> $a['rank'] ?: strcmp($a['name'], $b['name']);
        });

        $matches = array_map(static fn (array $s): array => [
            'name' => $s['name'],
            'group' => $s['group'],
            'description_excerpt' => self::excerpt($s['description'], 200),
        ], \array_slice($scored, 0, $limit));

        return [
            'matches' => $matches,
            'count' => \count($matches),
            'total_tools' => \count($allTools),
            'total_matched' => \count($scored),
            'query' => $query,
            'group' => $group,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'contao_describe_tool',
        description: <<<'DESC'
            Returns the full schema for one registered tool: name, group, full description,
            JSON-Schema for parameters (with types, required-list, descriptions, defaults).

            Use this BEFORE calling contao_call so you know what `args` shape to pass.
        DESC,
    )]
    public function describeTool(string $name): array
    {
        $registry = $this->registryAccessor->get();
        $tool = $registry->getTool($name);
        // Hide tools the caller may not use behind the same "not found" answer
        // as a genuinely missing tool — no capability disclosure.
        if ($tool === null || !$this->permissionEnforcer->isToolVisible($name)) {
            return [
                'error' => 'not_found',
                'message' => sprintf('No tool with name "%s". Use contao_search_tools to find available tools.', $name),
            ];
        }

        return [
            'name' => $name,
            'group' => ToolGroups::groupOf($name),
            'description' => (string) ($tool->schema->description ?? ''),
            'input_schema' => $tool->schema->inputSchema,
        ];
    }

    /**
     * @return mixed
     */
    #[McpTool(
        name: 'contao_call',
        description: <<<'DESC'
            Proxy for invoking any registered Contao MCP tool by name. `args` is the
            payload that the target tool expects (call contao_describe_tool first to
            see its schema).

            Example: contao_call(name="layout_update", args={"id": 21,
            "fields": {"external": ["files/app.scss"]}}) is equivalent to calling
            layout_update directly.

            Errors are forwarded transparently. The schema below is intentionally
            permissive — the inner tool's schema is enforced when contao_call invokes
            it (NOT by Claude's pre-flight validation).
        DESC,
    )]
    /**
     * @param object|null $args Arguments for the target tool as a JSON object. Use contao_describe_tool(name) to see the required shape. May be omitted for parameter-less tools.
     */
    public function call(string $name, mixed $args = null): mixed
    {
        $registry = $this->registryAccessor->get();
        $tool = $registry->getTool($name);
        if ($tool === null) {
            return [
                'error' => 'not_found',
                'message' => sprintf('No tool with name "%s". Use contao_search_tools to find available tools.', $name),
            ];
        }

        $arguments = self::normaliseArgs($args);

        // Re-enforce backend-permission parity on the PROXIED tool — otherwise
        // contao_call would be a bypass around the per-tool check that the
        // controller applies to direct tools/call requests.
        if ($denial = $this->permissionEnforcer->check($name, $arguments)) {
            return $denial;
        }

        // This proxy calls $tool->call() itself, so the dispatcher's schema
        // validation never runs for it. Without this check a lazy-mode
        // instance -- where EVERY call arrives here -- would keep dropping
        // unknown parameters without a word.
        if ($unknownArgs = $this->unknownArgumentGuard->check($name, $arguments)) {
            return $unknownArgs;
        }

        // Same reasoning as the permission check above: without these, deleting
        // through the lazy-mode proxy would skip the reference guard and the
        // undo snapshot that direct tools/call requests get.
        if ($inUse = $this->deletionGuard->check($name, $arguments)) {
            return $inUse;
        }

        $undoId = $this->undoRecorder->beforeToolCall($name, $arguments);

        try {
            $contentItems = $tool->call($this->container, $arguments);
        } catch (\Throwable $e) {
            $this->undoRecorder->discard($undoId);

            // Same reasoning as in McpController::opaqueError(): an unexpected
            // exception can carry SQL fragments or paths. Tools report their own
            // *expected* errors as structured results — those are unaffected and
            // still tell the model exactly what to fix.
            $reference = bin2hex(random_bytes(4));
            $this->log(sprintf('contao_call(%s) failed [ref %s]: %s', $name, $reference, $e->getMessage()), __METHOD__);

            return [
                'error' => 'tool_failed',
                'tool' => $name,
                'message' => sprintf('Internal error while running "%s" (reference %s). See the Contao application log.', $name, $reference),
            ];
        }

        // Unwrap MCP Content[] back to the original PHP value the underlying
        // tool returned, so the LLM gets the same shape it would have gotten
        // from a direct tools/call (an object / array / scalar).
        $result = self::unwrapContent($contentItems);

        // The tool reported a problem → nothing was deleted, drop the snapshot.
        if (\is_array($result) && isset($result['error'])) {
            $this->undoRecorder->discard($undoId);
        }

        return $result;
    }

    // ─────────────────────────── helpers ────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function normaliseArgs(mixed $args): array
    {
        if ($args === null) {
            return [];
        }
        if (\is_object($args)) {
            return (array) $args;
        }
        if (\is_array($args)) {
            return $args;
        }
        throw new \InvalidArgumentException('`args` must be a JSON object.');
    }

    private static function excerpt(string $text, int $maxLength): string
    {
        // Strip leading/trailing whitespace + collapse internal whitespace
        // (descriptions are written as heredoc with indentation).
        $clean = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (mb_strlen($clean) <= $maxLength) {
            return $clean;
        }

        return rtrim(mb_substr($clean, 0, $maxLength - 1), '.,; ').'…';
    }

    /**
     * RegisteredTool::call() returns Content[]. For our purposes we want the
     * original PHP value back — a single TextContent with a JSON body is
     * decoded, anything else is left in its MCP shape (rare for tool results).
     */
    private static function unwrapContent(array $contentItems): mixed
    {
        if (\count($contentItems) === 1) {
            $only = $contentItems[0];
            if (is_object($only) && property_exists($only, 'text')) {
                $text = (string) $only->text;
                $decoded = json_decode($text, true);
                if (json_last_error() === \JSON_ERROR_NONE) {
                    return $decoded;
                }
                return $text;
            }
        }

        // Mixed / multiple content items: pass through as-is so the framework
        // re-encodes them.
        return $contentItems;
    }

    private function log(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::GENERAL, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }
}
