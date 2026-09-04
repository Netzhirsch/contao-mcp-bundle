<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Backend;

use Netzhirsch\ContaoMcpBundle\Service\AtomicFile;

/**
 * Reads / writes the editable MCP-server configuration as JSON under
 * `var/mcp/config.json`. We deliberately avoid a DB table here because the
 * config is small, easy to back up via Git, and reachable even when the
 * Contao framework is in a half-bootstrapped state (e.g. early CLI calls).
 *
 * The legacy daemon-mode fields (`mode`, `host`, `port`) are silently
 * dropped from any pre-v0.3.0 config.json on load — see {@see load()}.
 */
final class McpServerConfigStorage
{
    /**
     * @return array{
     *     path: string,
     *     pagination_limit: int,
     *     auth_mode: string,
     *     backend_url: string,
     *     oauth_registration_mode: string,
     *     lazy_mode: bool,
     *     extension_tools_enabled: list<string>,
     *     disabled_tools: list<string>,
     *     registration_open_until: int,
     *     license_server_url: string,
     *     cimd_mode: string,
     *     cimd_trusted_hosts: list<string>
     * }
     */
    public function defaults(): array
    {
        return [
            'path' => 'mcp',
            'pagination_limit' => 500,
            // 'none' = no auth (only safe on a private/loopback host).
            // 'oauth' = OAuth 2.1 with PKCE, tokens issued by the Backend's
            //           /_mcp_oauth/* endpoints, verified as JWTs by the
            //           controller before dispatching tool calls.
            'auth_mode' => 'none',
            // Public base URL of the Contao Backend. Used when the server
            // advertises OAuth endpoints in
            // /.well-known/oauth-authorization-server. Required when
            // auth_mode=oauth.
            'backend_url' => '',
            // 'open' = anyone can POST /_mcp_oauth/register and get a
            // client_id back. Convenient for local dev. 'restricted' = the
            // request must carry `Authorization: Bearer iat_…` with a
            // valid Initial Access Token generated in the Backend.
            'oauth_registration_mode' => 'restricted',
            // Client ID Metadata Documents (CIMD). A URL-shaped client_id
            // is dereferenced and the client's metadata read from it, so no
            // registration step is needed at all.
            //   'trusted' = only client_id hosts on cimd_trusted_hosts (default)
            //   'open'    = any HTTPS client_id, the spec's open-server posture
            //   'off'     = disabled; the capability is not advertised and
            //               clients fall back to Dynamic Client Registration
            // 'trusted' is the default because accepting any HTTPS URL means
            // this server will fetch any HTTPS URL a caller names, and a
            // customer's production CMS is a poor place to make that promise
            // for no gain: the clients Contao is connected to live here.
            'cimd_mode' => 'trusted',
            // Host suffixes trusted in 'trusted' mode. Matched on label
            // boundaries, so 'claude.ai' covers 'claude.ai' and any
            // subdomain of it, but never 'notclaude.ai'.
            'cimd_trusted_hosts' => ['claude.ai', 'claude.com'],
            // Lazy-Mode: when true, the server exposes only the 3 discovery
            // tools (contao_search_tools, contao_describe_tool, contao_call)
            // plus a tiny health-check set in `tools/list`. The other ~150
            // tools remain reachable via contao_call. Recommended for Claude
            // because Claude doesn't paginate tools/list and loads every
            // tool's schema into context.
            'lazy_mode' => false,
            // Allowlist for third-party MCP tools contributed by other
            // bundles (services tagged `netzhirsch_mcp.tool`). Empty by
            // default: an extension tool is NEVER callable until its
            // `#[McpTool]` name appears in this list. This is the core
            // security gate for the extension point — see EXTENDING.md.
            'extension_tools_enabled' => [],
            // Opt-out list of CORE tool names hidden from the MCP catalogue
            // entirely (tools/list, discovery AND tools/call). Managed via the
            // Backend tool panel; ToolCatalog::PROTECTED_TOOLS can never land
            // here effectively. Counterpart of the extension allowlist above.
            'disabled_tools' => [],
            // Pairing window: unix timestamp until which dynamic client
            // registration is allowed WITHOUT an Initial Access Token even in
            // restricted mode. 0 = closed. Opened via the Backend button for
            // 10 minutes; auto-closes after the first successful registration.
            'registration_open_until' => 0,
            // Base URL of the vendor license server (trial/renew endpoints).
            // Empty = offline only (activate a signed token by hand). The
            // enforcement switch + public key live in CODE, not here, so this
            // URL is safe to expose/change: a rogue server cannot mint valid,
            // vendor-signed tokens. See License\RenewalClient + LicenseGate.
            'license_server_url' => '',
        ];
    }

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{
     *     path: string,
     *     pagination_limit: int,
     *     auth_mode: string,
     *     backend_url: string,
     *     oauth_registration_mode: string,
     *     lazy_mode: bool,
     *     extension_tools_enabled: list<string>,
     *     disabled_tools: list<string>,
     *     registration_open_until: int,
     *     license_server_url: string,
     *     cimd_mode: string,
     *     cimd_trusted_hosts: list<string>,
     *     config_error?: string
     * }
     */
    public function load(): array
    {
        $defaults = $this->defaults();
        $path = $this->filePath();

        if (!is_file($path)) {
            return $defaults;
        }

        // A config file that EXISTS but cannot be read or parsed must not fall
        // back to the defaults, because one of those defaults is
        // `auth_mode = none`. Restoring a backup without var/mcp, losing the
        // file's permissions, a full disk during save() — any of those turned
        // an OAuth-protected server into an open one with ~186 tools, and
        // nothing in the answer said so. The failure has to point the other
        // way: keep serving nothing rather than serving everything.
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return $defaults + ['config_error' => sprintf('%s exists but could not be read.', $path)];
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            return $defaults + ['config_error' => sprintf('%s is not valid JSON (%s).', $path, json_last_error_msg())];
        }

        // Same reasoning one level down: an auth_mode that is present but not
        // one we know is a corrupted or hand-edited value, and clamping it to
        // the default would silently disable authentication.
        $declaredAuthMode = $decoded['auth_mode'] ?? null;
        if ($declaredAuthMode !== null && !\in_array($declaredAuthMode, ['none', 'oauth'], true)) {
            return $defaults + ['config_error' => sprintf(
                '%s has auth_mode=%s, which is neither "none" nor "oauth".',
                $path,
                var_export($declaredAuthMode, true),
            )];
        }

        // Pre-v0.3.0 config files carried `mode`, `host`, `port` fields for
        // the daemon transport. We read only the fields we still recognise —
        // legacy fields are dropped silently. The first save() rewrites the
        // file without them.
        return [
            'path' => self::normalisePath($decoded['path'] ?? null, $defaults['path']),
            'pagination_limit' => self::clampInt($decoded['pagination_limit'] ?? null, 1, 10000, $defaults['pagination_limit']),
            'auth_mode' => self::clampEnum($decoded['auth_mode'] ?? null, ['none', 'oauth'], $defaults['auth_mode']),
            'backend_url' => self::trimString($decoded['backend_url'] ?? null, ''),
            'oauth_registration_mode' => self::clampEnum($decoded['oauth_registration_mode'] ?? null, ['open', 'restricted'], $defaults['oauth_registration_mode']),
            'lazy_mode' => self::toBool($decoded['lazy_mode'] ?? null, $defaults['lazy_mode']),
            'extension_tools_enabled' => self::stringList($decoded['extension_tools_enabled'] ?? null),
            'disabled_tools' => self::stringList($decoded['disabled_tools'] ?? null),
            'registration_open_until' => self::clampInt($decoded['registration_open_until'] ?? null, 0, PHP_INT_MAX, 0),
            'license_server_url' => rtrim(self::trimString($decoded['license_server_url'] ?? null, ''), '/'),
            'cimd_mode' => self::clampEnum($decoded['cimd_mode'] ?? null, ['off', 'trusted', 'open'], $defaults['cimd_mode']),
            'cimd_trusted_hosts' => self::hostList($decoded['cimd_trusted_hosts'] ?? null, $defaults['cimd_trusted_hosts']),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     saved: bool,
     *     errors: list<string>,
     *     values: array{path: string, pagination_limit: int, auth_mode: string, backend_url: string, oauth_registration_mode: string, lazy_mode: bool, extension_tools_enabled: list<string>, disabled_tools: list<string>, registration_open_until: int, license_server_url: string}
     * }
     */
    public function save(array $input): array
    {
        $defaults = $this->defaults();
        $errors = [];

        $path = self::normalisePath($input['path'] ?? null, $defaults['path']);

        $paginationLimit = (int) ($input['pagination_limit'] ?? 0);
        if ($paginationLimit < 1 || $paginationLimit > 10000) {
            $errors[] = 'pagination_limit_invalid';
            $paginationLimit = $defaults['pagination_limit'];
        }

        $authMode = self::clampEnum($input['auth_mode'] ?? null, ['none', 'oauth'], $defaults['auth_mode']);
        $backendUrl = rtrim(self::trimString($input['backend_url'] ?? null, ''), '/');
        $oauthRegistrationMode = self::clampEnum($input['oauth_registration_mode'] ?? null, ['open', 'restricted'], $defaults['oauth_registration_mode']);
        $lazyMode = self::toBool($input['lazy_mode'] ?? null, $defaults['lazy_mode']);
        // The Backend config form does not (yet) submit this field, so a
        // naive save would wipe it. Callers that don't manage the allowlist
        // must pass through the current value (see ModuleMcpConfig::
        // handleSaveConfig). Absent input → empty list.
        $extensionToolsEnabled = self::stringList($input['extension_tools_enabled'] ?? null);
        // Same pass-through contract as the allowlist: callers that do not
        // manage the tool panel must hand the current value through.
        $disabledTools = self::stringList($input['disabled_tools'] ?? null);
        $registrationOpenUntil = self::clampInt($input['registration_open_until'] ?? null, 0, PHP_INT_MAX, 0);
        // Optional dev/test override for the baked-in license server. Validate
        // the shape so it can't become an arbitrary request target (file://,
        // gopher://, garbage) — the bundle POSTs to it from the backend/cron.
        $licenseServerUrl = rtrim(self::trimString($input['license_server_url'] ?? null, ''), '/');
        if ('' !== $licenseServerUrl) {
            $scheme = strtolower((string) parse_url($licenseServerUrl, PHP_URL_SCHEME));
            $urlHost = (string) parse_url($licenseServerUrl, PHP_URL_HOST);
            if (!\in_array($scheme, ['http', 'https'], true) || '' === $urlHost) {
                $errors[] = 'license_server_url_invalid';
                $licenseServerUrl = ''; // fall back to the baked-in default
            }
        }

        $cimdMode = self::clampEnum($input['cimd_mode'] ?? null, ['off', 'trusted', 'open'], $defaults['cimd_mode']);
        // Same pass-through contract as the tool lists: a caller that does
        // not manage the allowlist must hand the current value through, or
        // saving an unrelated setting would silently widen the trust policy
        // to nothing at all.
        $cimdTrustedHosts = self::hostList($input['cimd_trusted_hosts'] ?? null, $defaults['cimd_trusted_hosts']);

        $values = [
            'path' => $path,
            'pagination_limit' => $paginationLimit,
            'auth_mode' => $authMode,
            'backend_url' => $backendUrl,
            'oauth_registration_mode' => $oauthRegistrationMode,
            'lazy_mode' => $lazyMode,
            'extension_tools_enabled' => $extensionToolsEnabled,
            'disabled_tools' => $disabledTools,
            'registration_open_until' => $registrationOpenUntil,
            'license_server_url' => $licenseServerUrl,
            'cimd_mode' => $cimdMode,
            'cimd_trusted_hosts' => $cimdTrustedHosts,
        ];

        if ($errors !== []) {
            return ['saved' => false, 'errors' => $errors, 'values' => $values];
        }

        $dir = \dirname($this->filePath());
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return ['saved' => false, 'errors' => ['dir_unwritable'], 'values' => $values];
        }

        $written = AtomicFile::write(
            $this->filePath(),
            json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        );

        return [
            'saved' => $written,
            'errors' => $written ? [] : ['file_unwritable'],
            'values' => $values,
        ];
    }

    private function filePath(): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'mcp'.\DIRECTORY_SEPARATOR.'config.json';
    }

    /**
     * Coerce arbitrary JSON input into a clean list of non-empty,
     * de-duplicated tool-name strings. Anything non-array → empty list.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (\is_string($entry)) {
                $trimmed = trim($entry);
                if ($trimmed !== '') {
                    $out[$trimmed] = true;
                }
            }
        }

        return array_keys($out);
    }

    /**
     * Hostnames for the CIMD trust policy.
     *
     * Absent input falls back to the defaults — the same pass-through contract
     * the tool lists have, and here it matters more: a form that forgets to
     * submit the field would otherwise empty the allowlist, which in 'trusted'
     * mode silently turns CIMD off for everyone.
     *
     * An explicitly empty array is honoured, because "trust nobody" is a
     * legitimate thing for an operator to mean.
     *
     * Entries are validated as hostnames. A stray scheme, port or path in here
     * would never match the host comparison and would look like a working
     * allowlist entry that quietly does nothing.
     *
     * @param list<string> $fallback
     *
     * @return list<string>
     */
    private static function hostList(mixed $value, array $fallback): array
    {
        if (!\is_array($value)) {
            return $fallback;
        }

        $out = [];

        foreach ($value as $entry) {
            if (!\is_string($entry)) {
                continue;
            }

            $host = strtolower(trim($entry));

            if ($host === '') {
                continue;
            }

            if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $host) !== 1) {
                continue;
            }

            $out[$host] = true;
        }

        return array_keys($out);
    }

    private static function trimString(mixed $value, string $fallback): string
    {
        if (!\is_string($value) && !\is_numeric($value)) {
            return $fallback;
        }
        $s = trim((string) $value);

        return $s === '' ? $fallback : $s;
    }

    private static function clampInt(mixed $value, int $min, int $max, int $fallback): int
    {
        if (!\is_numeric($value)) {
            return $fallback;
        }
        $i = (int) $value;
        if ($i < $min || $i > $max) {
            return $fallback;
        }

        return $i;
    }

    /**
     * @param list<string> $allowed
     */
    private static function clampEnum(mixed $value, array $allowed, string $fallback): string
    {
        if (!\is_string($value) || !\in_array($value, $allowed, true)) {
            return $fallback;
        }

        return $value;
    }

    private static function toBool(mixed $value, bool $fallback): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value)) {
            return $value === 1;
        }
        if (\is_string($value)) {
            $normalised = strtolower(trim($value));
            if (\in_array($normalised, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (\in_array($normalised, ['0', 'false', 'off', 'no', ''], true)) {
                return false;
            }
        }

        return $fallback;
    }

    private static function normalisePath(mixed $value, string $fallback): string
    {
        if (!\is_string($value)) {
            return $fallback;
        }
        $s = trim($value);
        if ($s === '') {
            return $fallback;
        }
        // Stored without leading slash; the controller route adds it.
        return ltrim($s, '/');
    }
}
