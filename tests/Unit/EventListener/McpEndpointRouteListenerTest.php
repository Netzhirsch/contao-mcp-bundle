<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\EventListener;

use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\Controller\McpController;
use Netzhirsch\ContaoMcpBundle\Controller\McpHealthzController;
use Netzhirsch\ContaoMcpBundle\EventListener\McpEndpointRouteListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The endpoint is where var/mcp/config.json says, from the request after the
 * save on — and nowhere else.
 */
#[CoversClass(McpEndpointRouteListener::class)]
final class McpEndpointRouteListenerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'mcp-route-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/var/mcp', 0o775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/var/mcp/config.json');
        @rmdir($this->dir.'/var/mcp');
        @rmdir($this->dir.'/var');
        @rmdir($this->dir);
    }

    public function testTheEndpointIsWhereTheConfigurationSays(): void
    {
        $this->configure('ki/mcp');

        $request = $this->dispatch('POST', '/ki/mcp');

        self::assertSame(McpController::class.'::handle', $request->attributes->get('_controller'));
        self::assertSame('frontend', $request->attributes->get('_scope'));
        self::assertSame('netzhirsch_contao_mcp_controller', $request->attributes->get('_route'));
    }

    public function testThePathIsDecodedBeforeMatchingAsSymfonyDoes(): void
    {
        $this->configure('ki/mcp');

        self::assertTrue($this->dispatch('POST', '/ki/%6Dcp')->attributes->has('_controller'));
    }

    public function testTheOldPathIsLeftToContao(): void
    {
        $this->configure('ki/mcp');

        self::assertFalse($this->dispatch('POST', '/mcp')->attributes->has('_controller'));
        self::assertFalse($this->dispatch('GET', '/mcp/healthz')->attributes->has('_controller'));
    }

    public function testASavedPathAppliesToTheNextRequest(): void
    {
        $this->configure('mcp');
        self::assertTrue($this->dispatch('POST', '/mcp')->attributes->has('_controller'));

        $this->configure('ai');
        self::assertFalse($this->dispatch('POST', '/mcp')->attributes->has('_controller'));
        self::assertSame(McpHealthzController::class, $this->dispatch('GET', '/ai/healthz')->attributes->get('_controller'));
    }

    public function testWithoutAConfigurationTheEndpointStaysAtTheDefault(): void
    {
        // It answers 503 there until something is saved — the controller's job.
        self::assertTrue($this->dispatch('POST', '/mcp')->attributes->has('_controller'));
    }

    public function testAPathThatCouldNotBeSavedDoesNotMoveTheEndpoint(): void
    {
        $this->configure('contao');

        self::assertFalse($this->dispatch('POST', '/contao')->attributes->has('_controller'));
        self::assertTrue($this->dispatch('POST', '/mcp')->attributes->has('_controller'));
    }

    public function testAWrongMethodIsAnswered405WithAllow(): void
    {
        $this->configure('ki/mcp');

        try {
            $this->dispatch('GET', '/ki/mcp');
            self::fail('GET on the endpoint was routed.');
        } catch (MethodNotAllowedHttpException $e) {
            self::assertSame(405, $e->getStatusCode());
            self::assertSame('POST, OPTIONS', $e->getHeaders()['Allow'] ?? null);
        }
    }

    public function testARoutedRequestIsLeftAlone(): void
    {
        $this->configure('mcp');

        $request = Request::create('/mcp', 'POST');
        $request->attributes->set('_controller', 'something_else');
        $this->listener()->onKernelRequest(new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertSame('something_else', $request->attributes->get('_controller'));
    }

    public function testASubRequestIsLeftAlone(): void
    {
        $request = Request::create('/mcp', 'POST');
        $this->listener()->onKernelRequest(new RequestEvent($this->kernel(), $request, HttpKernelInterface::SUB_REQUEST));

        self::assertFalse($request->attributes->has('_controller'));
    }

    private function configure(string $path): void
    {
        file_put_contents($this->dir.'/var/mcp/config.json', json_encode(['path' => $path, 'auth_mode' => 'oauth']));
    }

    private function dispatch(string $method, string $path): Request
    {
        $request = Request::create($path, $method);
        $this->listener()->onKernelRequest(new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST));

        return $request;
    }

    private function listener(): McpEndpointRouteListener
    {
        return new McpEndpointRouteListener(new McpServerConfigStorage($this->dir));
    }

    private function kernel(): HttpKernelInterface
    {
        return $this->createStub(HttpKernelInterface::class);
    }
}
