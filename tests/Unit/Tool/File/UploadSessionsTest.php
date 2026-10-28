<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Tool\File;

use Netzhirsch\ContaoMcpBundle\Tool\File\UploadSessions;
use PHPUnit\Framework\TestCase;

/**
 * The buffer a chunked upload lives in while it is incomplete.
 *
 * Two of these tests are about an id that is used to build a filesystem path,
 * which is the one place here where getting it wrong would be more than an
 * inconvenience.
 */
final class UploadSessionsTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'mcp-upload-test-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->projectDir);
    }

    public function testAFreshSessionStartsEmptyAndAtSequenceZero(): void
    {
        $sessions = $this->sessions();
        $id = $sessions->create(['name' => 'a.png', 'total_size_bytes' => 10]);

        $manifest = $sessions->load($id);

        self::assertIsArray($manifest);
        self::assertSame(0, $manifest['received_bytes']);
        self::assertSame(0, $manifest['next_sequence']);
        self::assertSame('a.png', $manifest['name']);
    }

    public function testAppendingCountsBytesAndAdvancesTheSequence(): void
    {
        $sessions = $this->sessions();
        $id = $sessions->create(['total_size_bytes' => 6]);

        $manifest = $sessions->append((array) $sessions->load($id), 'abc');
        $manifest = $sessions->append($manifest, 'def');

        self::assertSame(6, $manifest['received_bytes']);
        self::assertSame(2, $manifest['next_sequence']);
        self::assertSame('abcdef', $sessions->bytes($id));
    }

    public function testTheBufferSurvivesBetweenCalls(): void
    {
        $sessions = $this->sessions();
        $id = $sessions->create(['total_size_bytes' => 3]);
        $sessions->append((array) $sessions->load($id), 'xyz');

        // A second instance is what a second MCP request really gets.
        self::assertSame('xyz', $this->sessions()->bytes($id));
    }

    /**
     * The id is interpolated into a path, so anything but hex is refused before
     * it gets near the filesystem — not sanitised, refused.
     */
    public function testAnIdThatIsNotHexIsRejected(): void
    {
        $sessions = $this->sessions();

        self::assertNull($sessions->load('../../../etc/passwd'));
        self::assertNull($sessions->load('..'));
        self::assertNull($sessions->load(''));
        self::assertNull($sessions->load(str_repeat('z', 32)));
    }

    public function testDiscardingAHostileIdTouchesNothing(): void
    {
        $canary = $this->projectDir.\DIRECTORY_SEPARATOR.'canary.txt';
        file_put_contents($canary, 'still here');

        $this->sessions()->discard('../canary.txt');

        self::assertFileExists($canary);
    }

    public function testAnExpiredSessionIsGoneWhenItIsLookedUp(): void
    {
        $sessions = $this->sessions();
        $id = $sessions->create(['total_size_bytes' => 1]);

        $this->ageOut($id);

        self::assertNull($sessions->load($id));
        self::assertDirectoryDoesNotExist($this->dir($id));
    }

    public function testSweepRemovesOnlyWhatHasExpired(): void
    {
        $sessions = $this->sessions();
        $stale = $sessions->create(['total_size_bytes' => 1]);
        $fresh = $sessions->create(['total_size_bytes' => 1]);

        $this->ageOut($stale);

        self::assertSame(1, $sessions->sweep());
        self::assertDirectoryDoesNotExist($this->dir($stale));
        self::assertIsArray($sessions->load($fresh));
    }

    public function testADirectoryWithoutAManifestIsSweptToo(): void
    {
        $sessions = $this->sessions();
        $orphan = str_repeat('a', 32);
        mkdir($this->dir($orphan), 0o700, true);

        self::assertSame(1, $sessions->sweep());
        self::assertDirectoryDoesNotExist($this->dir($orphan));
    }

    public function testTwoSessionsDoNotShareABuffer(): void
    {
        $sessions = $this->sessions();
        $one = $sessions->create(['total_size_bytes' => 3]);
        $two = $sessions->create(['total_size_bytes' => 3]);

        $sessions->append((array) $sessions->load($one), 'aaa');
        $sessions->append((array) $sessions->load($two), 'bbb');

        self::assertSame('aaa', $sessions->bytes($one));
        self::assertSame('bbb', $sessions->bytes($two));
    }

    /**
     * The recommendation used to be 256 KB, while the tools warned that inline
     * base64 breaks somewhere above 50 KB — and a chunk is inline base64. This
     * holds the recommendation below that point, base64 overhead included.
     */
    public function testTheRecommendedChunkStaysBelowWhereInlineBase64Breaks(): void
    {
        self::assertLessThanOrEqual(50_000, (int) ceil(UploadSessions::CHUNK_SIZE_RECOMMENDED / 3) * 4);
    }

    /**
     * A file sent in many small slices by a model that needs half a minute
     * per call must not expire while it is still arriving.
     */
    public function testEveryChunkPushesTheExpiryBack(): void
    {
        $sessions = $this->sessions();
        $id = $sessions->create(['total_size_bytes' => 2]);
        $this->setExpiry($id, time() + 5);

        $manifest = $sessions->append((array) $sessions->load($id), 'a');

        self::assertGreaterThan(time() + UploadSessions::TTL_SECONDS - 5, $manifest['expires_at']);
    }

    public function testOnlyTheIssuedTokenMatches(): void
    {
        $sessions = $this->sessions();
        $id = $sessions->create(['total_size_bytes' => 1]);
        $token = $sessions->issueDirectToken($id);
        $manifest = (array) $sessions->load($id);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertTrue(UploadSessions::tokenMatches($manifest, $token));
        self::assertFalse(UploadSessions::tokenMatches($manifest, str_repeat('0', 64)));
        self::assertFalse(UploadSessions::tokenMatches($manifest, ''));
        self::assertStringNotContainsString($token, (string) file_get_contents($this->dir($id).\DIRECTORY_SEPARATOR.'manifest.json'), 'only the hash is stored');
    }

    public function testASessionWithoutATokenMatchesNoToken(): void
    {
        self::assertFalse(UploadSessions::tokenMatches(['id' => 'x'], str_repeat('a', 64)));
    }

    public function testAStreamIsAppendedInFull(): void
    {
        $sessions = $this->sessions();
        $id = $sessions->create(['total_size_bytes' => 200_000]);
        $body = random_bytes(200_000);

        $manifest = $sessions->appendStream((array) $sessions->load($id), $this->stream($body), 200_000);

        self::assertIsArray($manifest);
        self::assertSame(200_000, $manifest['received_bytes']);
        self::assertSame($body, $sessions->bytes($id));
    }

    /**
     * A body that is too long must leave the session exactly as it was — not
     * with the part that still fitted.
     */
    public function testAStreamPastTheLimitLeavesTheBufferUntouched(): void
    {
        $sessions = $this->sessions();
        $id = $sessions->create(['total_size_bytes' => 100_000]);
        $manifest = $sessions->append((array) $sessions->load($id), 'head');

        self::assertNull($sessions->appendStream($manifest, $this->stream(random_bytes(150_000)), 100_000));
        self::assertSame('head', $sessions->bytes($id));
        self::assertSame(4, (int) ((array) $sessions->load($id))['received_bytes']);
    }

    public function testTheLockHandsOverTheCurrentManifest(): void
    {
        $sessions = $this->sessions();
        $id = $sessions->create(['total_size_bytes' => 3, 'name' => 'a.txt']);

        $seen = $sessions->locked($id, static fn (array $manifest): string => (string) $manifest['name']);

        self::assertSame('a.txt', $seen);
        self::assertNull($sessions->locked(str_repeat('b', 32), static fn (): string => 'never'));
        self::assertNull($sessions->locked('../x', static fn (): string => 'never'));
    }

    public function testOpenUploadsAreCountedPerUser(): void
    {
        $sessions = $this->sessions();
        $sessions->create(['user_id' => 7]);
        $sessions->create(['user_id' => 7]);
        $sessions->create(['user_id' => 8]);
        $expired = $sessions->create(['user_id' => 7]);
        $this->ageOut($expired);

        self::assertSame(2, $sessions->openFor(7));
        self::assertSame(1, $sessions->openFor(8));
        self::assertSame(0, $sessions->openFor(null));
    }

    public function testAnOldOneShotBufferIsSweptAndAFreshOneIsNot(): void
    {
        $sessions = $this->sessions();
        $old = $sessions->tempFile();
        $fresh = $sessions->tempFile();
        touch($old, time() - UploadSessions::TTL_SECONDS - 10);

        $sessions->sweep();

        self::assertFileDoesNotExist($old);
        self::assertFileExists($fresh);
        self::assertNull($sessions->load(basename($fresh)), 'a buffer is never taken for a session');
    }

    /**
     * @return resource
     */
    private function stream(string $contents)
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    private function setExpiry(string $id, int $at): void
    {
        $path = $this->dir($id).\DIRECTORY_SEPARATOR.'manifest.json';
        $manifest = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($manifest);
        $manifest['expires_at'] = $at;
        file_put_contents($path, json_encode($manifest));
    }

    private function sessions(): UploadSessions
    {
        return new UploadSessions($this->projectDir);
    }

    private function dir(string $id): string
    {
        return implode(\DIRECTORY_SEPARATOR, [$this->projectDir, 'var', 'mcp', 'uploads', $id]);
    }

    /**
     * Pushes a session's expiry into the past, which is the only way to test a
     * TTL without waiting an hour.
     */
    private function ageOut(string $id): void
    {
        $path = $this->dir($id).\DIRECTORY_SEPARATOR.'manifest.json';
        $manifest = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($manifest);
        $manifest['expires_at'] = time() - 1;
        file_put_contents($path, json_encode($manifest));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if (!\is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path.\DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }
}
