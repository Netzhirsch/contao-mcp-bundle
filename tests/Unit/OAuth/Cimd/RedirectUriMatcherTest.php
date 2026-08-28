<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\OAuth\Cimd;

use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\RedirectUriMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Redirect URI matching is exact, with exactly one documented exception:
 * RFC 8252 §7.3 ignores the PORT for a native client's loopback redirect,
 * because the client binds an ephemeral port it cannot declare in advance.
 *
 * The tests below are mostly about what the exception must NOT do. Every
 * relaxation beyond the port is somebody's open-redirect advisory, and
 * `http://localhost.attacker.example/callback` is the specific shape this
 * bundle already had to fix once in its dynamic-registration path.
 */
#[CoversClass(RedirectUriMatcher::class)]
final class RedirectUriMatcherTest extends TestCase
{
    /** The real declaration from Claude Code's metadata document. */
    private const CLAUDE_CODE = ['http://localhost/callback', 'http://127.0.0.1/callback'];

    /**
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'exact https match' => [
            ['https://claude.ai/api/mcp/auth_callback'],
            'https://claude.ai/api/mcp/auth_callback',
            true,
        ];

        yield 'ephemeral port on localhost' => [self::CLAUDE_CODE, 'http://localhost:3118/callback', true];
        yield 'ephemeral port on 127.0.0.1' => [self::CLAUDE_CODE, 'http://127.0.0.1:51234/callback', true];
        yield 'no port at all' => [self::CLAUDE_CODE, 'http://localhost/callback', true];
        yield 'ipv6 loopback' => [['http://[::1]/callback'], 'http://[::1]:8080/callback', true];

        // The port is the ONLY thing the exception relaxes.
        yield 'different path' => [self::CLAUDE_CODE, 'http://localhost:3118/evil', false];
        yield 'added query string' => [self::CLAUDE_CODE, 'http://127.0.0.1:3118/callback?x=1', false];
        yield 'added fragment' => [self::CLAUDE_CODE, 'http://localhost:3118/callback#x', false];
        yield 'https instead of http' => [self::CLAUDE_CODE, 'https://localhost:3118/callback', false];

        // A host that merely starts with a loopback name is a host somebody
        // else owns.
        yield 'lookalike subdomain' => [self::CLAUDE_CODE, 'http://localhost.attacker.example/callback', false];
        yield 'lookalike prefix' => [self::CLAUDE_CODE, 'http://127.0.0.1.attacker.example/callback', false];
        yield 'credentials disguising the host' => [self::CLAUDE_CODE, 'http://localhost@attacker.example/callback', false];

        // A public host never gets port-agnostic treatment, in either direction.
        yield 'public host, different port' => [
            ['https://claude.ai/api/mcp/auth_callback'],
            'https://claude.ai:8443/api/mcp/auth_callback',
            false,
        ];
        yield 'unrelated host' => [self::CLAUDE_CODE, 'https://evil.example/callback', false];

        yield 'empty request' => [self::CLAUDE_CODE, '', false];
        yield 'nothing declared' => [[], 'http://localhost:3118/callback', false];
    }

    #[DataProvider('cases')]
    public function testMatches(array $declared, string $requested, bool $expected): void
    {
        self::assertSame($expected, RedirectUriMatcher::matches($declared, $requested));
    }

    public function testHostComparisonIsCaseInsensitive(): void
    {
        // Hostnames are case-insensitive; a client that sends LOCALHOST is
        // odd but not an attacker, and refusing it would be a bug report.
        self::assertTrue(RedirectUriMatcher::matches(self::CLAUDE_CODE, 'http://LOCALHOST:3118/callback'));
    }

    public function testDetectsLoopbackOnlyDeclarations(): void
    {
        self::assertTrue(RedirectUriMatcher::isLoopbackOnly(self::CLAUDE_CODE));
        self::assertFalse(RedirectUriMatcher::isLoopbackOnly(['https://claude.ai/api/mcp/auth_callback']));
        self::assertFalse(RedirectUriMatcher::isLoopbackOnly(
            ['http://localhost/callback', 'https://claude.ai/api/mcp/auth_callback'],
        ));
        self::assertFalse(RedirectUriMatcher::isLoopbackOnly([]));
    }
}
