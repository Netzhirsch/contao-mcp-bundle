<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Maintenance;

use Contao\Config;
use Contao\CoreBundle\Filesystem\Dbafs\DbafsManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\DcaLoader;
use Contao\PageModel;
use Contao\System;
use Doctrine\DBAL\Connection;
use FOS\HttpCacheBundle\CacheManager;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use PhpMcp\Server\Attributes\McpTool;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder as SymfonyFinder;

/**
 * MCP facade for the maintenance area at /contao?do=maintenance.
 *
 * Two tools:
 *   - maintenance_jobs_list — discover every available job + its current
 *     before-counts so the LLM can pick what to purge
 *   - maintenance_run — execute one or more jobs, with destructive-confirmation
 *
 * Jobs come from $GLOBALS['TL_PURGE'] (Contao\CoreBundle\contao\config\config.php).
 * They are statically callable methods on the Automator class — we honour the
 * same call pattern Contao's PurgeData module uses.
 *
 * Destructive policy:
 *   Jobs that wipe DB rows users cannot easily recover (undo/versions/log/
 *   search-index/crawl_queue) require `confirm_destructive=true`. Everything
 *   else (cache directories, sitemaps, symlinks) is safe enough to run
 *   without confirmation — those rebuild on demand.
 */
final class Tool
{
    /**
     * Jobs that wipe data which is hard or impossible to recover.
     *
     * @var list<string>
     */
    private const DESTRUCTIVE_JOBS = ['undo', 'versions', 'log', 'index', 'crawl_queue'];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly ParameterBagInterface $parameterBag,
        private readonly CacheManager $cacheManager,
        private readonly DbafsManager $dbafsManager,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Number of items per change-set bucket emitted in the response.
     * Past this cap we still report the total count, but only include this
     * many entries inline (otherwise the response would explode on a fresh
     * sync of a Contao site with 10k files).
     */
    private const DBAFS_SYNC_SAMPLE_PER_BUCKET = 50;

    /**
     * @return array{tables: list<array<string,mixed>>, folders: list<array<string,mixed>>, custom: list<array<string,mixed>>, destructive_jobs: list<string>}
     */
    #[McpTool(
        name: 'maintenance_jobs_list',
        description: <<<'DESC'
            Lists every job available in the Contao Backend's "Maintenance → Purge data" area
            (see /contao?do=maintenance), powered by $GLOBALS['TL_PURGE'].

            Three groups:
              - tables:  TRUNCATE-style purges on tl_log / tl_version / tl_undo / search-indexes
              - folders: filesystem purges (image cache, preview cache, JS/CSS cache, system/tmp)
              - custom:  one-off jobs (HTTP page-cache, sitemap regenerate, symlink rebuild)

            Each entry carries: id (pass to maintenance_run), title, description, affected
            (current rows / file count / folder size — so the LLM can show "you're about to
            delete X items"), destructive (bool — if true, maintenance_run needs
            confirm_destructive=true).
        DESC,
    )]
    public function jobsList(): array
    {
        $this->framework->initialize();

        $purge = $GLOBALS['TL_PURGE'] ?? [];

        return [
            'tables' => $this->describeTables($purge['tables'] ?? []),
            'folders' => $this->describeFolders($purge['folders'] ?? []),
            'custom' => $this->describeCustom($purge['custom'] ?? []),
            'destructive_jobs' => self::DESTRUCTIVE_JOBS,
        ];
    }

    /**
     * @param list<string> $jobs
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'maintenance_run',
        description: <<<'DESC'
            Executes one or more maintenance jobs by their id.

            Job ids come from maintenance_jobs_list. Examples:
              - "images"      → purge generated image cache
              - "scripts"     → purge JS / CSS asset cache
              - "temp"        → purge system/tmp/
              - "pages"       → invalidate HTTP page cache
              - "xml"         → regenerate sitemap.xml files
              - "log"         → TRUNCATE tl_log  (destructive — requires confirm_destructive=true)
              - "versions"    → TRUNCATE tl_version (destructive)
              - "undo"        → TRUNCATE tl_undo (destructive)
              - "index"       → wipe search-index tables (destructive)
              - "crawl_queue" → TRUNCATE tl_crawl_queue (destructive)

            Returns per-job before/after counts + duration, plus a top-level success flag.
            If any destructive job is in the list and confirm_destructive is false, the WHOLE
            call is rejected — none of the jobs run. Re-issue with confirm_destructive=true to
            execute.
        DESC,
    )]
    public function run(array $jobs, bool $confirm_destructive = false): array
    {
        $this->framework->initialize();

        if ($jobs === []) {
            return ['error' => 'invalid_input', 'message' => 'jobs must be a non-empty list of job ids.'];
        }

        $purge = $GLOBALS['TL_PURGE'] ?? [];
        $jobMap = self::flattenJobs($purge);

        // Validate every job id BEFORE running anything — atomic semantics.
        $unknown = [];
        $requestedDestructive = [];
        foreach ($jobs as $jobId) {
            if (!isset($jobMap[$jobId])) {
                $unknown[] = $jobId;
            } elseif (\in_array($jobId, self::DESTRUCTIVE_JOBS, true)) {
                $requestedDestructive[] = $jobId;
            }
        }
        if ($unknown !== []) {
            return [
                'error' => 'unknown_jobs',
                'message' => sprintf('Unknown maintenance job ids: %s', implode(', ', $unknown)),
                'unknown' => $unknown,
                'hint' => 'Call maintenance_jobs_list to see what is available.',
            ];
        }
        if ($requestedDestructive !== [] && !$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => sprintf(
                    'These jobs would wipe data that is hard to recover: %s. Re-call with confirm_destructive=true to proceed.',
                    implode(', ', $requestedDestructive),
                ),
                'destructive' => $requestedDestructive,
            ];
        }

        $results = [];
        $totalStart = (int) (microtime(true) * 1000);

        foreach ($jobs as $jobId) {
            $info = $jobMap[$jobId];
            $before = $this->snapshot($jobId, $info);
            $start = microtime(true);

            $err = null;
            try {
                [$class, $method] = $info['callback'];
                System::importStatic($class)->$method();
            } catch (\Throwable $e) {
                $err = $e->getMessage();
            }

            $duration = (int) ((microtime(true) - $start) * 1000);
            $after = $this->snapshot($jobId, $info);

            $results[] = [
                'id' => $jobId,
                'group' => $info['group'],
                'success' => $err === null,
                'error' => $err,
                'before' => $before,
                'after' => $after,
                'duration_ms' => $duration,
            ];

            $this->log(sprintf(
                'Maintenance job %s (%s) %s in %dms',
                $jobId, $info['group'], $err === null ? 'OK' : 'FAILED: '.$err, $duration,
            ), __METHOD__);
        }

        $allSuccess = !array_filter($results, static fn (array $r): bool => !$r['success']);

        return [
            'success' => $allSuccess,
            'jobs' => $results,
            'duration_ms' => (int) (microtime(true) * 1000) - $totalStart,
        ];
    }

    /**
     * @param list<int>|null $page_ids
     * @param list<string>|null $paths
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'page_cache_invalidate',
        description: <<<'DESC'
            Invalidates the HTTP page cache (reverse-proxy / FOSHttpCache). Three modes:

              - Pass `page_ids: [12, 34]` to invalidate by Contao page id — uses the cache tag
                `contao.db.tl_page.<id>` so every cached URL belonging to that page is dropped.
              - Pass `paths: ["/", "/news"]` to invalidate exact URL paths.
              - Pass neither to invalidate EVERYTHING — equivalent to maintenance_run(["pages"]).

            Returns counts of invalidated targets and any errors per target. Note: this only
            invalidates the cache; pages get re-cached on the next request.
        DESC,
    )]
    public function pageCacheInvalidate(?array $page_ids = null, ?array $paths = null): array
    {
        $this->framework->initialize();

        // Mode: nothing → global purge
        if (($page_ids === null || $page_ids === []) && ($paths === null || $paths === [])) {
            try {
                $this->cacheManager->clearCache();
                $this->cacheManager->flush();
                $this->log('Invalidated entire page cache.', __METHOD__);
                return ['mode' => 'all', 'success' => true];
            } catch (\Throwable $e) {
                return ['mode' => 'all', 'success' => false, 'error' => $e->getMessage()];
            }
        }

        $result = [
            'mode' => 'targeted',
            'invalidated_pages' => [],
            'invalidated_paths' => [],
            'errors' => [],
        ];

        // Mode: per page-id → tag-based invalidation
        if ($page_ids !== null) {
            $validIds = [];
            foreach ($page_ids as $id) {
                $id = (int) $id;
                if ($id <= 0 || PageModel::findByPk($id) === null) {
                    $result['errors'][] = sprintf('page id %d not found', $id);
                    continue;
                }
                $validIds[] = $id;
            }
            if ($validIds !== []) {
                $tags = array_map(static fn (int $id): string => 'contao.db.tl_page.'.$id, $validIds);
                try {
                    $this->cacheManager->invalidateTags($tags);
                    $result['invalidated_pages'] = $validIds;
                } catch (\Throwable $e) {
                    $result['errors'][] = 'invalidateTags: '.$e->getMessage();
                }
            }
        }

        // Mode: per path → path-based invalidation
        if ($paths !== null) {
            foreach ($paths as $path) {
                $path = (string) $path;
                if ($path === '' || !str_starts_with($path, '/')) {
                    $result['errors'][] = sprintf('path "%s" must start with "/"', $path);
                    continue;
                }
                try {
                    $this->cacheManager->invalidatePath($path);
                    $result['invalidated_paths'][] = $path;
                } catch (\Throwable $e) {
                    $result['errors'][] = sprintf('invalidatePath %s: %s', $path, $e->getMessage());
                }
            }
        }

        try {
            $this->cacheManager->flush();
        } catch (\Throwable $e) {
            $result['errors'][] = 'flush: '.$e->getMessage();
        }

        $result['success'] = $result['errors'] === [];
        $this->log(sprintf(
            'Page cache invalidated: %d page id(s), %d path(s)',
            \count($result['invalidated_pages']),
            \count($result['invalidated_paths']),
        ), __METHOD__);

        return $result;
    }

    // ─────────────────────────── helpers ────────────────────────────

    /**
     * @return list<array<string,mixed>>
     */
    private function describeTables(array $tables): array
    {
        $out = [];
        foreach ($tables as $id => $config) {
            $affected = [];
            foreach ((array) ($config['affected'] ?? []) as $table) {
                $affected[] = [
                    'table' => (string) $table,
                    'rows' => $this->countTableRows((string) $table),
                ];
            }
            $out[] = [
                'id' => (string) $id,
                'group' => 'tables',
                'title' => $GLOBALS['TL_LANG']['tl_maintenance_jobs'][$id][0] ?? (string) $id,
                'description' => $GLOBALS['TL_LANG']['tl_maintenance_jobs'][$id][1] ?? '',
                'affected' => $affected,
                'destructive' => \in_array((string) $id, self::DESTRUCTIVE_JOBS, true),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function describeFolders(array $folders): array
    {
        $out = [];
        foreach ($folders as $id => $config) {
            $affected = [];
            foreach ((array) ($config['affected'] ?? []) as $folder) {
                $resolved = $this->resolveFolderPath((string) $folder);
                $affected[] = [
                    'folder' => $resolved['rel'],
                    'file_count' => $resolved['exists'] ? $this->countFilesInFolder($resolved['abs'], (string) $id) : 0,
                    'exists' => $resolved['exists'],
                ];
            }
            $out[] = [
                'id' => (string) $id,
                'group' => 'folders',
                'title' => $GLOBALS['TL_LANG']['tl_maintenance_jobs'][$id][0] ?? (string) $id,
                'description' => $GLOBALS['TL_LANG']['tl_maintenance_jobs'][$id][1] ?? '',
                'affected' => $affected,
                'destructive' => false,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function describeCustom(array $custom): array
    {
        $out = [];
        foreach ($custom as $id => $config) {
            $out[] = [
                'id' => (string) $id,
                'group' => 'custom',
                'title' => $GLOBALS['TL_LANG']['tl_maintenance_jobs'][$id][0] ?? (string) $id,
                'description' => $GLOBALS['TL_LANG']['tl_maintenance_jobs'][$id][1] ?? '',
                'affected' => null,
                'destructive' => false,
            ];
        }

        return $out;
    }

    /**
     * Builds a flat lookup {jobId → {callback, group}} from the nested
     * $GLOBALS['TL_PURGE'] structure.
     *
     * @return array<string, array{callback: array{0:string,1:string}, group: string}>
     */
    private static function flattenJobs(array $purge): array
    {
        $out = [];
        foreach (['tables', 'folders', 'custom'] as $group) {
            foreach ($purge[$group] ?? [] as $id => $config) {
                $callback = $config['callback'] ?? null;
                if (!\is_array($callback) || \count($callback) !== 2) {
                    continue;
                }
                $out[(string) $id] = [
                    'callback' => [(string) $callback[0], (string) $callback[1]],
                    'group' => $group,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array{callback: array{0:string,1:string}, group: string} $info
     *
     * @return array<string,mixed>
     */
    private function snapshot(string $jobId, array $info): array
    {
        $purge = $GLOBALS['TL_PURGE'] ?? [];
        $config = $purge[$info['group']][$jobId] ?? [];

        if ($info['group'] === 'tables') {
            $tables = (array) ($config['affected'] ?? []);
            $totals = [];
            foreach ($tables as $t) {
                $totals[(string) $t] = $this->countTableRows((string) $t);
            }
            return ['rows_per_table' => $totals];
        }

        if ($info['group'] === 'folders') {
            $folders = (array) ($config['affected'] ?? []);
            $totals = [];
            foreach ($folders as $f) {
                $resolved = $this->resolveFolderPath((string) $f);
                $totals[$resolved['rel']] = $resolved['exists']
                    ? $this->countFilesInFolder($resolved['abs'], $jobId)
                    : 0;
            }
            return ['files_per_folder' => $totals];
        }

        // custom jobs have no measurable before/after — they regenerate state.
        return [];
    }

    private function countTableRows(string $table): int
    {
        try {
            return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$this->connection->quoteIdentifier($table));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array{rel: string, abs: string, exists: bool}
     */
    private function resolveFolderPath(string $folder): array
    {
        // Some entries are container-parameter references like %contao.image.target_dir%.
        if (str_contains($folder, '%')) {
            $folder = Path::canonicalize($this->stripRootDir((string) $this->parameterBag->resolveValue($folder)));
        }
        $abs = $this->projectDir.\DIRECTORY_SEPARATOR.$folder;
        return ['rel' => $folder, 'abs' => $abs, 'exists' => is_dir($abs)];
    }

    private function countFilesInFolder(string $abs, string $jobId): int
    {
        if (!is_dir($abs)) {
            return 0;
        }
        $finder = SymfonyFinder::create()->in($abs)->files();
        // PurgeData::run() ignores `deferred/` for the images-job (those are
        // JSON files Contao keeps around for deferred image generation).
        if ($jobId === 'images') {
            $finder->notPath('deferred');
        }

        return iterator_count($finder);
    }

    /**
     * Strips the project root from an absolute path. Cheaper than calling
     * Contao\StringUtil::stripRootDir() because it doesn't require the
     * framework to be initialized yet.
     */
    private function stripRootDir(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $this->projectDir), '/').'/';
        if (str_starts_with($normalized, $root)) {
            return substr($normalized, \strlen($root));
        }

        return $normalized;
    }

    /**
     * The four buckets Contao keeps under `var/cache/<env>/contao`, and what
     * goes stale in each when a bundle is installed or its DCA changes.
     *
     * Every one of them falls back to the sources on a miss, so clearing them
     * never takes a site down — checked, not assumed, because a cache without a
     * fallback would not be a cache but a build artefact:
     *
     *   dca       DcaLoader::loadDcaFiles() includes the source files
     *   sql       DcaExtractor::__construct() falls back to createExtract()
     *   config    Config::preload() / TemplateLoader / Model::getColumnCastTypes()
     *             each fall back to reading the sources
     *   languages System::loadLanguageFile() falls back to the .php/.xlf finder
     *
     * Whether the cache is also REWRITTEN on that miss is version-dependent,
     * which is the part worth knowing before clearing a production site:
     * Contao 5.7 re-dumps the DCA through CombinedFileDumper, Contao 5.3 does
     * not — there the files stay gone until the next `cache:warmup`, and every
     * request pays for reading the sources in the meantime. See
     * {@see self::rebuildsLazily()}.
     *
     * @var array<string, string>
     */
    private const DCA_CACHE_SCOPES = [
        'dca' => 'merged DataContainer arrays — stale after a bundle adds or changes a field',
        'sql' => 'per-table schema extracts — stale after a column changes',
        'config' => 'config.php, template map, column cast types',
        'languages' => 'compiled XLF/PHP labels — stale after a bundle ships new translations',
    ];

    /**
     * @param list<string>|null $scopes
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'dca_cache_clear',
        description: <<<'DESC'
            Discards Contao's own file caches under `var/cache/<env>/contao` so the
            next request rebuilds them from the current sources. Use it when a DCA
            change is not showing up — a bundle added a field, a palette changed, a
            label is still the old one.

            Scopes (default: all four):
              - dca        merged DataContainer arrays
              - sql        per-table schema extracts
              - config     config.php, template map, column cast types
              - languages  compiled labels

            Safe to run on a live site: every bucket falls back to reading the
            sources, so nothing breaks while the cache is cold.

            Whether the files are also REWRITTEN on that first access depends on
            the Contao version, and the answer comes back as `rebuild`:
              - "lazy"        Contao re-dumps them (5.7+). Nothing else to do.
              - "next_warmup" Contao 5.3 only reads the sources without caching
                              them again. The site stays correct but pays for it
                              on every request until `contao-console cache:warmup`
                              runs. On a busy site, clear during a deploy.

            What this does NOT do: clear the compiled Symfony container in
            `var/cache/<env>` itself. A NEW service, listener or tool needs
            `vendor/bin/contao-console cache:clear --env=prod` — that is a CLI
            operation, and doing it from inside a request would mean deleting the
            container the request is running on. `server_info` returns
            `container.compiled_at` so you can tell the two cases apart before
            reaching for the wrong remedy.

            Pass `dry_run: true` to see what would be removed. Returns per scope:
            files, bytes, and anything that could not be removed (a Windows file
            lock is the usual reason) — a partial result says so instead of
            reporting success.
        DESC,
    )]
    public function dcaCacheClear(?array $scopes = null, bool $dry_run = false): array
    {
        // The caller's own input first, the environment second. A typo in
        // `scopes` is a typo whether or not anything is cached, and answering
        // "nothing is cached yet" to it hides the typo behind a fact about the
        // machine.
        $wanted = $scopes ?? array_keys(self::DCA_CACHE_SCOPES);
        $unknown = array_values(array_diff(array_map(strval(...), $wanted), array_keys(self::DCA_CACHE_SCOPES)));

        if ($unknown !== []) {
            return [
                'error' => 'invalid_input',
                'message' => sprintf(
                    'Unknown scope %s. Available: %s.',
                    implode(', ', array_map(static fn (string $s): string => '"'.$s.'"', $unknown)),
                    implode(', ', array_keys(self::DCA_CACHE_SCOPES)),
                ),
                'available_scopes' => array_keys(self::DCA_CACHE_SCOPES),
            ];
        }

        $cacheDir = (string) $this->parameterBag->get('kernel.cache_dir');
        $base = realpath($cacheDir.\DIRECTORY_SEPARATOR.'contao');

        if ($base === false) {
            // Not an error: the postcondition — "nothing stale is cached" — is
            // already true. A freshly deployed installation that has not served
            // a request yet looks exactly like this, and answering `error`
            // there would send the caller looking for a fault that is not one.
            return [
                'cleared' => !$dry_run,
                'dry_run' => $dry_run,
                'cache_dir' => $this->stripRootDir($cacheDir.\DIRECTORY_SEPARATOR.'contao'),
                'scopes' => [],
                'files' => 0,
                'bytes' => 0,
                'note' => 'Nothing was cached — the directory does not exist yet. Contao creates it on the first request that needs a DCA.',
            ];
        }

        $result = [];
        $totalFiles = 0;
        $totalBytes = 0;
        $failed = [];

        foreach ($wanted as $scope) {
            $scope = (string) $scope;
            $dir = realpath($base.\DIRECTORY_SEPARATOR.$scope);

            // Never delete outside the Contao cache, whatever the parameter or a
            // symlink says. The scope names are ours, but the paths they resolve
            // to are the filesystem's.
            if ($dir === false || !self::isInside($dir, $base)) {
                $result[$scope] = ['files' => 0, 'bytes' => 0, 'present' => false];

                continue;
            }

            $files = 0;
            $bytes = 0;

            foreach (SymfonyFinder::create()->files()->in($dir) as $file) {
                $path = $file->getPathname();
                $size = (int) $file->getSize();

                if ($dry_run) {
                    ++$files;
                    $bytes += $size;

                    continue;
                }

                if (@unlink($path)) {
                    ++$files;
                    $bytes += $size;
                } else {
                    $failed[] = $this->stripRootDir($path);
                }
            }

            $result[$scope] = [
                'files' => $files,
                'bytes' => $bytes,
                'present' => true,
                'what' => self::DCA_CACHE_SCOPES[$scope],
            ];
            $totalFiles += $files;
            $totalBytes += $bytes;
        }

        if (!$dry_run) {
            $this->log(
                sprintf('Discarded Contao cache (%s): %d file(s) via MCP', implode(', ', $wanted), $totalFiles),
                __METHOD__,
            );
        }

        $out = [
            'cleared' => !$dry_run,
            'dry_run' => $dry_run,
            'cache_dir' => $this->stripRootDir($base),
            'scopes' => $result,
            'files' => $totalFiles,
            'bytes' => $totalBytes,
            'rebuild' => self::rebuildsLazily() ? 'lazy' : 'next_warmup',
        ];

        if (!self::rebuildsLazily() && !$dry_run && $totalFiles > 0) {
            $out['note'] = 'This Contao version reads the sources without caching them again, so every '
                .'request pays for it until `vendor/bin/contao-console cache:warmup` runs. The site is '
                .'correct in the meantime — just slower.';
        }

        if ($failed !== []) {
            // Saying "cleared" while files survived is the failure shape this
            // whole bundle keeps closing: the caller would move on believing the
            // stale entry is gone.
            $out['cleared'] = false;
            $out['failed'] = \array_slice($failed, 0, 20);
            $out['failed_count'] = \count($failed);
            $out['message'] = sprintf(
                '%d file(s) could not be removed and are still cached. On Windows another '
                .'process usually holds them open; retry, or clear on the CLI.',
                \count($failed),
            );
        }

        return $out;
    }

    /**
     * Whether this Contao writes the DCA cache back after a miss.
     *
     * Probed by capability rather than by version number on purpose: 5.3 does
     * not re-dump and 5.7 does, but which release in between drew the line is
     * not something to guess at in a message an operator will act on.
     * `DcaLoader::$freshPaths` exists only to support the re-dump path, so its
     * presence is the capability.
     *
     * If a future Contao renames it, this reports "next_warmup" for a version
     * that would in fact self-heal — an operator running cache:warmup they did
     * not strictly need. That is the right direction to be wrong in.
     */
    private static function rebuildsLazily(): bool
    {
        return property_exists(DcaLoader::class, 'freshPaths');
    }

    /**
     * Containment check for a path that has already been through realpath().
     * Case-insensitive on Windows, exact elsewhere.
     */
    private static function isInside(string $child, string $parent): bool
    {
        $parent = rtrim($parent, \DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR;
        $child = rtrim($child, \DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR;

        return \PHP_OS_FAMILY === 'Windows'
            ? str_starts_with(mb_strtolower($child), mb_strtolower($parent))
            : str_starts_with($child, $parent);
    }

    /**
     * @param list<string>|null $paths
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'dbafs_sync',
        description: <<<'DESC'
            Reconciles `tl_files` with the actual contents of the upload folder
            (and any other DBAFS-managed mount points). Equivalent to clicking
            "Synchronise" in Contao's Backend file manager, or running
            `contao:dbafs:sync` on the CLI.

            Use this when files were added / removed / renamed OUTSIDE of MCP
            (FTP, deploy script, manual rsync) and you need tl_files to catch up.
            Tools that write files via Contao itself (file_upload, file_rename,
            file_move) already keep tl_files in sync — you do NOT need to call
            this after them.

            Parameters:
              - paths: optional list of DBAFS-prefixed paths to limit the sync,
                e.g. ["files/content"]. Empty/null = sync ALL registered DBAFS.
                Paths MUST start with the upload-prefix (`files/...`); a path
                like `content/foo` is rejected because we can't tell which
                DBAFS owns it.
              - confirm_destructive: required `true` — the sync may DELETE
                tl_files rows for files removed from disk. Without confirm we
                return `destructive_confirmation_required`.

            Returns: summary (created/updated/deleted counts) + samples (first
            50 entries per bucket inline; further entries are reflected only in
            the count). `duration_ms` measures the actual sync wall time.

            Logged to tl_log with action category GENERAL and source mcp/mcp_oauth.
        DESC,
    )]
    public function dbafsSync(?array $paths = null, bool $confirm_destructive = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'dbafs_sync writes to tl_files (insert/update/delete). Pass confirm_destructive=true to proceed.',
            ];
        }

        // Validate paths if provided.
        $cleanPaths = [];
        if (\is_array($paths)) {
            foreach ($paths as $p) {
                if (!\is_string($p) || trim($p) === '') {
                    return ['error' => 'invalid_input', 'message' => 'paths must be a list of non-empty strings.'];
                }
                $clean = ltrim(str_replace('\\', '/', trim($p)), '/');
                if (str_contains($clean, '..') || str_contains($clean, "\0")) {
                    return ['error' => 'invalid_path', 'message' => 'Path contains forbidden segment: '.$p];
                }
                // DBAFS expects paths to start with the registered prefix
                // (typically `files`). Reject anything that doesn't look like
                // a DBAFS-prefixed path — DbafsManager would otherwise silently
                // skip it (no match) and we'd return a zero-change set without
                // explaining why.
                if (!str_contains($clean, '/')) {
                    return [
                        'error' => 'invalid_path',
                        'message' => 'paths must be DBAFS-prefixed, e.g. "files/content" — got: '.$p,
                    ];
                }
                $cleanPaths[] = $clean;
            }
        }

        $start = microtime(true);

        try {
            $changeSet = $this->dbafsManager->sync(...$cleanPaths);
        } catch (\Throwable $e) {
            $this->logger->error('dbafs_sync failed', ['exception' => $e]);
            return [
                'error' => 'sync_failed',
                'message' => $e->getMessage(),
                'class' => $e::class,
            ];
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $created = array_map(
            static fn ($item): array => ['path' => $item->getPath(), 'type' => $item->isFile() ? 'file' : 'directory', 'hash' => $item->getHash()],
            $changeSet->getItemsToCreate(),
        );
        $updated = array_map(
            static fn ($item): array => [
                'existing_path' => $item->getExistingPath(),
                'new_path' => $item->updatesPath() ? $item->getNewPath() : null,
                'new_hash' => $item->updatesHash() ? $item->getNewHash() : null,
            ],
            $changeSet->getItemsToUpdate(),
        );
        $deleted = array_map(
            static fn ($item): array => ['path' => $item->getPath(), 'type' => $item->isFile() ? 'file' : 'directory'],
            $changeSet->getItemsToDelete(),
        );

        $createdCount = \count($created);
        $updatedCount = \count($updated);
        $deletedCount = \count($deleted);
        $totalChanges = $createdCount + $updatedCount + $deletedCount;

        $this->log(
            sprintf(
                'DBAFS sync via MCP — %d created, %d updated, %d deleted (paths=%s, %d ms)',
                $createdCount,
                $updatedCount,
                $deletedCount,
                $cleanPaths === [] ? 'ALL' : implode(',', $cleanPaths),
                $durationMs,
            ),
            __METHOD__,
        );

        $cap = self::DBAFS_SYNC_SAMPLE_PER_BUCKET;

        return [
            'success' => true,
            'duration_ms' => $durationMs,
            'paths_synced' => $cleanPaths === [] ? null : $cleanPaths,
            'summary' => [
                'created' => $createdCount,
                'updated' => $updatedCount,
                'deleted' => $deletedCount,
                'total' => $totalChanges,
                'no_changes' => $totalChanges === 0,
            ],
            'samples' => [
                'created' => \array_slice($created, 0, $cap),
                'updated' => \array_slice($updated, 0, $cap),
                'deleted' => \array_slice($deleted, 0, $cap),
                'truncated' => $createdCount > $cap || $updatedCount > $cap || $deletedCount > $cap,
                'sample_cap' => $cap,
            ],
        ];
    }

    private function log(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::GENERAL, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }
}
