<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Routing;

use Netzhirsch\ContaoMcpBundle\Routing\EndpointPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EndpointPath::class)]
final class EndpointPathTest extends TestCase
{
    public function testTheStoredFormHasNoSlashesAtEitherEnd(): void
    {
        self::assertSame('ki/mcp', EndpointPath::normalise('  /ki/mcp/ '));
        self::assertSame('', EndpointPath::normalise(' / '));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function paths(): iterable
    {
        yield 'the default' => ['mcp', null];
        yield 'several segments' => ['ki/mcp', null];
        yield 'digits, dash and underscore' => ['api-v2/mcp_1', null];
        yield 'empty' => ['', 'path_invalid'];
        yield 'upper case' => ['MCP', 'path_invalid'];
        yield 'a dot, which web servers take for a file' => ['mcp.json', 'path_invalid'];
        yield 'an empty segment' => ['ki//mcp', 'path_invalid'];
        yield 'a space' => ['ki mcp', 'path_invalid'];
        yield 'a leading underscore, Symfony and Contao territory' => ['_mcp', 'path_invalid'];
        yield 'a segment starting with a dash' => ['ki/-mcp', 'path_invalid'];
        yield 'too long' => [str_repeat('a', 101), 'path_invalid'];
        yield 'the backend' => ['contao', 'path_reserved'];
        yield 'below the backend' => ['contao/mcp', 'path_reserved'];
        yield 'a public/ directory' => ['files/mcp', 'path_reserved'];
        yield 'only shares a prefix with the backend' => ['contaomcp', null];
    }

    #[DataProvider('paths')]
    public function testWhatAPathMayBe(string $path, ?string $problem): void
    {
        self::assertSame($problem, EndpointPath::problem($path));
    }

    public function testTheBackendPrefixIsTheConfiguredOne(): void
    {
        self::assertSame('path_reserved', EndpointPath::problem('admin/mcp', '/admin'));
        self::assertNull(EndpointPath::problem('contao/mcp', '/admin'));
    }

    public function testTheFourRoutesFollowThePath(): void
    {
        self::assertSame(EndpointPath::ROUTE_ENDPOINT, EndpointPath::route('/ki/mcp', 'ki/mcp')['name'] ?? null);
        self::assertSame(EndpointPath::ROUTE_HEALTHZ, EndpointPath::route('/ki/mcp/healthz', 'ki/mcp')['name'] ?? null);
        self::assertSame(EndpointPath::ROUTE_AS_METADATA, EndpointPath::route('/ki/mcp/.well-known/oauth-authorization-server', 'ki/mcp')['name'] ?? null);
        self::assertSame(EndpointPath::ROUTE_PRM, EndpointPath::route('/.well-known/oauth-protected-resource/ki/mcp', 'ki/mcp')['name'] ?? null);
    }

    public function testNothingElseIsClaimed(): void
    {
        foreach (['/mcp', '/mcp/healthz', '/ki/mcp/', '/ki/mcpx', '/x/ki/mcp', '/.well-known/oauth-protected-resource', '/'] as $pathInfo) {
            self::assertNull(EndpointPath::route($pathInfo, 'ki/mcp'), $pathInfo);
        }
    }
}
