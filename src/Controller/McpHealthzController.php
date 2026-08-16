<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Controller;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\OAuth\KeyManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Lightweight liveness probe for external monitoring (Plesk watchdog,
 * UptimeRobot, Prometheus blackbox-exporter, Kubernetes liveness probes,
 * Pingdom, …). Deliberately separate from the OAuth-gated /mcp endpoint
 * so monitoring tooling doesn't need a Bearer token (and doesn't need
 * to know about MCP semantics at all — it just checks for HTTP 200).
 *
 * Goals:
 *   - Returns under 100 ms on a healthy host. No php-mcp Dispatcher
 *     instantiation, no Contao model discovery, no tool-registry walk
 *     — the Symfony kernel boots, this controller runs, the response
 *     goes out. That's it.
 *   - 200 when everything checks pass; 503 with detailed JSON when at
 *     least one check fails. Standard liveness-probe convention.
 *   - Never throws on a bad check — every probe path is wrapped in
 *     try/catch so a transient sub-system failure surfaces as JSON,
 *     not as a 500 with a stack trace.
 *
 * What we check:
 *   1. database — DBAL `SELECT 1` round-trip. Catches "MySQL is down",
 *      "wrong creds", "schema migration left a half-locked state".
 *   2. var_mcp_dir — the var/mcp/ directory is writable. Catches the
 *      common "filesystem read-only after disk pressure" failure mode.
 *   3. oauth_keys — IF auth_mode=oauth, both private.pem and public.pem
 *      exist and are readable. Skipped (status "n/a") otherwise — a
 *      bundle in auth_mode=none doesn't need keys at all.
 *   4. disk_free — at least 50 MB free on the var/ partition. PHP-FPM
 *      writes session + cache files; tip-over to a full disk silently
 *      corrupts both.
 *
 * What we deliberately DON'T check:
 *   - Tool registry health (would require booting php-mcp Dispatcher
 *     + scanning src/Tool/ — too heavy for a per-minute probe)
 *   - OAuth-token store size or staleness (cleanup is its own job)
 *   - External-network reachability (probe target is /this host/, not
 *     "the internet")
 *
 * Route: GET /mcp/healthz — no auth, no CSRF, no rate-limit gating
 * the OAuth endpoints have. The path is OUTSIDE both /mcp (POST-only
 * JSON-RPC) and /_mcp_oauth/* (rate-limited authorization endpoints),
 * so a monitoring system that probes once per second doesn't trip the
 * OAuth throttles.
 */
final class McpHealthzController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly McpServerConfigStorage $configStorage,
        private readonly KeyManager $keyManager,
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/mcp/healthz',
        name: 'netzhirsch_contao_mcp_healthz',
        methods: ['GET'],
        defaults: ['_scope' => 'frontend'],
    )]
    public function __invoke(): JsonResponse
    {
        $checks = [
            $this->checkDatabase(),
            $this->checkVarMcpDir(),
            $this->checkOAuthKeys(),
            $this->checkDiskFree(),
        ];

        $anyFailed = false;
        foreach ($checks as $c) {
            if (($c['ok'] ?? null) === false) {
                $anyFailed = true;
                break;
            }
        }

        $body = [
            'status' => $anyFailed ? 'degraded' : 'ok',
            'checks' => $checks,
            'bundle_version' => InstalledVersions::isInstalled('netzhirsch/contao-mcp-bundle')
                ? (string) InstalledVersions::getPrettyVersion('netzhirsch/contao-mcp-bundle')
                : 'dev',
            'checked_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];

        // 503 is the right "I see your request, but I can't serve real
        // traffic right now" code for liveness probes. 500 would imply
        // an unexpected crash; we know exactly what's wrong and we're
        // reporting it as data.
        return new JsonResponse(
            $body,
            $anyFailed ? 503 : 200,
            [
                // Cache-Control: no-store — every probe must see fresh
                // results. A 5-second CDN cache here would be a foot-gun
                // (probe says "ok" because last good check is still
                // cached, real state degraded).
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ],
        );
    }

    /**
     * @return array{name: string, ok: bool, latency_ms?: float, error?: string}
     */
    private function checkDatabase(): array
    {
        $start = microtime(true);
        try {
            // executeQuery + fetchOne over Connection is the lightest
            // round-trip DBAL exposes. No prepared-statement cache
            // churn (driver-specific).
            $result = $this->connection->fetchOne('SELECT 1');
            $ok = $result == 1 || $result === '1';
        } catch (\Throwable $e) {
            // This endpoint is public. A DBAL connection error carries host,
            // port, database and user ("Access denied for user 'x'@'host'") —
            // that belongs in the log, not in an unauthenticated response.
            $this->logger->error('MCP healthz: database check failed.', ['exception' => $e]);

            return [
                'name' => 'database',
                'ok' => false,
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                'error' => 'not reachable (see the application log for details)',
            ];
        }

        return [
            'name' => 'database',
            'ok' => $ok,
            'latency_ms' => round((microtime(true) - $start) * 1000, 2),
        ];
    }

    /**
     * @return array{name: string, ok: bool, path: string, error?: string}
     */
    private function checkVarMcpDir(): array
    {
        $path = $this->projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'mcp';
        // This endpoint is public (no auth) — report the project-relative path,
        // never the absolute one, so the probe doesn't hand out the server's
        // filesystem layout.
        $reported = 'var/mcp';
        if (!is_dir($path)) {
            return [
                'name' => 'var_mcp_dir',
                'ok' => false,
                'path' => $reported,
                'error' => 'directory does not exist (will be created on first MCP request)',
            ];
        }
        if (!is_writable($path)) {
            return [
                'name' => 'var_mcp_dir',
                'ok' => false,
                'path' => $reported,
                'error' => 'directory is not writable by the web-server user',
            ];
        }

        return ['name' => 'var_mcp_dir', 'ok' => true, 'path' => $reported];
    }

    /**
     * @return array{name: string, ok: bool, status?: string, private_key_age_days?: float, error?: string}
     */
    private function checkOAuthKeys(): array
    {
        try {
            $config = $this->configStorage->load();
        } catch (\Throwable $e) {
            return ['name' => 'oauth_keys', 'ok' => false, 'error' => 'config load failed: '.$e->getMessage()];
        }

        $authMode = (string) ($config['auth_mode'] ?? 'none');
        if ($authMode !== 'oauth') {
            // Not failing the probe — a bundle running in auth_mode=none
            // legitimately doesn't have keys.
            return ['name' => 'oauth_keys', 'ok' => true, 'status' => 'n/a (auth_mode='.$authMode.')'];
        }

        $privatePath = $this->keyManager->privateKeyPath();
        $publicPath = $this->keyManager->publicKeyPath();

        if (!is_file($privatePath) || !is_readable($privatePath)) {
            return [
                'name' => 'oauth_keys',
                'ok' => false,
                'error' => 'private key missing or unreadable: '.$privatePath,
            ];
        }
        if (!is_file($publicPath) || !is_readable($publicPath)) {
            return [
                'name' => 'oauth_keys',
                'ok' => false,
                'error' => 'public key missing or unreadable: '.$publicPath,
            ];
        }

        $ageSeconds = $this->keyManager->currentKeyAgeSeconds();
        $ageDays = $ageSeconds === null ? null : round($ageSeconds / 86400, 1);

        return [
            'name' => 'oauth_keys',
            'ok' => true,
            'private_key_age_days' => $ageDays ?? 0.0,
        ];
    }

    /**
     * @return array{name: string, ok: bool, free_mb?: int, threshold_mb: int, error?: string}
     */
    private function checkDiskFree(): array
    {
        $threshold = 50; // MB
        $path = $this->projectDir.\DIRECTORY_SEPARATOR.'var';
        // Fall back to projectDir if var/ doesn't exist yet (fresh install
        // pre-first-Symfony-cache-warm — unlikely under a probe but
        // possible during Plesk-Restore).
        if (!is_dir($path)) {
            $path = $this->projectDir;
        }

        $free = @disk_free_space($path);
        if ($free === false) {
            return [
                'name' => 'disk_free',
                'ok' => false,
                'threshold_mb' => $threshold,
                'error' => 'disk_free_space() returned false for '.$path,
            ];
        }

        $freeMb = (int) ($free / (1024 * 1024));
        return [
            'name' => 'disk_free',
            'ok' => $freeMb >= $threshold,
            'free_mb' => $freeMb,
            'threshold_mb' => $threshold,
        ];
    }
}
