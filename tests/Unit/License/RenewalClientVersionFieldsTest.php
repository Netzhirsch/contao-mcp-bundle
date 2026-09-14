<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\License;

use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\License\LicenseStore;
use Netzhirsch\ContaoMcpBundle\License\RenewalClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The license server had no way to tell which bundle version runs at a
 * customer; the only signal was indirect. These three fields answer that.
 *
 * The sanitiser matters more than it looks: the server discards what does not
 * match its rules and keeps the previous value. That is right on its side, but
 * it means a malformed value arrives as "this instance never reported" —
 * indistinguishable from an installation that predates the feature. So a field
 * that cannot be made valid is left out here instead, and a field that IS sent
 * is one that will be accepted.
 */
#[CoversClass(RenewalClient::class)]
final class RenewalClientVersionFieldsTest extends TestCase
{
    private static function sanitise(string $value): string
    {
        $method = new \ReflectionMethod(RenewalClient::class, 'sanitiseVersion');

        return (string) $method->invoke(null, $value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function values(): iterable
    {
        // What Composer actually hands back, from the briefing.
        yield 'release' => ['1.0.10', '1.0.10'];
        yield 'prerelease with build' => ['v1.2.0-beta.1+build.7', 'v1.2.0-beta.1+build.7'];
        yield 'branch alias' => ['5.7.x-dev', '5.7.x-dev'];
        yield 'branch' => ['dev-master', 'dev-master'];
        yield 'fallback' => ['dev', 'dev'];

        // Trimmed rather than rejected — leading whitespace is not a reason to
        // lose a perfectly good version.
        yield 'padded' => ['  1.0.10  ', '1.0.10'];

        // The briefing's own counter-example: a space inside the string.
        yield 'space inside' => ['1.0.10 beta', ''];
        yield 'empty' => ['', ''];
        yield 'slash' => ['1.0/10', ''];
        yield 'too long' => [str_repeat('9', 33), ''];
        yield 'exactly at the limit' => [str_repeat('9', 32), str_repeat('9', 32)];
    }

    #[DataProvider('values')]
    public function testSanitiser(string $input, string $expected): void
    {
        self::assertSame($expected, self::sanitise($input));
    }

    /**
     * A PHP built with a distribution suffix — 8.3.14-1+deb12u1 — passes the
     * server's character rules, so it would be stored. The column would then
     * hold a Debian build id rather than a PHP version, and two instances on
     * the same PHP would not group. We send the three-part version instead.
     */
    public function testThePhpVersionIsTheVersionNotThePackageBuild(): void
    {
        $fields = new \ReflectionMethod(RenewalClient::class, 'versionFields');
        $client = (new \ReflectionClass(RenewalClient::class))->newInstanceWithoutConstructor();

        /** @var array<string, string> $result */
        $result = $fields->invoke($client);

        self::assertSame(
            PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION,
            $result['php_version'],
        );
        self::assertDoesNotMatchRegularExpression('/[+-]/', $result['php_version']);
    }

    /**
     * The briefing's actual ask: BOTH calls carry the fields, and neither can
     * be forgotten. That is why they are added in post() rather than at the two
     * call sites — this test is what makes that claim checkable.
     */
    public function testTrialAndRenewBothCarryTheFieldsInTheRequestBody(): void
    {
        $captured = [];

        $http = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$captured): MockResponse {
                $captured[] = [$url, json_decode((string) ($options['body'] ?? '{}'), true)];

                return new MockResponse(
                    (string) json_encode(['token' => 'x']),
                    ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
                );
            },
        );

        $client = $this->clientWith($http);

        // The token cannot be stored without a real signing key, so both calls
        // end in a storage failure — by which point the request has gone out,
        // which is what this test is about.
        $client->startTrial('a@example.com');
        $client->renew(force: true);

        self::assertNotSame([], $captured, 'no request was made');

        foreach ($captured as [$url, $body]) {
            self::assertIsArray($body, $url);
            self::assertArrayHasKey('bundle_version', $body, $url);
            self::assertArrayHasKey('contao_version', $body, $url);
            self::assertArrayHasKey('php_version', $body, $url);

            // …and the original body survives. `+=` exists so that a later
            // rewrite of post() cannot displace product or token.
            self::assertArrayHasKey('product', $body, $url);
        }
    }

    private function clientWith(MockHttpClient $http): RenewalClient
    {
        $projectDir = sys_get_temp_dir().'/mcp-rc-'.bin2hex(random_bytes(5));
        mkdir($projectDir.'/var/mcp', 0o777, true);
        file_put_contents(
            $projectDir.'/var/mcp/config.json',
            (string) json_encode(['license_server_url' => 'https://license.example', 'auth_mode' => 'none']),
        );

        return new RenewalClient(
            $http,
            new LicenseStore($projectDir),
            new McpServerConfigStorage($projectDir),
            new RequestStack(),
            new NullLogger(),
        );
    }

    /**
     * Three fields, and only these three. The channel is not telemetry: no
     * installed bundles, no counts, no content, no user data.
     */
    public function testExactlyThreeFieldsAreSent(): void
    {
        $fields = new \ReflectionMethod(RenewalClient::class, 'versionFields');
        $client = (new \ReflectionClass(RenewalClient::class))->newInstanceWithoutConstructor();

        /** @var array<string, string> $result */
        $result = $fields->invoke($client);

        self::assertSame(
            ['bundle_version', 'contao_version', 'php_version'],
            array_keys($result),
        );
    }
}
