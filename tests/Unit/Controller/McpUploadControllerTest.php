<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Controller;

use Netzhirsch\ContaoMcpBundle\Controller\McpUploadController;
use Netzhirsch\ContaoMcpBundle\Tool\File\UploadSessions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * The raw-byte endpoint is the one way into an upload buffer that does not go
 * through MCP authentication, so what it refuses matters as much as what it
 * accepts: no token, a wrong token, a wrong offset, a body that does not fit.
 * Every refusal must leave the buffer as it was.
 */
#[CoversClass(McpUploadController::class)]
final class McpUploadControllerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'mcp-put-test-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->projectDir);
    }

    public function testTheWholeFileInOneRequest(): void
    {
        [$id, $token] = $this->open(1000);
        $body = random_bytes(1000);

        $response = $this->put($id, $token, 0, $body);
        $answer = $this->decode($response->getContent());

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($answer['complete']);
        self::assertSame('1000', $response->headers->get('Upload-Offset'));
        self::assertSame($body, $this->sessions()->bytes($id));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * The way around a web-server body limit: several requests, each saying
     * where it continues.
     */
    public function testAFileInSeveralRequests(): void
    {
        [$id, $token] = $this->open(10);

        self::assertSame(200, $this->put($id, $token, 0, 'abcd')->getStatusCode());
        $last = $this->put($id, $token, 4, 'efghij');

        self::assertTrue($this->decode($last->getContent())['complete']);
        self::assertSame('abcdefghij', $this->sessions()->bytes($id));
    }

    /**
     * A retry after a dropped connection must not append the same bytes a
     * second time. The answer says where to continue.
     */
    public function testARepeatedRequestIsRefusedWithTheOffsetToResumeAt(): void
    {
        [$id, $token] = $this->open(8);
        $this->put($id, $token, 0, 'abcd');

        $repeat = $this->put($id, $token, 0, 'abcd');

        self::assertSame(409, $repeat->getStatusCode());
        self::assertSame(4, $this->decode($repeat->getContent())['received_bytes']);
        self::assertSame('abcd', $this->sessions()->bytes($id));
    }

    /**
     * A missing or wrong token gets the same answer as an id that does not
     * exist: the endpoint does not confirm which uploads are open.
     */
    public function testWithoutTheRightTokenTheUploadDoesNotExist(): void
    {
        [$id] = $this->open(4);

        $none = $this->put($id, '', 0, 'abcd');
        $wrong = $this->put($id, str_repeat('0', 64), 0, 'abcd');
        $unknown = $this->put(str_repeat('c', 32), str_repeat('0', 64), 0, 'abcd');

        self::assertSame(404, $none->getStatusCode());
        self::assertSame(404, $wrong->getStatusCode());
        self::assertSame($unknown->getContent(), $wrong->getContent());
        self::assertSame('', $this->sessions()->bytes($id));
    }

    /**
     * One token opens one upload, not every upload of the same user.
     */
    public function testATokenDoesNotOpenAnotherUpload(): void
    {
        [, $token] = $this->open(4);
        [$other] = $this->open(4);

        self::assertSame(404, $this->put($other, $token, 0, 'abcd')->getStatusCode());
    }

    public function testABodyLargerThanDeclaredIsRefusedBeforeItIsRead(): void
    {
        [$id, $token] = $this->open(4);

        $response = $this->put($id, $token, 0, 'abcdefgh');

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('', $this->sessions()->bytes($id));
    }

    /**
     * Content-Length is the client's claim, and a client can leave it out or
     * get it wrong. The limit holds on what actually arrives.
     */
    public function testABodyLargerThanDeclaredIsRefusedWithoutAContentLength(): void
    {
        [$id, $token] = $this->open(4);

        $response = $this->put($id, $token, 0, 'abcdefgh', withLength: false);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('', $this->sessions()->bytes($id));
        self::assertSame(0, (int) ((array) $this->sessions()->load($id))['received_bytes']);
    }

    public function testTheOffsetHeaderIsRequired(): void
    {
        [$id, $token] = $this->open(4);

        $request = Request::create('/mcp/upload/'.$id, 'PUT', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token], 'abcd');
        $request->attributes->set('upload_id', $id);

        self::assertSame(400, $this->controller()($request)->getStatusCode());
        self::assertSame('', $this->sessions()->bytes($id));
    }

    /**
     * @return array{string, string} upload id and token
     */
    private function open(int $size): array
    {
        $sessions = $this->sessions();
        $id = $sessions->create(['total_size_bytes' => $size, 'name' => 'f.bin', 'parent_path' => '']);

        return [$id, $sessions->issueDirectToken($id)];
    }

    private function put(string $id, string $token, int $offset, string $body, bool $withLength = true): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $server = ['HTTP_UPLOAD_OFFSET' => (string) $offset, 'CONTENT_TYPE' => 'application/octet-stream'];

        if ($token !== '') {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $request = Request::create('/mcp/upload/'.$id, 'PUT', [], [], [], $server, $body);
        $request->attributes->set('upload_id', $id);

        if (!$withLength) {
            $request->headers->remove('Content-Length');
        } else {
            $request->headers->set('Content-Length', (string) \strlen($body));
        }

        return $this->controller()($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string|false $json): array
    {
        $decoded = json_decode((string) $json, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function controller(): McpUploadController
    {
        return new McpUploadController($this->projectDir, new NullLogger());
    }

    private function sessions(): UploadSessions
    {
        return new UploadSessions($this->projectDir);
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
