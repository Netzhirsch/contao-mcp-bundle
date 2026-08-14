<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\UrlRewrite;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;
use Terminal42\UrlRewriteBundle\Terminal42UrlRewriteBundle;

/**
 * MCP facade for tl_url_rewrite (terminal42/contao-url-rewrite).
 *
 * The host extension is optional. Every tool first asserts the bundle is
 * installed via {@see ensureAvailable()} so the LLM gets a clean
 * `extension_not_available` error instead of a missing-class crash.
 *
 * tl_url_rewrite has no Contao Model; we work directly against Doctrine DBAL.
 * Versioning is wired through the DCA (enableVersioning=true) but
 * {@see Contao\Versions} ultimately reads via DBAL too — we still skip it
 * defensively (no Model = brittle in CLI) and log all writes to tl_log
 * (ContaoContext::GENERAL).
 */
final class Tool
{
    /**
     * Marker class for the optional host bundle. Picking the Bundle class
     * itself (rather than e.g. a DependencyInjection extension) keeps the
     * check reliable across bundle versions.
     */
    private const MARKER_CLASS = Terminal42UrlRewriteBundle::class;
    private const REQUIRED_EXTENSION = 'terminal42/contao-url-rewrite';

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly FieldMapper $mapper,
        private readonly AuthorResolver $authorResolver,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}|array{error: string, message: string, required_extension: string}
     */
    #[McpTool(
        name: 'url_rewrites_list',
        description: 'Lists URL rewrites from tl_url_rewrite (terminal42/contao-url-rewrite). Sorted by priority DESC then name. Requires the bundle to be installed — returns extension_not_available otherwise.',
    )]
    public function list(int $limit = 50, int $offset = 0, ?bool $onlyActive = null): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }

        // Permission parity: tl_url_rewrite is a flat, globally-managed table
        // with NO record-level ACL in Contao — the only backend gate is the
        // owning module ("url_rewrites"), which the central enforcer already
        // checks for url_rewrites_list at call time. A user who can open that
        // module sees every rewrite in the backend, so there is nothing to
        // scope per row here (unlike news/pages, which have parent/pagemount
        // scoping). See McpPermissionGuard::VOTER_FILTERED_TABLES.
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $sql = 'SELECT * FROM tl_url_rewrite';
        $params = [];
        $types = [];
        if ($onlyActive === true) {
            $sql .= ' WHERE inactive = ?';
            $params[] = 0;
            $types[] = ParameterType::INTEGER;
        } elseif ($onlyActive === false) {
            $sql .= ' WHERE inactive = ?';
            $params[] = 1;
            $types[] = ParameterType::INTEGER;
        }
        // MySQL requires integer literals for LIMIT/OFFSET; DBAL binds `?`
        // as strings by default which leads to a 42000 syntax error.
        $sql .= ' ORDER BY priority DESC, name ASC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $types[] = ParameterType::INTEGER;
        $types[] = ParameterType::INTEGER;

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);
        $out = array_map(Serializer::summary(...), $rows);

        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'url_rewrite_get',
        description: 'Returns a single URL rewrite by id from tl_url_rewrite. Requires terminal42/contao-url-rewrite.',
    )]
    public function get(int $id): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_url_rewrite WHERE id = ?', [$id]);
        if ($row === false) {
            return ['error' => 'not_found', 'message' => "URL rewrite $id not found."];
        }

        return Serializer::summary($row);
    }

    /**
     * @param list<string>|null $requestHosts            List of allowed hostnames (empty = any).
     * @param mixed             $requestRequirements     Dict<string,string> {placeholder: regex}. Typed `mixed`
     *                                                    because php-mcp interprets `?array` as JSON list, not object.
     * @param mixed             $conditionalResponseUri  Dict<string,string> {symfony-expression: targetUri}.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'url_rewrite_create',
        description: 'Creates a URL rewrite in tl_url_rewrite. Required: name (internal label), requestPath (incoming pattern, e.g. /old-page or /shop/{slug}), responseCode (one of 301, 302, 303, 307, 410). Either responseUri OR conditionalResponseUri must be set unless responseCode=410. requestHosts is a list of allowed hostnames (empty = any). requestRequirements is a dict mapping placeholders to regex (JSON object). conditionalResponseUri is a dict mapping Symfony-expression conditions to target URIs. Logged to tl_log. Requires terminal42/contao-url-rewrite.',
    )]
    public function create(
        string $name,
        string $requestPath,
        int $responseCode,
        ?array $requestHosts = null,
        #[Schema(type: 'object')] mixed $requestRequirements = null,
        ?string $requestCondition = null,
        #[Schema(type: 'object')] mixed $conditionalResponseUri = null,
        ?string $responseUri = null,
        ?bool $keepQueryParams = null,
        ?int $priority = null,
        ?bool $active = null,
        ?string $comment = null,
    ): array {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }

        if (trim($name) === '') {
            return ['error' => 'invalid_input', 'message' => "'name' is required."];
        }
        if (trim($requestPath) === '') {
            return ['error' => 'invalid_input', 'message' => "'requestPath' is required."];
        }
        if ($responseCode !== 410 && ($responseUri === null || $responseUri === '') && empty($conditionalResponseUri)) {
            return [
                'error' => 'invalid_input',
                'message' => 'For responseCode '.$responseCode.' you must provide either responseUri or conditionalResponseUri.',
            ];
        }

        $input = array_filter(
            compact(
                'name', 'requestPath', 'responseCode', 'requestHosts', 'requestRequirements',
                'requestCondition', 'conditionalResponseUri', 'responseUri', 'keepQueryParams',
                'priority', 'active', 'comment',
            ),
            static fn ($v): bool => $v !== null,
        );

        try {
            $mapped = $this->mapper->apply([], $input);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $values = $mapped['values'];
        $values['tstamp'] = time();
        // DB defaults to inactive=0 if neither active nor inactive given on create.
        $values += ['inactive' => 0, 'priority' => 0, 'keepQueryParams' => 0, 'comment' => ''];

        try {
            $this->connection->insert('tl_url_rewrite', $values);
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $id = (int) $this->connection->lastInsertId();
        $this->log(sprintf('Created URL rewrite ID %d ("%s") via MCP', $id, $name), __METHOD__);

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_url_rewrite WHERE id = ?', [$id]);

        return ($row ? Serializer::summary($row) : ['id' => $id]) + ['created' => true];
    }

    /**
     * @param list<string>|null $requestHosts            List of allowed hostnames.
     * @param mixed             $requestRequirements     Dict<string,string>. Same `mixed`-typing as create().
     * @param mixed             $conditionalResponseUri  Dict<string,string>.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'url_rewrite_update',
        description: 'Updates a URL rewrite in tl_url_rewrite. Only fields you pass are changed. Returns changed_fields. requestRequirements and conditionalResponseUri are JSON objects (dict<string,string>). Logged to tl_log. Requires terminal42/contao-url-rewrite.',
    )]
    public function update(
        int $id,
        ?string $name = null,
        ?string $requestPath = null,
        ?int $responseCode = null,
        ?array $requestHosts = null,
        #[Schema(type: 'object')] mixed $requestRequirements = null,
        ?string $requestCondition = null,
        #[Schema(type: 'object')] mixed $conditionalResponseUri = null,
        ?string $responseUri = null,
        ?bool $keepQueryParams = null,
        ?int $priority = null,
        ?bool $active = null,
        ?string $comment = null,
    ): array {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }

        $current = $this->connection->fetchAssociative('SELECT * FROM tl_url_rewrite WHERE id = ?', [$id]);
        if ($current === false) {
            return ['error' => 'not_found', 'message' => "URL rewrite $id not found."];
        }

        $input = array_filter(
            compact(
                'name', 'requestPath', 'responseCode', 'requestHosts', 'requestRequirements',
                'requestCondition', 'conditionalResponseUri', 'responseUri', 'keepQueryParams',
                'priority', 'active', 'comment',
            ),
            static fn ($v): bool => $v !== null,
        );

        try {
            $mapped = $this->mapper->apply($current, $input);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_input', 'message' => $e->getMessage()];
        }

        $changed = $mapped['changed'];
        if ($changed === []) {
            return Serializer::summary($current) + [
                'updated' => false,
                'id' => $id,
                'changed_fields' => [],
                'applied' => 0,
            ];
        }

        $values = array_intersect_key($mapped['values'], array_flip($changed));
        $values['tstamp'] = time();

        try {
            $this->connection->update('tl_url_rewrite', $values, ['id' => $id]);
        } catch (\Throwable $e) {
            return ['error' => 'save_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->log(sprintf('Updated URL rewrite ID %d via MCP (fields: %s)', $id, implode(', ', $changed)), __METHOD__);

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_url_rewrite WHERE id = ?', [$id]);

        return ($row ? Serializer::summary($row) : ['id' => $id]) + [
            'updated' => true,
            'id' => $id,
            'changed_fields' => $changed,
            'applied' => \count($changed),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'url_rewrite_delete',
        description: 'Deletes a URL rewrite from tl_url_rewrite. Logged to tl_log. Requires terminal42/contao-url-rewrite. Requires confirm_destructive=true to proceed.',
    )]
    public function delete(int $id, bool $confirm_destructive = false): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'url_rewrite_delete is irreversible. Pass confirm_destructive=true to proceed.',
            ];
        }

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_url_rewrite WHERE id = ?', [$id]);
        if ($row === false) {
            return ['error' => 'not_found', 'message' => "URL rewrite $id not found."];
        }

        try {
            $this->connection->delete('tl_url_rewrite', ['id' => $id]);
        } catch (\Throwable $e) {
            return ['error' => 'delete_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $name = (string) ($row['name'] ?? '');
        $this->log(sprintf('Deleted URL rewrite ID %d ("%s") via MCP', $id, $name), __METHOD__);

        return ['deleted' => true, 'id' => $id, 'name' => $name];
    }

    /**
     * @return array{error: string, message: string, required_extension: string}|null
     */
    private function ensureAvailable(): ?array
    {
        if (class_exists(self::MARKER_CLASS)) {
            // Boot Contao so StringUtil + ContaoContext have what they need.
            $this->framework->initialize();
            return null;
        }

        return [
            'error' => 'extension_not_available',
            'message' => 'This tool requires the optional Contao extension "'.self::REQUIRED_EXTENSION.'", which is not installed in this project. Use installed_bundles to inspect availability.',
            'required_extension' => self::REQUIRED_EXTENSION,
        ];
    }

    private function log(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::GENERAL, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }
}
