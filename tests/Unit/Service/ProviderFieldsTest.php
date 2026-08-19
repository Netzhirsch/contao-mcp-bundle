<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service;

use Contao\Model;
use Netzhirsch\ContaoMcpBundle\Service\FieldProviderRegistry;
use Netzhirsch\ContaoMcpBundle\Service\ProviderFields;
use Netzhirsch\ContaoMcpBundle\Tool\Contract\FieldProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Extension-owned columns are the difference between "the instance can be
 * built by an agent" and "someone opens the backend and pastes SCSS".
 *
 * The failure that matters most here is the quiet one: a provider that
 * rejects a value must stop the write, not let the tool report success on a
 * row that never received it.
 */
#[CoversClass(ProviderFields::class)]
final class ProviderFieldsTest extends TestCase
{
    /**
     * @param list<string> $declared
     */
    private function provider(
        string $table,
        array $declared,
        bool $available = true,
        ?\Throwable $throwsOnApply = null,
        array $serialised = [],
    ): FieldProvider {
        $p = $this->createMock(FieldProvider::class);
        $p->method('getTable')->willReturn($table);
        $p->method('getDeclaredFields')->willReturn($declared);
        $p->method('getAllowedFields')->willReturn($declared);
        $p->method('isAvailable')->willReturn($available);
        $p->method('getRequiredExtension')->willReturn('netzhirsch/contao-bootstrap-bundle');
        $p->method('serialize')->willReturn($serialised);

        if (null !== $throwsOnApply) {
            $p->method('apply')->willThrowException($throwsOnApply);
        } else {
            $p->method('apply')->willReturn($declared);
        }

        return $p;
    }

    private function fields(FieldProvider ...$providers): ProviderFields
    {
        return new ProviderFields(new FieldProviderRegistry($providers));
    }

    private function model(): Model
    {
        return $this->createStub(Model::class);
    }

    public function testDeclaredFieldsAreCollectedPerTable(): void
    {
        $subject = $this->fields(
            $this->provider('tl_theme', ['netzhirsch_bootstrap_mode', 'netzhirsch_bootstrap_scss']),
            $this->provider('tl_article', ['something_else']),
        );

        self::assertSame(
            ['netzhirsch_bootstrap_mode', 'netzhirsch_bootstrap_scss'],
            $subject->declaredFor('tl_theme'),
        );
        self::assertSame([], $subject->declaredFor('tl_page'));
    }

    public function testValuesAreWrittenAndReported(): void
    {
        $subject = $this->fields($this->provider('tl_theme', ['netzhirsch_bootstrap_mode']));

        $result = $subject->apply('tl_theme', $this->model(), ['netzhirsch_bootstrap_mode' => 'compile']);

        self::assertSame(['netzhirsch_bootstrap_mode'], $result['applied']);
        self::assertSame([], $result['errors']);
    }

    /**
     * A provider only runs when the input actually mentions one of its fields
     * — otherwise every theme write would invoke every extension.
     */
    public function testAProviderIsSkippedWhenNothingClaimsIt(): void
    {
        $subject = $this->fields($this->provider('tl_theme', ['netzhirsch_bootstrap_mode']));

        $result = $subject->apply('tl_theme', $this->model(), ['name' => 'Theme']);

        self::assertSame([], $result['applied']);
        self::assertSame([], $result['errors']);
    }

    /**
     * The acceptance case from the briefing: invalid SCSS must fail the call.
     * The provider throws, and nothing may be reported as applied — the Tool
     * layer refuses to save when errors came back.
     */
    public function testARejectedValueBecomesAnErrorAndAppliesNothing(): void
    {
        $subject = $this->fields($this->provider(
            'tl_theme',
            ['netzhirsch_bootstrap_scss'],
            throwsOnApply: new \InvalidArgumentException('SCSS: unexpected token at "$primary: ("'),
        ));

        $result = $subject->apply('tl_theme', $this->model(), ['netzhirsch_bootstrap_scss' => '$primary: (']);

        self::assertSame([], $result['applied']);
        self::assertSame(['SCSS: unexpected token at "$primary: ("'], $result['errors']);
    }

    /**
     * Writing a field whose extension was removed must name the extension.
     * "Unknown field" would send the caller looking for a typo.
     */
    public function testAnUnavailableExtensionIsNamed(): void
    {
        $subject = $this->fields($this->provider('tl_theme', ['netzhirsch_bootstrap_mode'], available: false));

        $result = $subject->apply('tl_theme', $this->model(), ['netzhirsch_bootstrap_mode' => 'compile']);

        self::assertSame([], $result['applied']);
        self::assertStringContainsString('netzhirsch/contao-bootstrap-bundle', $result['errors'][0]);
        self::assertStringContainsString('netzhirsch_bootstrap_mode', $result['errors'][0]);
    }

    public function testOnlyAvailableProvidersAreRead(): void
    {
        $subject = $this->fields(
            $this->provider('tl_theme', ['a'], serialised: ['a' => 1]),
            $this->provider('tl_theme', ['b'], available: false, serialised: ['b' => 2]),
        );

        self::assertSame(['a' => 1], $subject->serialize('tl_theme', $this->model()));
    }
}
