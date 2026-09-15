<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\License;

use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\License\LicenseStore;
use Netzhirsch\ContaoMcpBundle\License\RenewalClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The release announcement that comes BACK from /trial and /renew.
 *
 * It is optional and additive, so the interesting cases are the degenerate
 * ones: absent, retracted, malformed. In every one of them the licence itself
 * must come through untouched — that is the condition the whole feature is
 * built under, not a nice-to-have.
 */
#[CoversClass(RenewalClient::class)]
#[CoversClass(LicenseStore::class)]
final class RenewalClientUpdateNoticeTest extends TestCase
{
    private const NOTES = 'https://github.com/Netzhirsch/contao-mcp-bundle/releases/tag/1.27.0';

    public function testAnAnnouncementIsStoredAndReturned(): void
    {
        [$client, $store] = $this->clientReturning([
            'token' => 'x',
            'latest_version' => '1.27.0',
            'release_notes_url' => self::NOTES,
            'security_release' => true,
        ]);

        $result = $client->renew(force: true);

        self::assertTrue($result['ok']);
        self::assertSame('1.27.0', $result['latest_version'] ?? null);
        self::assertSame(self::NOTES, $result['release_notes_url'] ?? null);
        self::assertTrue($result['security_release'] ?? null);

        // Stored, so the backend can show it without a server call — the cron
        // runs at most every six hours.
        self::assertSame('1.27.0', $store->getLatestVersion());
        self::assertSame(self::NOTES, $store->getReleaseNotesUrl());
        self::assertTrue($store->isSecurityRelease());
    }

    /**
     * The normal state. A server that announces nothing sends nulls, and an
     * older server does not send the keys at all — neither may leave anything
     * behind.
     */
    public function testNothingAnnouncedStoresNothing(): void
    {
        [$client, $store] = $this->clientReturning(['token' => 'x', 'latest_version' => null, 'release_notes_url' => null, 'security_release' => false]);

        self::assertTrue($client->renew(force: true)['ok']);
        self::assertSame('', $store->getLatestVersion());
        self::assertSame('', $store->getReleaseNotesUrl());
        self::assertFalse($store->isSecurityRelease());
    }

    /**
     * An announcement can be WITHDRAWN at the server. If the withdrawal did not
     * clear the stored values, the retracted version would stand in the backend
     * of every customer until the next announcement replaced it.
     */
    public function testAWithdrawnAnnouncementIsCleared(): void
    {
        [$client, $store] = $this->clientReturning([
            ['token' => 'x', 'latest_version' => '1.27.0', 'release_notes_url' => self::NOTES, 'security_release' => true],
            ['token' => 'x', 'latest_version' => null, 'release_notes_url' => null, 'security_release' => false],
        ]);

        $client->renew(force: true);
        self::assertSame('1.27.0', $store->getLatestVersion(), 'precondition');

        $client->renew(force: true);

        self::assertSame('', $store->getLatestVersion());
        self::assertSame('', $store->getReleaseNotesUrl());
        self::assertFalse($store->isSecurityRelease());
    }

    /**
     * A malformed announcement is dropped, and — the part that matters — the
     * renewal still succeeds. An array in `latest_version` would otherwise
     * raise "Array to string conversion", which PHP's error handler turns into
     * an exception under PHPUnit and in dev: the renewal would come back as
     * 'unreachable' and a display-only field would have broken the licensing.
     */
    public function testAMalformedAnnouncementIsDroppedAndTheRenewalStillSucceeds(): void
    {
        [$client, $store] = $this->clientReturning([
            'token' => 'x',
            'latest_version' => ['1.27.0'],
            'release_notes_url' => ['http://example.com'],
            'security_release' => ['yes'],
        ]);

        $result = $client->renew(force: true);

        self::assertTrue($result['ok'], 'the licence must survive a broken announcement');
        self::assertSame('', $store->getLatestVersion());
        self::assertSame('', $store->getReleaseNotesUrl());
        self::assertFalse($store->isSecurityRelease());
    }

    /**
     * A version the server would have rejected on the way in is rejected here
     * on the way out, and a plain-http link is dropped while the version
     * survives — the link is the optional part.
     */
    public function testValuesAreFilteredNotTrusted(): void
    {
        [$client, $store] = $this->clientReturning([
            'token' => 'x',
            'latest_version' => '  1.27.0  ',
            'release_notes_url' => 'http://example.com/notes',
            'security_release' => false,
        ]);

        $client->renew(force: true);

        self::assertSame('1.27.0', $store->getLatestVersion());
        self::assertSame('', $store->getReleaseNotesUrl());
    }

    /**
     * A failing call (unpaid, revoked, unreachable) carries no announcement —
     * there it is about the error. Whatever was stored before stays, rather
     * than being wiped by a server outage.
     */
    public function testAFailedCallLeavesTheStoredAnnouncementAlone(): void
    {
        [$client, $store] = $this->clientReturning([
            ['token' => 'x', 'latest_version' => '1.27.0', 'release_notes_url' => self::NOTES, 'security_release' => false],
            ['error' => 'unpaid', 'message' => 'Subscription is not active.'],
        ], [200, 402]);

        $client->renew(force: true);
        self::assertSame('1.27.0', $store->getLatestVersion(), 'precondition');

        self::assertFalse($client->renew(force: true)['ok']);
        self::assertSame('1.27.0', $store->getLatestVersion());
    }

    /**
     * @param array<mixed>     $responses one body, or a list of bodies answered in order
     * @param list<int>        $codes     HTTP status per response (default 200)
     *
     * @return array{RenewalClient, LicenseStore}
     */
    private function clientReturning(array $responses, array $codes = []): array
    {
        $bodies = array_is_list($responses) ? $responses : [$responses];
        $queue = [];
        foreach ($bodies as $i => $body) {
            $queue[] = new MockResponse(
                (string) json_encode($body),
                ['http_code' => $codes[$i] ?? 200, 'response_headers' => ['content-type' => 'application/json']],
            );
        }

        $projectDir = sys_get_temp_dir().'/mcp-un-'.bin2hex(random_bytes(5));
        mkdir($projectDir.'/var/mcp', 0o777, true);
        file_put_contents(
            $projectDir.'/var/mcp/config.json',
            (string) json_encode(['license_server_url' => 'https://license.example', 'auth_mode' => 'none']),
        );

        $store = new LicenseStore($projectDir);

        return [
            new RenewalClient(
                new MockHttpClient($queue),
                $store,
                new McpServerConfigStorage($projectDir),
                new RequestStack(),
                new NullLogger(),
            ),
            $store,
        ];
    }
}
