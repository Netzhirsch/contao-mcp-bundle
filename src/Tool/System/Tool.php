<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\System;

use Composer\InstalledVersions;
use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\StringUtil;
use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\FieldProviderRegistry;
use Netzhirsch\ContaoMcpBundle\Service\QueryFilterResolver;
use PhpMcp\Server\Attributes\McpTool;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Terminal42\LeadsBundle\Terminal42LeadsBundle;
use Terminal42\UrlRewriteBundle\Terminal42UrlRewriteBundle;

/**
 * System-level introspection tools. Four endpoints:
 *
 *   - ping              — health probe; cheap server-reachability check.
 *   - installed_bundles — Symfony bundles + Contao packages + MCP field-extensions.
 *   - contao_version    — Contao / PHP / Symfony versions in one shot.
 *   - system_settings   — global Contao system settings (Backend Einstellungen).
 */
final class Tool
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly FieldProviderRegistry $providers,
        private readonly ContaoFramework $framework,
        private readonly LoggerInterface $logger,
        private readonly AuthorResolver $authorResolver,
        private readonly McpServerConfigStorage $configStorage,
        private readonly QueryFilterResolver $queryFilterResolver,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Tables that ship `list`-tools with search/filter capability. Listed
     * here so `entity_query_options` can reject unknown tables with a clear
     * "use one of these" message before even loading the DCA.
     *
     * Keep in sync with the actual `*_list` tools that wire QueryFilterResolver.
     */
    private const QUERYABLE_TABLES = [
        'tl_news',
        'tl_page',
        'tl_article',
        'tl_calendar_events',
        'tl_faq',
        'tl_member',
        'tl_member_group',
        'tl_form',
        'tl_form_field',
        'tl_comments',
        'tl_theme',
        'tl_layout',
        'tl_module',
        'tl_image_size',
        'tl_user',
        'tl_news_archive',
        'tl_calendar',
        'tl_faq_category',
    ];

    /**
     * @return array{table: string, searchable_fields: list<string>, filterable_fields: array<string, mixed>, has_tstamp: bool, supports_q: bool, supports_filters: bool, supports_updated_range: bool}|array{error: string, message: string, supported_tables?: list<string>}
     */
    #[McpTool(
        name: 'entity_query_options',
        description: <<<'DESC'
            Returns the search / filter shape of an entity table — `searchable_fields`
            (which columns the `q`-LIKE-search covers in the corresponding `*_list`
            tool), `filterable_fields` (which keys are valid in `filters: {…}`), and
            whether updated_after / updated_before timestamp ranges are supported.

            Call this BEFORE constructing a complex list query so you know what's
            queryable for the table without trial-and-error.

            Example for tl_news:
              { searchable_fields: ["headline", "alias", "author"],
                filterable_fields: {
                    "published": {type: "boolean"},
                    "pid": {type: "foreign_key", references: ["tl_news_archive"]},
                    "featured": {type: "boolean"} },
                has_tstamp: true,
                supports_q: true,
                supports_filters: true,
                supports_updated_range: true }

            Supported tables grow as `*_list` tools wire the resolver. For unsupported
            tables this returns an `error` with the current supported list.
        DESC,
    )]
    public function entityQueryOptions(string $table): array
    {
        if (!\in_array($table, self::QUERYABLE_TABLES, true)) {
            return [
                'error' => 'unsupported_table',
                'message' => sprintf(
                    'Table "%s" does not (yet) expose search/filter options. The corresponding *_list tool may still work without `q`/`filters`.',
                    $table,
                ),
                'supported_tables' => self::QUERYABLE_TABLES,
            ];
        }

        try {
            $opts = $this->queryFilterResolver->discover($table);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'unknown_dca', 'message' => $e->getMessage()];
        }

        return [
            'table' => $opts['table'],
            'searchable_fields' => $opts['searchable_fields'],
            'filterable_fields' => $opts['filterable_fields'],
            'has_tstamp' => $opts['has_tstamp'],
            'supports_q' => $opts['searchable_fields'] !== [],
            'supports_filters' => $opts['filterable_fields'] !== [],
            'supports_updated_range' => $opts['has_tstamp'],
            'examples' => self::filterExamples($table, $opts['filterable_fields']),
        ];
    }

    /**
     * Curated example filter combinations for the queryable tables, plus a
     * best-effort fallback generated from the DCA-filterable field set.
     * The examples help both human operators ("how do I narrow this down?")
     * and the LLM ("what's a sensible default filter for tl_content?").
     *
     * @param array<string, array<string, mixed>> $filterableFields
     *
     * @return list<array{description: string, filters: array<string, mixed>}>
     */
    private static function filterExamples(string $table, array $filterableFields): array
    {
        $curated = [
            'tl_news' => [
                ['description' => 'Published news in archive 1', 'filters' => ['pid' => 1, 'published' => '1']],
                ['description' => 'Featured news only', 'filters' => ['featured' => '1']],
            ],
            'tl_page' => [
                ['description' => 'All root pages', 'filters' => ['type' => 'root']],
                ['description' => 'Published regular pages with their own layout', 'filters' => ['type' => 'regular', 'published' => '1']],
                ['description' => 'Pages hidden from search', 'filters' => ['noSearch' => '1']],
            ],
            'tl_article' => [
                ['description' => 'Articles on page 5, in main column', 'filters' => ['pid' => 5, 'inColumn' => 'main']],
                ['description' => 'Protected articles', 'filters' => ['protected' => '1']],
            ],
            'tl_calendar_events' => [
                ['description' => 'Published events in calendar 2', 'filters' => ['pid' => 2, 'published' => '1']],
                ['description' => 'Featured events', 'filters' => ['featured' => '1']],
            ],
            'tl_member' => [
                ['description' => 'Login-enabled, active accounts', 'filters' => ['login' => '1', 'disable' => '']],
                ['description' => 'Members in group 3', 'filters' => ['groups' => [3]]],
            ],
            'tl_form_field' => [
                ['description' => 'Submit buttons across all forms', 'filters' => ['type' => 'submit']],
                ['description' => 'Mandatory fields on form 1', 'filters' => ['pid' => 1, 'mandatory' => '1']],
            ],
            'tl_module' => [
                ['description' => 'Navigation modules in theme 1', 'filters' => ['pid' => 1, 'type' => 'navigation']],
            ],
            'tl_comments' => [
                ['description' => 'Unmoderated (unpublished) comments', 'filters' => ['published' => '']],
            ],
        ];

        if (isset($curated[$table])) {
            return $curated[$table];
        }

        // Fallback: synthesise a tiny example from the first 1-2 filterable
        // boolean / enum fields the DCA exposes. Better than nothing for tables
        // without curated examples.
        $synthetic = [];
        foreach ($filterableFields as $col => $info) {
            $type = (string) ($info['type'] ?? 'text');
            if ($type === 'boolean') {
                $synthetic = ['description' => sprintf('Rows with %s=true', $col), 'filters' => [$col => '1']];
                break;
            }
            if ($type === 'enum' && !empty($info['values'])) {
                $firstValue = (string) ($info['values'][0] ?? '');
                if ($firstValue !== '') {
                    $synthetic = ['description' => sprintf('Rows with %s="%s"', $col, $firstValue), 'filters' => [$col => $firstValue]];
                    break;
                }
            }
        }

        return $synthetic === [] ? [] : [$synthetic];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'server_info',
        description: <<<'DESC'
            Returns runtime info about the MCP server: the current PHP-FPM
            worker's pid (for log-correlation), the kernel's compiled
            container path + compile time, and the configured transport
            settings (path, backend_url).

            Cheap to call — no DB queries. Useful at the start of a long
            Skill-2-session to confirm which Symfony cache the request is
            actually being served from. If your code changes don't appear,
            compare `container.compiled_at` against the on-disk file mtimes
            and run `vendor/bin/contao-console cache:clear --env=prod`.
        DESC,
    )]
    public function serverInfo(): array
    {
        $config = $this->configStorage->load();

        // Container metadata: operators want to know which compiled container
        // is in play (helps spot stale Symfony caches under PHP-FPM).
        $containerClass = $this->kernel->getContainer()::class;
        $containerCompiledAt = null;
        $containerPath = null;
        try {
            $rc = new \ReflectionClass($containerClass);
            $file = $rc->getFileName();
            if ($file !== false && is_file($file)) {
                $containerPath = $file;
                $mtime = filemtime($file);
                if ($mtime !== false) {
                    $containerCompiledAt = $mtime;
                }
            }
        } catch (\Throwable) {
            // Best-effort — never fail because of reflection trouble.
        }

        return [
            'pid' => getmypid(),
            'now' => time(),
            'transport' => [
                'path' => (string) ($config['path'] ?? 'mcp'),
                'backend_url' => (string) ($config['backend_url'] ?? ''),
            ],
            'container' => [
                'class' => $containerClass,
                'path' => $containerPath,
                'compiled_at' => $containerCompiledAt,
                'compiled_at_iso' => $containerCompiledAt !== null ? (new \DateTimeImmutable('@'.$containerCompiledAt))->format(\DateTimeInterface::ATOM) : null,
            ],
        ];
    }

    /**
     * @return array{message: string, serverTime: string}
     */
    #[McpTool(
        name: 'ping',
        description: 'Health-check tool that returns "pong" plus the current server time. Use this to verify the MCP server is reachable.',
    )]
    public function ping(): array
    {
        return [
            'message' => 'pong',
            'serverTime' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'contao_version',
        description: 'Returns the installed versions of the Contao stack (core-bundle, manager-bundle), PHP, and Symfony framework-bundle. Use this for compatibility checks before relying on version-specific behaviour.',
    )]
    public function contaoVersion(): array
    {
        return [
            'contao_core_bundle' => self::installedVersion('contao/core-bundle'),
            'contao_manager_bundle' => self::installedVersion('contao/manager-bundle'),
            'symfony_framework_bundle' => self::installedVersion('symfony/framework-bundle'),
            'php' => \PHP_VERSION,
            'kernel_env' => $this->kernel->getEnvironment(),
            'kernel_debug' => $this->kernel->isDebug(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'installed_bundles',
        description: 'Lists which Symfony bundles, Contao packages and MCP field-extensions are present in this Contao installation. Use this when an extension-specific field is rejected with "extension_not_available" to confirm what is actually available.',
    )]
    public function installedBundles(): array
    {
        return [
            'symfony_bundles' => $this->collectSymfonyBundles(),
            'contao_packages' => $this->collectContaoPackages(),
            'mcp_field_extensions' => $this->collectFieldExtensions(),
            'mcp_entity_extensions' => $this->collectEntityExtensions(),
        ];
    }

    /**
     * Lists optional Contao extensions for which we expose dedicated entity tools
     * (whole-table CRUD, not field-level extras). Each entry includes an
     * `available` flag so the LLM can avoid calling tools that will error out
     * with `extension_not_available`.
     *
     * @return list<array{required_extension: string, table: string, available: bool, provided_tools: list<string>}>
     */
    private function collectEntityExtensions(): array
    {
        return [
            [
                'required_extension' => 'terminal42/contao-url-rewrite',
                'table' => 'tl_url_rewrite',
                'available' => class_exists(Terminal42UrlRewriteBundle::class),
                'provided_tools' => [
                    'url_rewrites_list',
                    'url_rewrite_get',
                    'url_rewrite_create',
                    'url_rewrite_update',
                    'url_rewrite_delete',
                ],
            ],
            [
                'required_extension' => 'terminal42/contao-leads',
                'table' => 'tl_lead',
                'available' => class_exists(Terminal42LeadsBundle::class),
                // Read-only: leads are captured from frontend form submissions,
                // so there is no create/update/delete surface.
                'provided_tools' => [
                    'leads_list',
                    'lead_get',
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function collectSymfonyBundles(): array
    {
        $names = [];
        foreach ($this->kernel->getBundles() as $bundle) {
            $names[] = $bundle->getName();
        }
        sort($names);

        return $names;
    }

    /**
     * @return list<array{name: string, version: string, type: string}>
     */
    private function collectContaoPackages(): array
    {
        $lockFile = $this->projectDir.\DIRECTORY_SEPARATOR.'composer.lock';
        if (!is_readable($lockFile)) {
            return [];
        }

        $raw = file_get_contents($lockFile);
        if ($raw === false) {
            return [];
        }
        $lock = json_decode($raw, true);
        if (!\is_array($lock)) {
            return [];
        }

        $packages = [];
        foreach (($lock['packages'] ?? []) as $pkg) {
            $name = (string) ($pkg['name'] ?? '');
            $type = (string) ($pkg['type'] ?? '');
            if ($name === '') {
                continue;
            }
            $isContaoFamily = $type === 'contao-bundle'
                || $type === 'contao-component'
                || $type === 'contao-module'
                || str_starts_with($name, 'contao/')
                || str_starts_with($name, 'terminal42/contao-')
                || str_contains($name, '/contao-');
            if (!$isContaoFamily) {
                continue;
            }
            $packages[] = [
                'name' => $name,
                'version' => (string) ($pkg['version'] ?? ''),
                'type' => $type,
            ];
        }

        usort($packages, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $packages;
    }

    /**
     * @return list<array{required_extension: string, table: string, available: bool, declared_fields: list<string>}>
     */
    private function collectFieldExtensions(): array
    {
        $out = [];
        foreach ($this->providers->all() as $provider) {
            $out[] = [
                'required_extension' => $provider->getRequiredExtension(),
                'table' => $provider->getTable(),
                'available' => $provider->isAvailable(),
                'declared_fields' => $provider->getDeclaredFields(),
            ];
        }

        return $out;
    }

    /**
     * Maps each tl_settings legend (the groups you see in the Contao backend
     * "Einstellungen" view) to the Contao\Config keys it contains.
     *
     * @var array<string, list<string>>
     */
    private const SETTINGS_GROUPS = [
        'global' => ['adminEmail'],
        'date' => ['dateFormat', 'timeFormat', 'datimFormat', 'timeZone'],
        'backend' => ['resultsPerPage', 'maxResultsPerPage'],
        'security' => ['allowedTags', 'allowedAttributes'],
        'files' => ['allowedDownload'],
        'uploads' => ['uploadTypes', 'maxFileSize', 'imageWidth', 'imageHeight'],
        'timeout' => ['undoPeriod', 'versionPeriod', 'logPeriod'],
        'chmod' => ['defaultUser', 'defaultGroup', 'defaultChmod'],
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    #[McpTool(
        name: 'system_settings',
        description: 'Returns the global Contao system settings (Backend → Einstellungen) grouped by the palette legends: global, date, backend, security, files, uploads, timeout, chmod. Values come from Contao\\Config (file-based localconfig + framework defaults). Serialised fields (allowedAttributes, defaultChmod) are decoded into JSON-friendly shapes.',
    )]
    public function systemSettings(): array
    {
        $this->framework->initialize();
        $config = $this->framework->getAdapter(Config::class);

        $out = [];
        foreach (self::SETTINGS_GROUPS as $group => $keys) {
            $section = [];
            foreach ($keys as $key) {
                $section[$key] = self::decodeSettingValue($key, $config->get($key));
            }
            $out[$group] = $section;
        }

        return $out;
    }

    /**
     * Per-field post-processing for settings that are stored as PHP-serialised blobs.
     */
    private static function decodeSettingValue(string $key, mixed $value): mixed
    {
        return match ($key) {
            // rowWizard: list of {key, value} pairs
            'allowedAttributes' => self::decodeAllowedAttributes($value),
            // chmod picker: list of "u1".."u6" / "g1".."g6" / "w1".."w6"
            'defaultChmod' => self::decodeFlagList($value),
            default => $value,
        };
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private static function decodeAllowedAttributes(mixed $value): array
    {
        $arr = StringUtil::deserialize($value, true);
        if (!\is_array($arr)) {
            return [];
        }
        $out = [];
        foreach ($arr as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $out[] = [
                'key' => (string) ($row['key'] ?? ''),
                'value' => (string) ($row['value'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function decodeFlagList(mixed $value): array
    {
        $arr = StringUtil::deserialize($value, true);
        if (!\is_array($arr)) {
            return [];
        }

        return array_values(array_map('strval', $arr));
    }

    private static function installedVersion(string $package): ?string
    {
        if (!InstalledVersions::isInstalled($package)) {
            return null;
        }

        return InstalledVersions::getPrettyVersion($package);
    }

    /**
     * Whitelist of tl_settings keys that are safe to set via MCP without
     * a destructive-confirm. Things like the hashed admin password or the
     * encryption key are deliberately NOT here — those need a hardcoded
     * confirm flag below.
     *
     * @var list<string>
     */
    private const SAFE_SETTINGS = [
        'adminEmail', 'dateFormat', 'datimFormat', 'imageHeight', 'imageWidth',
        'jpgQuality', 'logoutLength', 'maxFileSize', 'maxImageWidth',
        'minPasswordLength', 'sessionTimeout', 'showHelp', 'timeFormat',
        'timeZone', 'undoPeriod', 'uploadPath', 'uploadTypes', 'urlSuffix',
        'versionPeriod', 'websiteTitle',
    ];

    /*
     * Why the list above is curated and not derived at runtime.
     *
     * The obvious fix for "the tool confirms keys that no longer exist" is to
     * ask the installation instead of maintaining a list. It does not work:
     * Contao 5 has no complete machine-readable catalogue of settings.
     *
     *   - the tl_settings DCA covers only the 20 fields of the backend form
     *   - contao/config/default.php holds defaults for a subset, and
     *     `websiteTitle` — a perfectly valid setting — is in neither
     *   - $GLOBALS['TL_CONFIG'] is defaults PLUS whatever was ever persisted,
     *     so a bogus key becomes "known" the moment someone writes it once,
     *     while a valid-but-unset key looks unknown
     *
     * Both directions were tried and both were wrong: deriving from the DCA
     * union TL_CONFIG passed locally and then rejected `websiteTitle` on a
     * fresh instance in CI, because nothing had persisted it there yet.
     *
     * So the list stays curated, and it was pruned against Contao 5.7.11 —
     * gdMaxImgWidth, useFTP, characterSet and the rest of the Contao-3
     * leftovers are gone, which is what made the tool confirm writes that
     * could never take effect. Keys removed somewhere between 5.3 and 5.7 can
     * still slip through; the description says so and points at reading back.
     */

    /**
     * Settings whose values are sensitive — writes require confirm_dangerous=true.
     * encryptionKey rotation invalidates every session+token. rootPasswordHash
     * would let an attacker set themselves as admin.
     *
     * @var list<string>
     */
    private const DANGEROUS_SETTINGS = [
        'encryptionKey', 'rootPasswordHash', 'allowedAttributes', 'defaultChmod',
    ];

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'system_settings_update',
        description: <<<'DESC'
            Persists changes to Contao's global system settings ("/contao?do=settings").
            Pass `settings` as a JSON object mapping setting-key → new-value.

            Safe keys (set without ceremony): adminEmail, dateFormat, datimFormat, timeFormat,
            timeZone, websiteTitle, showHelp, undoPeriod, versionPeriod, sessionTimeout,
            logoutLength, minPasswordLength, maxFileSize, uploadTypes, uploadPath, urlSuffix,
            imageWidth, imageHeight, jpgQuality, maxImageWidth.

            Dangerous keys (require confirm_dangerous=true): encryptionKey (would invalidate every
            session + cookie), rootPasswordHash (admin password override), allowedAttributes,
            defaultChmod.

            A key outside the two lists is rejected outright. The lists were checked against
            Contao 5.7 — the Contao-3 leftovers they used to carry (gdMaxImgWidth and friends)
            are gone, because writing those landed in the local config where nothing reads them
            and the call still reported success.

            Contao offers no complete catalogue of valid settings, so a key that disappeared
            between 5.3 and 5.7 can still be accepted here without taking effect. Read back with
            system_settings when it matters.

            Returns {updated: list<string>, errors: list<string>, success: bool}.
        DESC,
    )]
    /**
     * @param object|null $settings Map of tl_settings key to new value as a JSON object.
     */
    public function systemSettingsUpdate(mixed $settings = null, bool $confirm_dangerous = false): array
    {
        $this->framework->initialize();

        $input = self::normaliseSettings($settings);
        if ($input === []) {
            return ['error' => 'invalid_input', 'message' => '`settings` must be a non-empty JSON object'];
        }

        $allowed = array_merge(self::SAFE_SETTINGS, self::DANGEROUS_SETTINGS);
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown !== []) {
            return [
                'error' => 'unknown_settings',
                'message' => sprintf('Unknown setting keys: %s', implode(', ', $unknown)),
                'unknown' => array_values($unknown),
                'hint' => 'Call system_settings (no args) to see the full catalogue of writable keys.',
            ];
        }

        $dangerousAttempts = array_intersect(array_keys($input), self::DANGEROUS_SETTINGS);
        if ($dangerousAttempts !== [] && !$confirm_dangerous) {
            return [
                'error' => 'dangerous_confirmation_required',
                'message' => sprintf(
                    'These keys are dangerous and would break sessions / security: %s. Re-call with confirm_dangerous=true.',
                    implode(', ', $dangerousAttempts),
                ),
                'dangerous' => array_values($dangerousAttempts),
            ];
        }

        $updated = [];
        $errors = [];
        foreach ($input as $key => $value) {
            try {
                // persist() writes to system/config/dcaconfig.php for the next
                // request, but does NOT touch $GLOBALS['TL_CONFIG'] in the
                // currently-running process. set() does — call both so the
                // change is visible IMMEDIATELY via Config::get() in this
                // process AND persisted for future requests.
                Config::persist((string) $key, $value);
                Config::set((string) $key, $value);
                $updated[] = (string) $key;
            } catch (\Throwable $e) {
                $errors[] = sprintf('%s: %s', $key, $e->getMessage());
            }
        }

        $this->logger->info(
            sprintf('Updated system settings: %s', implode(', ', $updated)),
            ['contao' => new ContaoContext(__METHOD__, ContaoContext::CONFIGURATION, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())],
        );

        return [
            'updated' => $updated,
            'errors' => $errors,
            'success' => $errors === [],
        ];
    }

    /**
     * @return array{groups: array<string, list<array<string,mixed>>>, total: int}
     */
    #[McpTool(
        name: 'insert_tags_list',
        description: <<<'DESC'
            Discovery for Contao Insert Tags (`{{date::d.m.Y}}`, `{{env::path}}`, `{{news_url::42}}`, …).
            Returns the known tag families grouped by category with a short description, syntax pattern,
            and (when applicable) an example.

            Source: hardcoded catalogue of Contao Core tags + any tags registered via
            $GLOBALS['TL_HOOKS']['replaceInsertTags'] (extension-contributed). The list is meant
            as a creation aid for the LLM — at render-time more tags may apply depending on the
            request context.
        DESC,
    )]
    public function insertTagsList(): array
    {
        $this->framework->initialize();

        $catalogue = [
            'date_time' => [
                ['tag' => 'date', 'syntax' => '{{date::format}}', 'example' => '{{date::d.m.Y}}', 'desc' => 'Current date formatted (PHP date() format string).'],
                ['tag' => 'last_update', 'syntax' => '{{last_update::format}}', 'example' => '{{last_update::d.m.Y}}', 'desc' => 'Last DB update timestamp.'],
            ],
            'page' => [
                ['tag' => 'page', 'syntax' => '{{page::field}}', 'example' => '{{page::title}}', 'desc' => 'Current page field (title, pageTitle, description, language, …).'],
                ['tag' => 'parent', 'syntax' => '{{parent::field}}', 'example' => '{{parent::title}}', 'desc' => 'Parent page field.'],
                ['tag' => 'request_token', 'syntax' => '{{request_token}}', 'example' => '{{request_token}}', 'desc' => 'CSRF token for forms.'],
            ],
            'links_urls' => [
                ['tag' => 'link', 'syntax' => '{{link::pageId|alias}}', 'example' => '{{link::5}}', 'desc' => 'Anchor element to a Contao page (uses page title as text).'],
                ['tag' => 'link_url', 'syntax' => '{{link_url::pageId|alias}}', 'example' => '{{link_url::5}}', 'desc' => 'URL of a Contao page (no anchor).'],
                ['tag' => 'link_open', 'syntax' => '{{link_open::pageId|alias}}', 'example' => '{{link_open::5}}', 'desc' => 'Opening &lt;a&gt; tag only.'],
                ['tag' => 'link_close', 'syntax' => '{{link_close}}', 'example' => '{{link_close}}', 'desc' => 'Closing &lt;/a&gt;.'],
                ['tag' => 'article_url', 'syntax' => '{{article_url::articleId}}', 'example' => '{{article_url::3}}', 'desc' => 'URL to a single article.'],
                ['tag' => 'news_url', 'syntax' => '{{news_url::newsId}}', 'example' => '{{news_url::42}}', 'desc' => 'URL to a news entry.'],
                ['tag' => 'event_url', 'syntax' => '{{event_url::eventId}}', 'example' => '{{event_url::7}}', 'desc' => 'URL to a calendar event.'],
                ['tag' => 'faq_url', 'syntax' => '{{faq_url::faqId}}', 'example' => '{{faq_url::1}}', 'desc' => 'URL to an FAQ entry.'],
            ],
            'environment' => [
                ['tag' => 'env', 'syntax' => '{{env::key}}', 'example' => '{{env::host}}', 'desc' => 'Environment value (host, base, url, path, request, ip, locale, language, …).'],
                ['tag' => 'ua', 'syntax' => '{{ua::field}}', 'example' => '{{ua::browser}}', 'desc' => 'User-agent info.'],
            ],
            'user' => [
                ['tag' => 'user', 'syntax' => '{{user::field}}', 'example' => '{{user::email}}', 'desc' => 'Field of the currently logged-in member (front-end).'],
                ['tag' => 'br', 'syntax' => '{{br}}', 'example' => '{{br}}', 'desc' => 'Inline &lt;br&gt; (useful inside attributes).'],
                ['tag' => 'lang', 'syntax' => '{{lang::xx}}content{{lang}}', 'example' => '{{lang::en}}Hello{{lang}}', 'desc' => 'Show content only when site language matches.'],
            ],
            'content' => [
                ['tag' => 'insert_article', 'syntax' => '{{insert_article::articleId}}', 'example' => '{{insert_article::3}}', 'desc' => 'Inline-render another article.'],
                ['tag' => 'insert_content', 'syntax' => '{{insert_content::ceId}}', 'example' => '{{insert_content::99}}', 'desc' => 'Inline-render a content element.'],
                ['tag' => 'insert_module', 'syntax' => '{{insert_module::moduleId}}', 'example' => '{{insert_module::3}}', 'desc' => 'Inline-render a front-end module.'],
                ['tag' => 'insert_form', 'syntax' => '{{insert_form::formId}}', 'example' => '{{insert_form::1}}', 'desc' => 'Inline-render a form.'],
            ],
            'files' => [
                ['tag' => 'file', 'syntax' => '{{file::fileId|path}}', 'example' => '{{file::files/foo.jpg}}', 'desc' => 'File URL (absolute).'],
                ['tag' => 'image', 'syntax' => '{{image::path|width,height,mode}}', 'example' => '{{image::files/foo.jpg|800,600,box}}', 'desc' => 'Resized image (HTML img tag).'],
            ],
        ];

        // Extension-registered insert-tag hooks. The hook signature varies but
        // the registration array has [class, method, priority?] per entry.
        $hooks = $GLOBALS['TL_HOOKS']['replaceInsertTags'] ?? [];
        $extensionHooks = [];
        foreach ($hooks as $hook) {
            if (!\is_array($hook) || \count($hook) < 2) {
                continue;
            }
            $extensionHooks[] = [
                'class' => (string) $hook[0],
                'method' => (string) $hook[1],
            ];
        }

        $total = 0;
        foreach ($catalogue as $entries) {
            $total += \count($entries);
        }

        return [
            'groups' => $catalogue,
            'total' => $total,
            'extension_hooks' => $extensionHooks,
            'note' => $extensionHooks !== []
                ? 'Extensions register their own insert tags via the replaceInsertTags hook — inspect the listed classes for additional tag names.'
                : 'No extension-registered insert-tag hooks detected.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'system_health_check',
        description: <<<'DESC'
            Diagnostic dump — PHP setup, file-system permissions, OAuth
            readiness. Run this BEFORE moving the bundle to a new host to
            spot setup issues before they bite at runtime.

            Categories returned:
              - php:       version, SAPI, key extensions (openssl, mbstring, …)
              - storage:   var/mcp/ writable, var/mcp/oauth/ writable + perms
              - oauth:     auth_mode, backend_url presence + scheme, key perms
              - config:    current var/mcp/config.json contents (sanitised)
              - filesystem: public/files + public/assets symlinks (missing → asset 404s)
              - warnings:  list of "you should fix this before going live" hints
        DESC,
    )]
    public function systemHealthCheck(): array
    {
        $this->framework->initialize();

        $config = $this->configStorage->load();

        $mcpDir = $this->projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'mcp';
        $oauthDir = $mcpDir.\DIRECTORY_SEPARATOR.'oauth';

        $warnings = [];

        // PHP capabilities. Under PHP-FPM, opcache + posix concerns don't
        // apply — FPM workers recycle, the OAuth crypto path uses openssl
        // directly (not posix), and there is no long-running process to
        // signal.
        if (!\extension_loaded('openssl')) {
            $warnings[] = 'PHP openssl extension is NOT loaded — OAuth will fail. Install php-openssl.';
        }

        // Storage / permissions. The mcp_dir holds config.json + OAuth key
        // material.
        $mcpWritable = is_dir($mcpDir) ? is_writable($mcpDir) : is_writable(\dirname($mcpDir));
        if (!$mcpWritable) {
            $warnings[] = sprintf(
                '%s is not writable for the current user — KeyManager and config storage will fail. Fix: chown -R %s %s && chmod 755 %s',
                $mcpDir,
                $this->currentUserName(),
                $mcpDir,
                $mcpDir,
            );
        }

        // OAuth readiness
        $oauthReady = true;
        if (($config['auth_mode'] ?? 'none') === 'oauth') {
            if (empty($config['backend_url'])) {
                $warnings[] = 'auth_mode=oauth but backend_url is empty — Discovery (/.well-known/oauth-authorization-server) cannot advertise endpoints.';
                $oauthReady = false;
            } elseif (str_starts_with((string) $config['backend_url'], 'http://')
                && !\in_array(parse_url((string) $config['backend_url'], \PHP_URL_HOST), ['localhost', '127.0.0.1', '[::1]'], true)) {
                $warnings[] = 'auth_mode=oauth + backend_url uses plain http:// on a non-loopback host — access tokens travel in clear text. Use HTTPS in production.';
            }
            if (is_dir($oauthDir) && !is_writable($oauthDir)) {
                $warnings[] = sprintf('%s is not writable — KeyManager cannot rotate keys. Fix: chmod 700 %s', $oauthDir, $oauthDir);
                $oauthReady = false;
            }
        }

        // Public web-dir symlinks. Contao serves /files/ and /assets/ through
        // symlinks (public/files → ../files, public/assets → ../var/…) that the
        // `contao:symlinks` command creates. If they're missing, every asset
        // URL 404s and the frontend silently loads no CSS/JS/images. (Seen on a
        // fresh Windows/Laragon checkout where only public/assets was linked.)
        $publicDir = $this->resolvePublicDir();
        $symlinkChecks = [];
        foreach (['files', 'assets'] as $link) {
            $path = $publicDir.\DIRECTORY_SEPARATOR.$link;
            $resolves = is_dir($path); // follows symlinks → false for a missing or broken link
            $symlinkChecks[$link] = [
                'path' => $path,
                'exists' => file_exists($path) || is_link($path),
                'is_symlink' => is_link($path),
                'target' => is_link($path) ? (readlink($path) ?: null) : null,
                'resolves' => $resolves,
            ];
            if (!$resolves) {
                $warnings[] = sprintf(
                    'public/%1$s is missing or broken — every /%1$s/… URL will 404 (frontend CSS/JS/images fail to load). Fix: run `vendor/bin/contao-console contao:symlinks`.',
                    $link,
                );
            }
        }

        return [
            'overall_health' => $warnings === [] ? 'ok' : 'warnings',
            'php' => [
                'version' => \PHP_VERSION,
                'sapi' => \PHP_SAPI,
                'os_family' => \PHP_OS_FAMILY,
                'extensions' => [
                    'openssl' => \extension_loaded('openssl'),
                    'mbstring' => \extension_loaded('mbstring'),
                    'mysqli' => \extension_loaded('mysqli'),
                    'pdo_mysql' => \extension_loaded('pdo_mysql'),
                ],
            ],
            'storage' => [
                'mcp_dir' => $mcpDir,
                'mcp_dir_writable' => $mcpWritable,
                'oauth_dir' => $oauthDir,
                'oauth_dir_exists' => is_dir($oauthDir),
                'oauth_dir_writable' => is_dir($oauthDir) ? is_writable($oauthDir) : null,
                'private_key_perms' => is_file($oauthDir.\DIRECTORY_SEPARATOR.'private.pem') ? substr(sprintf('%o', fileperms($oauthDir.\DIRECTORY_SEPARATOR.'private.pem')), -4) : null,
            ],
            'filesystem' => [
                'public_dir' => $publicDir,
                'symlinks' => $symlinkChecks,
            ],
            'oauth' => [
                'auth_mode' => $config['auth_mode'] ?? 'none',
                'backend_url' => $config['backend_url'] ?? '',
                'registration_mode' => $config['oauth_registration_mode'] ?? 'restricted',
                'ready' => $oauthReady,
            ],
            'config' => [
                'path' => $config['path'] ?? '',
                'lazy_mode' => (bool) ($config['lazy_mode'] ?? false),
                'pagination_limit' => $config['pagination_limit'] ?? 0,
            ],
            'warnings' => $warnings,
        ];
    }

    private function currentUserName(): string
    {
        if (\function_exists('posix_geteuid') && \function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (\is_array($info)) {
                return (string) $info['name'];
            }
        }
        return 'www-data';
    }

    /**
     * Contao Managed Edition serves the site from public/ (modern) or web/
     * (legacy). Return whichever exists; default to public/.
     */
    private function resolvePublicDir(): string
    {
        foreach (['public', 'web'] as $dir) {
            $candidate = $this->projectDir.\DIRECTORY_SEPARATOR.$dir;
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return $this->projectDir.\DIRECTORY_SEPARATOR.'public';
    }

    /**
     * @return array<string, mixed>
     */
    private static function normaliseSettings(mixed $settings): array
    {
        if ($settings === null) {
            return [];
        }
        if (\is_object($settings)) {
            return (array) $settings;
        }
        if (\is_array($settings)) {
            if ($settings !== [] && array_is_list($settings)) {
                throw new \InvalidArgumentException('`settings` must be a JSON object, not a list.');
            }
            return $settings;
        }

        throw new \InvalidArgumentException('`settings` must be a JSON object.');
    }
}
