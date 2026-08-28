<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service;

use Netzhirsch\ContaoMcpBundle\Server\RegistryAccessor;
use Netzhirsch\ContaoMcpBundle\Service\UnknownArgumentGuard;
use PhpMcp\Schema\Tool;
use PhpMcp\Server\Registry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A parameter the tool does not have used to vanish without a word: the
 * generated schema sets no `additionalProperties`, so validation waved it
 * through, and `prepareArguments()` then read only the parameters the method
 * declares. `page_update(id: 7, pageTitel: "…")` — one transposed letter —
 * answered with the page summary and `applied: 0`.
 */
#[CoversClass(UnknownArgumentGuard::class)]
final class UnknownArgumentGuardTest extends TestCase
{
    private UnknownArgumentGuard $guard;

    protected function setUp(): void
    {
        $registry = new Registry(new NullLogger());
        $registry->registerTool(
            Tool::make('page_update', [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'pageTitle' => ['type' => 'string'],
                    'sorting' => ['type' => 'integer'],
                    'canonicalKeepParams' => ['type' => 'string'],
                ],
                'required' => ['id'],
            ], 'demo'),
            handler: 'Some\Handler',
            isManual: true,
        );
        $registry->registerTool(
            Tool::make('ping', ['type' => 'object', 'properties' => [], 'required' => []], 'demo'),
            handler: 'Some\Handler',
            isManual: true,
        );

        $accessor = new RegistryAccessor();
        $accessor->set($registry);

        $this->guard = new UnknownArgumentGuard($accessor);
    }

    public function testAcceptsKnownParameters(): void
    {
        self::assertNull($this->guard->check('page_update', ['id' => 7, 'pageTitle' => 'x']));
    }

    public function testAcceptsAnEmptyCall(): void
    {
        self::assertNull($this->guard->check('page_update', []));
    }

    public function testRefusesAnUnknownParameter(): void
    {
        $error = $this->guard->check('page_update', ['id' => 7, 'voelligErfunden' => 'x']);

        self::assertNotNull($error);
        self::assertSame('invalid_input', $error['error']);
        self::assertSame(['voelligErfunden'], $error['unknown_parameters']);
        self::assertStringContainsString('Nothing was changed', $error['message']);
    }

    public function testSuggestsTheIntendedParameterOnATypo(): void
    {
        $error = $this->guard->check('page_update', ['id' => 7, 'pageTitel' => 'x']);

        self::assertNotNull($error);
        self::assertStringContainsString('did you mean "pageTitle"', $error['message']);
    }

    public function testSuggestsNothingWhenNothingIsClose(): void
    {
        $error = $this->guard->check('page_update', ['id' => 7, 'zzzzzzzzzzzzzz' => 'x']);

        self::assertNotNull($error);
        self::assertStringNotContainsString('did you mean', $error['message']);
    }

    public function testReportsEveryUnknownParameterAtOnce(): void
    {
        $error = $this->guard->check('page_update', ['pageTitel' => 'x', 'sortierung' => 1]);

        self::assertNotNull($error);
        self::assertSame(['pageTitel', 'sortierung'], $error['unknown_parameters']);
    }

    /**
     * A tool that declares no parameters accepts none — `ping` used to swallow
     * anything it was handed.
     */
    public function testRefusesArgumentsForAParameterlessTool(): void
    {
        $error = $this->guard->check('ping', ['unerwartet' => 1]);

        self::assertNotNull($error);
        self::assertSame(['unerwartet'], $error['unknown_parameters']);
    }

    /**
     * An unknown TOOL is not this guard's business — the dispatcher's own
     * "tool not found" is the better answer and comes right after.
     */
    public function testIgnoresAnUnknownTool(): void
    {
        self::assertNull($this->guard->check('gibt_es_nicht', ['x' => 1]));
    }

    /**
     * A `fields` object legitimately carries arbitrary DCA column names, and
     * the owning tool validates them properly. Only the top level is checked.
     */
    public function testDoesNotDescendIntoObjectParameters(): void
    {
        $registry = new Registry(new NullLogger());
        $registry->registerTool(
            Tool::make('content_update', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer'], 'fields' => ['type' => 'object']],
                'required' => ['id'],
            ], 'demo'),
            handler: 'Some\Handler',
            isManual: true,
        );
        $accessor = new RegistryAccessor();
        $accessor->set($registry);
        $guard = new UnknownArgumentGuard($accessor);

        self::assertNull($guard->check('content_update', [
            'id' => 1,
            'fields' => ['irgendeinDcaFeld' => 'wert', 'nochEins' => 2],
        ]));
    }
}
