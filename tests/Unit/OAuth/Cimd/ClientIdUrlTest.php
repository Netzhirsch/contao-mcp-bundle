<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\OAuth\Cimd;

use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\ClientIdUrl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The first gate a URL-shaped `client_id` passes, and the cheapest place to
 * stop a bad one: nothing here touches the network.
 *
 * Every rejection below is a way of writing a URL that reads as one thing to a
 * human and resolves as another. They are the reason section 3 of the draft
 * constrains the format at all.
 */
#[CoversClass(ClientIdUrl::class)]
final class ClientIdUrlTest extends TestCase
{
    public function testAcceptsTheRealClaudeCodeDocumentUrl(): void
    {
        self::assertNull(ClientIdUrl::reject('https://claude.ai/oauth/claude-code-client-metadata'));
    }

    public function testAcceptsAPortAndAQueryString(): void
    {
        // Ports are explicitly permitted; query strings are discouraged by the
        // draft but not forbidden, and refusing them would break a client the
        // specification allows.
        self::assertNull(ClientIdUrl::reject('https://app.example.com:8443/oauth/client.json?v=2'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejections(): iterable
    {
        yield 'plain http' => ['http://claude.ai/client.json', 'not_https'];
        // Parseable — parse_url reads the whole thing as a path — so the
        // reason is the missing scheme, not a parse failure.
        yield 'no scheme' => ['claude.ai/client.json', 'not_https'];
        yield 'bare host, no path' => ['https://claude.ai', 'no_path'];
        yield 'root path only' => ['https://claude.ai/', 'no_path'];
        yield 'fragment' => ['https://claude.ai/client.json#x', 'fragment'];
        yield 'credentials in the url' => ['https://user:pw@claude.ai/client.json', 'userinfo'];
        yield 'username only' => ['https://evil@claude.ai/client.json', 'userinfo'];
        yield 'parent directory segment' => ['https://claude.ai/a/../../client.json', 'dot_segment'];
        yield 'current directory segment' => ['https://claude.ai/./client.json', 'dot_segment'];
        yield 'ipv4 literal' => ['https://127.0.0.1/client.json', 'ip_literal'];
        yield 'ipv4 literal, public' => ['https://8.8.8.8/client.json', 'ip_literal'];
        yield 'ipv6 literal' => ['https://[::1]/client.json', 'ip_literal'];
        yield 'newline smuggling' => ["https://claude.ai/client.json\r\nHost: evil", 'illegal_characters'];
        yield 'tab' => ["https://claude.ai/cli\tent.json", 'illegal_characters'];
        yield 'trailing dot host' => ['https://claude.ai./client.json', 'invalid_host'];
        yield 'single label host' => ['https://localhost/client.json', 'invalid_host'];
        yield 'empty' => ['', 'length'];
        yield 'over the column length' => ['https://claude.ai/'.str_repeat('a', 260), 'length'];
    }

    #[DataProvider('rejections')]
    public function testRejects(string $url, string $expectedReason): void
    {
        self::assertSame($expectedReason, ClientIdUrl::reject($url));
    }

    public function testLooksLikeUrlOnlyForUrls(): void
    {
        self::assertTrue(ClientIdUrl::looksLikeUrl('https://claude.ai/client.json'));
        // http is caught later with a precise reason; the shape test only
        // decides whether the CIMD path applies at all.
        self::assertTrue(ClientIdUrl::looksLikeUrl('http://claude.ai/client.json'));
        self::assertFalse(ClientIdUrl::looksLikeUrl('a1b2c3d4e5f6'));
        self::assertFalse(ClientIdUrl::looksLikeUrl(''));
    }

    public function testHostIsExtractedForTheConsentScreen(): void
    {
        self::assertSame('claude.ai', ClientIdUrl::host('https://claude.ai/oauth/claude-code-client-metadata'));
        self::assertSame('', ClientIdUrl::host('not a url'));
    }
}
