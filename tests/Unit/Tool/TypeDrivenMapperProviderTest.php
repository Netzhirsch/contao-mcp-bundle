<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Tool;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use MadeYourDay\RockSolidCustomElements\CustomElements;
use Netzhirsch\ContaoMcpBundle\Service\FieldProviderRegistry;
use Netzhirsch\ContaoMcpBundle\Service\ProviderFields;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce\FormFieldProvider;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce\ModuleProvider;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce\RsceElements;
use Netzhirsch\ContaoMcpBundle\Tool\FormField\FieldMapper as FormFieldMapper;
use Netzhirsch\ContaoMcpBundle\Tool\Module\FieldMapper as ModuleMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * RSCE registers every element as content element, frontend module and form
 * field unless its config restricts `types`, and keeps rsce_data on all three
 * tables. The module and form-field mappers knew no extension columns at all, so
 * an RSCE module met the wall from the report one table over.
 */
#[CoversClass(ModuleMapper::class)]
#[CoversClass(FormFieldMapper::class)]
final class TypeDrivenMapperProviderTest extends TestCase
{
    private const TYPE = 'rsce_themoreTeaser';

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        if (!class_exists(CustomElements::class, false)) {
            require_once __DIR__.'/../../Fixtures/Rsce/CustomElements.php';
        }

        CustomElements::$configs = [
            self::TYPE => [
                'standardFields' => ['headline'],
                'fields' => [
                    'teaser' => ['inputType' => 'textarea'],
                    'name' => ['inputType' => 'standardField'],
                    'label' => ['inputType' => 'standardField'],
                ],
            ],
        ];

        foreach (['TL_DCA', 'FE_MOD', 'TL_FFL'] as $global) {
            $this->saved[$global] = $GLOBALS[$global] ?? null;
        }

        $GLOBALS['TL_DCA']['tl_module'] = [
            'palettes' => ['html' => '{title_legend},name,type;{html_legend},html'],
            'fields' => array_fill_keys(['name', 'type', 'headline', 'html', 'customTpl', 'protected', 'guests', 'cssID', 'rsce_data'], []),
        ];
        $GLOBALS['TL_DCA']['tl_form_field'] = [
            'palettes' => ['text' => '{type_legend},type,name,label;{fconfig_legend},mandatory'],
            'fields' => array_fill_keys(['type', 'name', 'label', 'mandatory', 'class', 'customTpl', 'rsce_data'], []),
        ];
        $GLOBALS['FE_MOD'] = ['miscellaneous' => ['html' => 'x'], 'custom_elements' => [self::TYPE => 'x']];
        $GLOBALS['TL_FFL'] = ['text' => 'x', self::TYPE => 'x'];
    }

    protected function tearDown(): void
    {
        CustomElements::$configs = [];

        foreach ($this->saved as $global => $value) {
            if ($value === null) {
                unset($GLOBALS[$global]);
            } else {
                $GLOBALS[$global] = $value;
            }
        }
    }

    /**
     * @return array{ContaoFramework, RsceElements}
     */
    private function contao(): array
    {
        $adapter = $this->createStub(Adapter::class);
        $adapter->method('__call')->willReturn(null);

        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($adapter);

        return [$framework, new RsceElements($framework)];
    }

    private function moduleMapper(): ModuleMapper
    {
        [$framework, $rsce] = $this->contao();

        return new ModuleMapper($framework, new ProviderFields(new FieldProviderRegistry([new ModuleProvider($rsce)])), $rsce);
    }

    private function formFieldMapper(): FormFieldMapper
    {
        [$framework, $rsce] = $this->contao();

        return new FormFieldMapper($framework, new ProviderFields(new FieldProviderRegistry([new FormFieldProvider($rsce)])), $rsce);
    }

    public function testAnRsceModuleTakesRsceDataAndTheColumnsItsFormShows(): void
    {
        $mapper = $this->moduleMapper();

        self::assertContains('rsce_data', $mapper->allowedFieldsFor(self::TYPE));
        self::assertContains('name', $mapper->allowedFieldsFor(self::TYPE));
        self::assertSame([], $mapper->rejectedFields(self::TYPE, ['rsce_data', 'headline', 'customTpl']));
    }

    public function testAnOrdinaryModuleIsNeitherOfferedNorGivenRsceData(): void
    {
        $mapper = $this->moduleMapper();

        self::assertNotContains('rsce_data', $mapper->allowedFieldsFor('html'));

        $rejected = $mapper->rejectedFields('html', ['nope', 'rsce_data']);
        self::assertSame(['nope', 'rsce_data'], array_keys($rejected), 'core refusals first');
        self::assertStringContainsString('not valid for module type "html"', $rejected['nope']);
        self::assertStringContainsString('not valid for type "html"', $rejected['rsce_data']);
    }

    /**
     * RSCE form-field configs bring name and label as standardField entries —
     * the regular columns their form shows.
     */
    public function testAnRsceFormFieldTakesRsceDataAndItsStandardFields(): void
    {
        $mapper = $this->formFieldMapper();

        self::assertSame([], $mapper->rejectedFields(self::TYPE, ['rsce_data', 'name', 'label', 'class']));
        self::assertNotContains('rsce_data', $mapper->allowedFieldsFor('text'));
        self::assertStringContainsString(
            'not valid for type "text"',
            $mapper->rejectedFields('text', ['rsce_data'])['rsce_data'] ?? '',
        );
    }
}
