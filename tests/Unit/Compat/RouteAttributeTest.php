<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Compat;

use Netzhirsch\ContaoMcpBundle\Controller\McpController;
use Netzhirsch\ContaoMcpBundle\Controller\McpHealthzController;
use Netzhirsch\ContaoMcpBundle\Routing\EndpointPath;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Loader\AttributeClassLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Symfony 8 removed Symfony\Component\Routing\Annotation\Route. A route
 * declared with it does not fail: the attribute loader simply no longer
 * recognises it, and the route is never registered. That is how /mcp,
 * /mcp/healthz and the .well-known metadata all answered 404 on Contao 6 —
 * the MCP server unreachable — while the smoke test, which calls the
 * controllers in-process, stayed green. Since the endpoint follows the
 * configured path, only the bare .well-known documents are attribute routes;
 * the rest is {@see EndpointPath::route()}.
 *
 * Routing\Attribute\Route exists from Symfony 6.4 on, so it serves every
 * version this bundle supports. On 7.x the old class is still an alias of the
 * new one, which is why nothing here would fail on the versions PHPUnit runs
 * against — hence the source check rather than relying on the loader alone.
 */
final class RouteAttributeTest extends TestCase
{
    private const SRC = __DIR__.'/../../../src';

    public function testNoRouteUsesTheNamespaceSymfony8Removed(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.php')
                && str_contains((string) file_get_contents($file->getPathname()), 'Symfony\\Component\\Routing\\Annotation\\')) {
                $offenders[] = substr($file->getPathname(), \strlen(self::SRC) + 1);
            }
        }

        self::assertSame([], $offenders, 'Symfony 8 ignores routes declared with Routing\Annotation\Route; use Routing\Attribute\Route.');
    }

    public function testTheBareWellKnownDocumentsAreDeclared(): void
    {
        $routes = $this->attributeRoutes();

        $expected = [
            'netzhirsch_contao_mcp_oauth_metadata_root' => ['/.well-known/oauth-authorization-server', ['GET']],
            'netzhirsch_contao_mcp_oauth_prm_root' => ['/.well-known/oauth-protected-resource', ['GET']],
        ];

        foreach ($expected as $name => [$path, $methods]) {
            $route = $routes->get($name);

            self::assertNotNull($route, "Route $name is not declared.");
            self::assertSame($path, $route->getPath(), $name);
            self::assertSame($methods, $route->getMethods(), $name);
        }
    }

    /**
     * The endpoint follows `path` from var/mcp/config.json, so its routes are
     * matched at request time. A copy left behind as an attribute would keep
     * answering at /mcp after the path was changed.
     */
    public function testThePathDependentRoutesAreNoAttributeRoutes(): void
    {
        $routes = $this->attributeRoutes();

        foreach ([EndpointPath::ROUTE_ENDPOINT, EndpointPath::ROUTE_HEALTHZ, EndpointPath::ROUTE_AS_METADATA, EndpointPath::ROUTE_PRM] as $name) {
            self::assertNull($routes->get($name), "$name is still an attribute route.");
        }
    }

    public function testThePathDependentRoutesReachRealControllers(): void
    {
        $expected = [
            '/mcp' => [EndpointPath::ROUTE_ENDPOINT, ['POST', 'OPTIONS']],
            '/mcp/healthz' => [EndpointPath::ROUTE_HEALTHZ, ['GET', 'HEAD']],
            '/mcp/.well-known/oauth-authorization-server' => [EndpointPath::ROUTE_AS_METADATA, ['GET', 'HEAD']],
            '/.well-known/oauth-protected-resource/mcp' => [EndpointPath::ROUTE_PRM, ['GET', 'HEAD']],
        ];

        foreach ($expected as $pathInfo => [$name, $methods]) {
            $route = EndpointPath::route($pathInfo, EndpointPath::DEFAULT);

            self::assertNotNull($route, $pathInfo);
            self::assertSame($name, $route['name'], $pathInfo);
            self::assertSame($methods, $route['methods'], $pathInfo);

            // The router never checks these: a typo would only show as a 500
            // on the first request.
            [$class, $method] = explode('::', $route['controller']) + [1 => '__invoke'];
            self::assertTrue(method_exists($class, $method), $route['controller']);
            self::assertContains($class, [McpController::class, McpHealthzController::class]);
        }
    }

    private function attributeRoutes(): RouteCollection
    {
        $loader = new class() extends AttributeClassLoader {
            protected function configureRoute(Route $route, \ReflectionClass $class, \ReflectionMethod $method, object $attr): void
            {
            }
        };

        $routes = new RouteCollection();
        $routes->addCollection($loader->load(McpController::class));
        $routes->addCollection($loader->load(McpHealthzController::class));

        return $routes;
    }
}
