<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\OAuth\Cimd;

use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\CimdException;
use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\CimdResolver;
use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\HostResolverInterface;
use Netzhirsch\ContaoMcpBundle\OAuth\Cimd\SafeUrlFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * What a metadata document has to say before this server will act on it, and
 * whose documents it will read at all.
 *
 * The single most important assertion in this file is the `client_id`
 * self-consistency check. Without it, a document served from anywhere could
 * claim `client_id: https://claude.ai/...`, and the consent screen would show
 * Claude's name above somebody else's redirect URI.
 */
#[CoversClass(CimdResolver::class)]
final class CimdResolverTest extends TestCase
{
    private const URL = 'https://claude.ai/oauth/claude-code-client-metadata';

    /** Claude Code's real document, verbatim. */
    private const CLAUDE_CODE = [
        'client_id' => self::URL,
        'client_name' => 'Claude Code',
        'client_uri' => 'https://claude.ai',
        'redirect_uris' => ['http://localhost/callback', 'http://127.0.0.1/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ];

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'nh_cimd_'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'mcp', 0o775, true);
    }

    protected function tearDown(): void
    {
        $file = $this->configFile();
        if (is_file($file)) {
            unlink($file);
        }
        @rmdir($this->projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'mcp');
        @rmdir($this->projectDir.\DIRECTORY_SEPARATOR.'var');
        @rmdir($this->projectDir);
    }

    private function configFile(): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'mcp'.\DIRECTORY_SEPARATOR.'config.json';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeConfig(array $config): void
    {
        file_put_contents($this->configFile(), json_encode($config) ?: '{}');
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function resolver(array $responses, int $rateLimit = 100, int $totalLimit = 100): CimdResolver
    {
        $fetcher = new SafeUrlFetcher(
            new MockHttpClient($responses),
            new class implements HostResolverInterface {
                public function resolve(string $host): array
                {
                    return ['160.79.104.1'];
                }
            },
        );

        return new CimdResolver(
            new McpServerConfigStorage($this->projectDir),
            $fetcher,
            new ArrayAdapter(),
            new RateLimiterFactory(
                ['id' => 'test_cimd', 'policy' => 'sliding_window', 'limit' => $rateLimit, 'interval' => '1 hour'],
                new InMemoryStorage(),
            ),
            new RateLimiterFactory(
                ['id' => 'test_cimd_total', 'policy' => 'sliding_window', 'limit' => $totalLimit, 'interval' => '1 hour'],
                new InMemoryStorage(),
            ),
        );
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function json(array $document, string $cacheControl = 'max-age=3600'): MockResponse
    {
        return new MockResponse((string) json_encode($document), [
            'response_headers' => [
                'content-type' => 'application/json',
                'cache-control' => $cacheControl,
            ],
        ]);
    }

    // ───────────────────────────── happy path ─────────────────────────────

    public function testAcceptsClaudeCodesDocument(): void
    {
        $result = $this->resolver([self::json(self::CLAUDE_CODE)])->resolve(self::URL);

        self::assertSame(self::URL, $result['client_id']);
        self::assertSame('Claude Code', $result['client_name']);
        self::assertSame(['http://localhost/callback', 'http://127.0.0.1/callback'], $result['redirect_uris']);
    }

    public function testAcceptsTheHostedSurfaceCallback(): void
    {
        $document = self::CLAUDE_CODE;
        $document['redirect_uris'] = ['https://claude.ai/api/mcp/auth_callback'];

        $result = $this->resolver([self::json($document)])->resolve(self::URL);

        self::assertSame(['https://claude.ai/api/mcp/auth_callback'], $result['redirect_uris']);
    }

    /**
     * A second authorization must not cost a second request — and the mock
     * holds exactly one response, so a cache miss here would fail loudly.
     */
    public function testCachesTheDocument(): void
    {
        $resolver = $this->resolver([self::json(self::CLAUDE_CODE)]);

        $first = $resolver->resolve(self::URL);
        $second = $resolver->resolve(self::URL);

        self::assertSame($first, $second);
    }

    // ─────────────────────────── document checks ──────────────────────────

    /**
     * The load-bearing check: a document may only speak for the URL it was
     * fetched from.
     */
    public function testRefusesADocumentClaimingSomebodyElsesClientId(): void
    {
        $document = self::CLAUDE_CODE;
        $document['client_id'] = 'https://claude.ai/oauth/some-other-client';

        $this->expectExceptionMessage('client_id_mismatch');

        $this->resolver([self::json($document)])->resolve(self::URL);
    }

    public function testRefusesADocumentWithoutAClientId(): void
    {
        $document = self::CLAUDE_CODE;
        unset($document['client_id']);

        $this->expectExceptionMessage('client_id_mismatch');

        $this->resolver([self::json($document)])->resolve(self::URL);
    }

    public function testRefusesABlankClientName(): void
    {
        $document = self::CLAUDE_CODE;
        $document['client_name'] = '   ';

        $this->expectExceptionMessage('client_name');

        $this->resolver([self::json($document)])->resolve(self::URL);
    }

    public function testRefusesAnEmptyRedirectUriList(): void
    {
        $document = self::CLAUDE_CODE;
        $document['redirect_uris'] = [];

        $this->expectExceptionMessage('redirect_uris');

        $this->resolver([self::json($document)])->resolve(self::URL);
    }

    /**
     * "All redirect URIs MUST be either localhost or use HTTPS" — a plaintext
     * redirect to a routable host hands the authorization code to the network.
     */
    public function testRefusesAPlaintextRedirectToARoutableHost(): void
    {
        $document = self::CLAUDE_CODE;
        $document['redirect_uris'] = ['http://evil.example/callback'];

        $this->expectExceptionMessage('redirect_uri_scheme');

        $this->resolver([self::json($document)])->resolve(self::URL);
    }

    public function testRefusesANonHttpRedirectScheme(): void
    {
        $document = self::CLAUDE_CODE;
        $document['redirect_uris'] = ['javascript:alert(1)'];

        $this->expectExceptionMessage('redirect_uri_scheme');

        $this->resolver([self::json($document)])->resolve(self::URL);
    }

    public function testRefusesAnAbsurdNumberOfRedirectUris(): void
    {
        $document = self::CLAUDE_CODE;
        $document['redirect_uris'] = array_map(
            static fn (int $i) => 'https://claude.ai/cb/'.$i,
            range(1, 50),
        );

        $this->expectExceptionMessage('redirect_uris');

        $this->resolver([self::json($document)])->resolve(self::URL);
    }

    /**
     * A client asking for `private_key_jwt` expects its signature to be
     * verified. Treating it as a public client would quietly remove the very
     * protection it asked for, so it is refused until we implement it.
     */
    public function testRefusesAnAuthMethodWeDoNotImplement(): void
    {
        $document = self::CLAUDE_CODE;
        $document['token_endpoint_auth_method'] = 'private_key_jwt';

        $this->expectExceptionMessage('unsupported_auth_method');

        $this->resolver([self::json($document)])->resolve(self::URL);
    }

    public function testRefusesAClientThatCannotDoTheAuthorizationCodeFlow(): void
    {
        $document = self::CLAUDE_CODE;
        $document['grant_types'] = ['client_credentials'];

        $this->expectExceptionMessage('grant_types');

        $this->resolver([self::json($document)])->resolve(self::URL);
    }

    public function testRefusesSomethingThatIsNotAJsonObject(): void
    {
        $this->expectExceptionMessage('not_a_json_object');

        $this->resolver([new MockResponse('[1,2,3]', [
            'response_headers' => ['content-type' => 'application/json'],
        ])])->resolve(self::URL);
    }

    // ─────────────────────────── trust policy ─────────────────────────────

    public function testTrustedModeRefusesAnUnknownHost(): void
    {
        $this->expectExceptionMessage('host_not_trusted');

        $this->resolver([self::json(self::CLAUDE_CODE)])->resolve('https://evil.example/client.json');
    }

    /**
     * The allowlist is matched on label boundaries. A plain suffix comparison
     * would let `notclaude.ai` pass for `claude.ai`.
     */
    public function testTrustedModeRefusesALookalikeHost(): void
    {
        $this->expectExceptionMessage('host_not_trusted');

        $this->resolver([self::json(self::CLAUDE_CODE)])->resolve('https://notclaude.ai/client.json');
    }

    public function testTrustedModeAcceptsASubdomainOfATrustedHost(): void
    {
        $url = 'https://sub.claude.ai/client.json';
        $document = self::CLAUDE_CODE;
        $document['client_id'] = $url;

        $result = $this->resolver([self::json($document)])->resolve($url);

        self::assertSame($url, $result['client_id']);
    }

    public function testOpenModeAcceptsAnyHttpsUrl(): void
    {
        $this->writeConfig(['cimd_mode' => 'open']);

        $url = 'https://app.example.com/oauth/client.json';
        $document = self::CLAUDE_CODE;
        $document['client_id'] = $url;

        $result = $this->resolver([self::json($document)])->resolve($url);

        self::assertSame($url, $result['client_id']);
    }

    public function testOffModeRefusesEverythingAndIsNotAdvertised(): void
    {
        $this->writeConfig(['cimd_mode' => 'off']);

        $resolver = $this->resolver([self::json(self::CLAUDE_CODE)]);

        self::assertFalse($resolver->isEnabled());

        $this->expectExceptionMessage('disabled');
        $resolver->resolve(self::URL);
    }

    public function testAnEmptyAllowlistTrustsNobody(): void
    {
        $this->writeConfig(['cimd_mode' => 'trusted', 'cimd_trusted_hosts' => []]);

        $this->expectExceptionMessage('host_not_trusted');

        $this->resolver([self::json(self::CLAUDE_CODE)])->resolve(self::URL);
    }

    public function testTheShapeCheckRunsBeforeAnythingReachesTheNetwork(): void
    {
        // No responses queued at all: if a request were made, the mock would
        // fail instead of the URL being refused on its shape.
        $this->expectExceptionMessage('not_https');

        $this->resolver([])->resolve('http://claude.ai/client.json');
    }

    // ─────────────────────────── rate limiting ────────────────────────────

    /**
     * The SSRF guard decides where we may connect; this decides how often, so
     * the endpoint cannot be turned into a way of pointing our bandwidth at
     * somebody else.
     */
    public function testRefusesOnceTheFetchBudgetIsSpent(): void
    {
        $first = 'https://claude.ai/oauth/one.json';
        $second = 'https://claude.ai/oauth/two.json';

        $docA = self::CLAUDE_CODE;
        $docA['client_id'] = $first;
        $docB = self::CLAUDE_CODE;
        $docB['client_id'] = $second;

        $resolver = $this->resolver([self::json($docA), self::json($docB)], rateLimit: 1);

        $resolver->resolve($first);

        $this->expectExceptionMessage('rate_limited');
        $resolver->resolve($second);
    }

    /**
     * The per-host bucket does not bound the total: in `open` mode a fresh
     * subdomain buys a fresh bucket, and /authorize is reachable before anyone
     * has logged in. Without a ceiling across all hosts, this endpoint is an
     * outbound-request generator for whoever asks.
     */
    public function testRefusesOnceTheTotalFetchBudgetIsSpentAcrossDifferentHosts(): void
    {
        $this->writeConfig(['cimd_mode' => 'open']);

        $first = 'https://a.example/client.json';
        $second = 'https://b.example/client.json';

        $docA = self::CLAUDE_CODE;
        $docA['client_id'] = $first;
        $docB = self::CLAUDE_CODE;
        $docB['client_id'] = $second;

        // Generous per-host budget, tight total: only the total can stop this.
        $resolver = $this->resolver([self::json($docA), self::json($docB)], rateLimit: 100, totalLimit: 1);

        $resolver->resolve($first);

        $this->expectExceptionMessage('rate_limited');
        $resolver->resolve($second);
    }

    /**
     * The name is shown to a human about to grant access, so it has to be safe
     * to LOOK at. HTML escaping does not help: a right-to-left override is a
     * legitimate character that survives escaping and reorders the text around
     * it in the renderer.
     */
    public function testStripsCharactersThatCanReorderTheConsentScreen(): void
    {
        $document = self::CLAUDE_CODE;
        $document['client_name'] = "Contao \u{202E}Backend\u{202C} \u{2066}Sync\u{2069}";

        $result = $this->resolver([self::json($document)])->resolve(self::URL);

        self::assertSame('Contao Backend Sync', $result['client_name']);
    }

    public function testStripsControlCharactersFromTheName(): void
    {
        $document = self::CLAUDE_CODE;
        $document['client_name'] = "Claude\r\n\tCode";

        $result = $this->resolver([self::json($document)])->resolve(self::URL);

        self::assertSame('ClaudeCode', $result['client_name']);
    }

    public function testRefusesANameThatIsNothingButControlCharacters(): void
    {
        $document = self::CLAUDE_CODE;
        $document['client_name'] = "\u{202E}\u{202C}";

        $this->expectExceptionMessage('client_name');

        $this->resolver([self::json($document)])->resolve(self::URL);
    }

    /**
     * A cache hit costs nothing, so it must not spend budget — otherwise a
     * client that simply reconnects often would throttle itself out.
     */
    public function testACacheHitDoesNotSpendTheBudget(): void
    {
        $resolver = $this->resolver([self::json(self::CLAUDE_CODE)], rateLimit: 1);

        $resolver->resolve(self::URL);
        $again = $resolver->resolve(self::URL);

        self::assertSame('Claude Code', $again['client_name']);
    }

    public function testAFailedFetchIsNotCached(): void
    {
        $broken = self::CLAUDE_CODE;
        $broken['client_id'] = 'https://claude.ai/oauth/wrong';

        $resolver = $this->resolver([self::json($broken), self::json(self::CLAUDE_CODE)]);

        try {
            $resolver->resolve(self::URL);
            self::fail('The first document should have been refused.');
        } catch (CimdException $e) {
            self::assertSame('client_id_mismatch', $e->reason);
        }

        // A fixed document must take effect immediately, not after a cache
        // lifetime that a refused document should never have started.
        self::assertSame('Claude Code', $resolver->resolve(self::URL)['client_name']);
    }
}
