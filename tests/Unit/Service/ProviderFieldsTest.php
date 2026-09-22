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

    /**
     * A provider whose declared fields span several types — the bootstrap
     * bundle's components are the real case. getDeclaredFields() is the union,
     * getAllowedFields() narrows it per type.
     *
     * @param array<string, list<string>> $allowedByType
     */
    private function typedProvider(string $table, array $allowedByType, ?bool &$applied = null): FieldProvider
    {
        $union = array_values(array_unique(array_merge(...array_values($allowedByType))));

        $p = $this->createMock(FieldProvider::class);
        $p->method('getTable')->willReturn($table);
        $p->method('getDeclaredFields')->willReturn($union);
        $p->method('getAllowedFields')->willReturnCallback(
            static fn (?string $type): array => $allowedByType[(string) $type] ?? [],
        );
        $p->method('isAvailable')->willReturn(true);
        $p->method('getRequiredExtension')->willReturn('netzhirsch/contao-bootstrap-bundle');
        $p->method('serialize')->willReturn([]);
        $p->method('apply')->willReturnCallback(
            static function (Model $m, array $input) use (&$applied, $union): array {
                $applied = true;

                return array_values(array_intersect(array_keys($input), $union));
            },
        );

        return $p;
    }

    /**
     * The gate the contract always promised. Before it existed here, only the
     * page mapper honoured getAllowedFields(); on tl_content every declared
     * field of every provider reached apply() on every type. A provider that
     * trusted the contract instead of re-checking wrote to the wrong record,
     * without an error — reported by the bootstrap bundle, whose fields belong
     * to one component each.
     */
    public function testAFieldOfAnotherTypeIsRefusedBeforeTheProviderIsCalled(): void
    {
        $wasApplied = false;
        $subject = $this->fields($this->typedProvider('tl_content', [
            'netzhirsch_component_card' => ['component_headline'],
            'netzhirsch_component_quote' => ['component_quote'],
        ], $wasApplied));

        $result = $subject->apply(
            'tl_content',
            $this->model(),
            ['component_quote' => 'x'],
            true,
            'netzhirsch_component_card',
        );

        self::assertSame([], $result['applied']);
        self::assertFalse($wasApplied, 'apply() must not be reached for a field of another type');
        self::assertStringContainsString('component_quote', $result['errors'][0]);
        self::assertStringContainsString('netzhirsch_component_card', $result['errors'][0]);
        self::assertStringContainsString('netzhirsch/contao-bootstrap-bundle', $result['errors'][0]);
    }

    public function testAFieldOfTheMatchingTypeStillGoesThrough(): void
    {
        $subject = $this->fields($this->typedProvider('tl_content', [
            'netzhirsch_component_card' => ['component_headline'],
            'netzhirsch_component_quote' => ['component_quote'],
        ]));

        $result = $subject->apply(
            'tl_content',
            $this->model(),
            ['component_headline' => 'x'],
            true,
            'netzhirsch_component_card',
        );

        self::assertSame(['component_headline'], $result['applied']);
        self::assertSame([], $result['errors']);
    }

    /**
     * tl_theme and tl_layout have no type concept, so there is nothing to gate
     * on. Passing no type must not turn into "allowed for type ''".
     */
    public function testWithoutATypeTheGateIsSkipped(): void
    {
        $subject = $this->fields($this->typedProvider('tl_theme', [
            'some_type' => ['netzhirsch_bootstrap_scss'],
        ]));

        $result = $subject->apply('tl_theme', $this->model(), ['netzhirsch_bootstrap_scss' => '$a: 1;']);

        self::assertSame(['netzhirsch_bootstrap_scss'], $result['applied']);
        self::assertSame([], $result['errors']);
    }
}
