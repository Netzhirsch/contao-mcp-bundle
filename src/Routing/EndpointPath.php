<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Routing;

use Netzhirsch\ContaoMcpBundle\Controller\McpController;
use Netzhirsch\ContaoMcpBundle\Controller\McpHealthzController;

/**
 * The URL path of the MCP endpoint — `path` in var/mcp/config.json, default
 * "mcp" — and the four routes that follow it:
 *
 *   POST|OPTIONS /<path>                                        JSON-RPC endpoint
 *   GET          /<path>/healthz                                liveness probe
 *   GET          /<path>/.well-known/oauth-authorization-server RFC 8414, MCP location
 *   GET          /.well-known/oauth-protected-resource/<path>   RFC 9728 §3.1
 *
 * The bare /.well-known documents and /_mcp_oauth/* do not depend on it and
 * stay ordinary routes.
 */
final class EndpointPath
{
    public const DEFAULT = 'mcp';

    public const ROUTE_ENDPOINT = 'netzhirsch_contao_mcp_controller';
    public const ROUTE_HEALTHZ = 'netzhirsch_contao_mcp_healthz';
    public const ROUTE_AS_METADATA = 'netzhirsch_contao_mcp_oauth_metadata_mcp_path';
    public const ROUTE_PRM = 'netzhirsch_contao_mcp_oauth_prm_mcp_path';

    /**
     * Segments of lower-case letters, digits, "-" and "_", each starting with a
     * letter or digit: nothing a URL has to encode, and nothing a web server
     * takes for a file name (no dot). The leading "_" it excludes is where
     * Symfony and Contao keep their own routes (/_contao, /_mcp_oauth, /_wdt).
     */
    private const PATTERN = '#^[a-z0-9][a-z0-9_-]*(?:/[a-z0-9][a-z0-9_-]*)*$#';

    private const MAX_LENGTH = 100;

    /**
     * Directories in public/: the web server answers them before PHP sees the
     * request, so an endpoint there would never be reached.
     */
    private const RESERVED = ['assets', 'bundles', 'files', 'share', 'system', 'vendor'];

    /**
     * The stored form: no whitespace, no slash at either end.
     */
    public static function normalise(string $value): string
    {
        return trim(trim($value), '/');
    }

    /**
     * Why a path cannot be the endpoint, as an error code; null if it can.
     *
     * @param string $backendRoutePrefix contao.backend.route_prefix, "/contao" by default
     */
    public static function problem(string $path, string $backendRoutePrefix = '/contao'): ?string
    {
        if (\strlen($path) > self::MAX_LENGTH || preg_match(self::PATTERN, $path) !== 1) {
            return 'path_invalid';
        }

        $backend = trim($backendRoutePrefix, '/');
        $inBackend = $backend !== '' && ($path === $backend || str_starts_with($path, $backend.'/'));

        if ($inBackend || \in_array(explode('/', $path, 2)[0], self::RESERVED, true)) {
            return 'path_reserved';
        }

        return null;
    }

    /**
     * The route a request path takes under the configured endpoint path, or
     * null when it is none of the four.
     *
     * @return array{name: string, controller: string, methods: list<string>}|null
     */
    public static function route(string $pathInfo, string $path): ?array
    {
        $base = '/'.$path;

        return match ($pathInfo) {
            $base => [
                'name' => self::ROUTE_ENDPOINT,
                'controller' => McpController::class.'::handle',
                'methods' => ['POST', 'OPTIONS'],
            ],
            $base.'/healthz' => [
                'name' => self::ROUTE_HEALTHZ,
                'controller' => McpHealthzController::class,
                'methods' => ['GET', 'HEAD'],
            ],
            $base.'/.well-known/oauth-authorization-server' => [
                'name' => self::ROUTE_AS_METADATA,
                'controller' => McpController::class.'::oauthMetadata',
                'methods' => ['GET', 'HEAD'],
            ],
            '/.well-known/oauth-protected-resource'.$base => [
                'name' => self::ROUTE_PRM,
                'controller' => McpController::class.'::oauthProtectedResourceMetadata',
                'methods' => ['GET', 'HEAD'],
            ],
            default => null,
        };
    }
}
