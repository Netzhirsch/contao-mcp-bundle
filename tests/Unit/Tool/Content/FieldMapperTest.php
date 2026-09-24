<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Tool\Content;

use Contao\ContentModel;
use Contao\Controller;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use MadeYourDay\RockSolidCustomElements\CustomElements;
use Netzhirsch\ContaoMcpBundle\Service\FieldProviderRegistry;
use Netzhirsch\ContaoMcpBundle\Service\ProviderFields;
use Netzhirsch\ContaoMcpBundle\Tool\Content\FieldMapper;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce\ContentProvider;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce\RsceElements;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Which fields an element may be written with — decided by its type, by what
 * its PARENT adds, and by the extensions that own columns. The two cases from
 * the v1.33.0 report: sectionHeadline on the children of an accordion, and
 * rsce_data on RSCE elements. Both were refused on the elements that need them.
 *
 * The DCA is a trimmed Contao 5.7 tl_content; RSCE is the test stand-in.
 */
#[CoversClass(FieldMapper::class)]
final class FieldMapperTest extends TestCase
{
    private const RSCE_TYPE = 'rsce_themoreGrid';

    /** @var array<int, string> id => type of the tl_content rows that exist */
    private array $elements = [10 => 'accordion', 11 => 'element_group'];

    private mixed $savedDca = null;

    private mixed $savedCte = null;

    protected function setUp(): void
    {
        if (!class_exists(CustomElements::class, false)) {
            require_once __DIR__.'/../../../Fixtures/Rsce/CustomElements.php';
        }

        CustomElements::$configs = [
            self::RSCE_TYPE => [
                'standardFields' => ['headline', 'image'],
                'fields' => ['grid' => ['inputType' => 'select', 'options' => ['grid2Col', 'grid3Col']]],
            ],
        ];

        $this->savedDca = $GLOBALS['TL_DCA']['tl_content'] ?? null;
        $this->savedCte = $GLOBALS['TL_CTE'] ?? null;

        $GLOBALS['TL_DCA']['tl_content'] = self::dca();
        $GLOBALS['TL_CTE'] = [
            'texts' => ['text' => 'x', 'headline' => 'x'],
            'miscellaneous' => ['accordion' => 'x', 'element_group' => 'x', 'dynamic_one' => 'x'],
            'custom_elements' => [self::RSCE_TYPE => 'x'],
        ];
    }

    protected function tearDown(): void
    {
        CustomElements::$configs = [];

        if ($this->savedDca === null) {
            unset($GLOBALS['TL_DCA']['tl_content']);
        } else {
            $GLOBALS['TL_DCA']['tl_content'] = $this->savedDca;
        }

        if ($this->savedCte === null) {
            unset($GLOBALS['TL_CTE']);
        } else {
            $GLOBALS['TL_CTE'] = $this->savedCte;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function dca(bool $withSectionHeadline = true): array
    {
        $fields = array_fill_keys([
            'type', 'title', 'headline', 'text', 'addImage', 'singleSRC', 'size', 'customTpl',
            'protected', 'groups', 'guests', 'cssID', 'invisible', 'start', 'stop', 'closeSections', 'rsce_data',
        ], []);

        if ($withSectionHeadline) {
            $fields['sectionHeadline'] = ['inputType' => 'inputUnit'];
        }

        return [
            'palettes' => [
                '__selector__' => ['type', 'addImage', 'protected'],
                'text' => '{type_legend},title,type,headline;{text_legend},text,addImage;{template_legend:hide},customTpl',
                'accordion' => '{type_legend},title,type;{accordion_legend},closeSections',
                'element_group' => '{type_legend},title,type,headline',
            ],
            'subpalettes' => ['addImage' => 'singleSRC,size', 'protected' => 'groups'],
            'fields' => $fields,
        ];
    }

    private function mapper(): FieldMapper
    {
        $controller = $this->createStub(Adapter::class);
        $controller->method('__call')->willReturn(null);

        $models = $this->createStub(Adapter::class);
        $models->method('__call')->willReturnCallback(function (string $method, array $arguments): ?ContentModel {
            $type = $this->elements[(int) ($arguments[0] ?? 0)] ?? null;

            return $method === 'findByPk' && $type !== null ? self::element($type) : null;
        });

        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(
            static fn (string $class): Adapter => $class === ContentModel::class ? $models : $controller,
        );

        $rsce = new RsceElements($framework);

        return new FieldMapper(
            $framework,
            new ProviderFields(new FieldProviderRegistry([new ContentProvider($rsce)])),
            $rsce,
        );
    }

    private static function element(string $type): ContentModel
    {
        return new class($type) extends ContentModel {
            public function __construct(private readonly string $elementType)
            {
            }

            public function __get($strKey): mixed
            {
                return $strKey === 'type' ? $this->elementType : null;
            }
        };
    }

    public function testTheParentOfAnElementIsOnlyKnownForNestedElements(): void
    {
        $mapper = $this->mapper();

        self::assertSame('accordion', $mapper->parentTypeOf('tl_content', 10));
        self::assertNull($mapper->parentTypeOf('tl_content', 999), 'a parent that does not exist');
        self::assertNull($mapper->parentTypeOf('tl_article', 10), 'an article is no element');
    }

    /**
     * The report: `Field "sectionHeadline" is not valid for content type "text"`
     * — on a child of an accordion, where the backend form shows it.
     */
    public function testSectionHeadlineIsWritableInsideAnAccordion(): void
    {
        $mapper = $this->mapper();

        self::assertContains('sectionHeadline', $mapper->allowedFieldsFor('text', 'accordion'));
        self::assertSame([], $mapper->rejectedFields('text', ['text', 'sectionHeadline'], 'accordion'));
    }

    /**
     * …and nowhere else, with a refusal that says where it would be valid.
     */
    public function testSectionHeadlineOutsideAnAccordionNamesTheAccordion(): void
    {
        $mapper = $this->mapper();

        foreach ([null, 'element_group'] as $parentType) {
            self::assertNotContains('sectionHeadline', $mapper->allowedFieldsFor('text', $parentType));

            $rejected = $mapper->rejectedFields('text', ['sectionHeadline'], $parentType);
            self::assertStringContainsString('only exists on an element inside an element of type "accordion"', $rejected['sectionHeadline'] ?? '');
            self::assertStringContainsString('ptable "tl_content"', $rejected['sectionHeadline'] ?? '');
        }

        self::assertSame(['sectionHeadline' => 'accordion'], $mapper->contextFields());
    }

    /**
     * Contao versions without the field must not be offered it.
     */
    public function testNoContextFieldWhereContaoDoesNotHaveIt(): void
    {
        $GLOBALS['TL_DCA']['tl_content'] = self::dca(withSectionHeadline: false);
        $mapper = $this->mapper();

        self::assertSame([], $mapper->contextFields());
        self::assertNotContains('sectionHeadline', $mapper->allowedFieldsFor('text', 'accordion'));
        self::assertStringContainsString(
            'is not valid for content type "text"',
            $mapper->rejectedFields('text', ['sectionHeadline'], 'accordion')['sectionHeadline'] ?? '',
        );
    }

    /**
     * The RSCE form shows the columns its config asks for; without the edit
     * mask there was no palette at all, so they were refused as well.
     */
    public function testAnRsceTypeGetsThePaletteItsFormShows(): void
    {
        $mapper = $this->mapper();
        $allowed = $mapper->allowedFieldsFor(self::RSCE_TYPE);

        self::assertTrue($mapper->hasPalette(self::RSCE_TYPE));
        foreach (['headline', 'addImage', 'singleSRC', 'customTpl', 'rsce_data'] as $field) {
            self::assertContains($field, $allowed);
        }
        self::assertNotContains('text', $allowed, 'not in this config\'s standardFields');
        self::assertSame([], $mapper->rejectedFields(self::RSCE_TYPE, ['rsce_data', 'headline']));
    }

    /**
     * rsce_data is an RSCE column; offering it on a text element would be as
     * wrong as refusing it on an RSCE element was.
     */
    public function testRsceDataIsNeitherOfferedNorAcceptedOnOtherTypes(): void
    {
        $mapper = $this->mapper();

        self::assertNotContains('rsce_data', $mapper->allowedFieldsFor('text'));
        self::assertStringContainsString(
            'not valid for type "text"',
            $mapper->rejectedFields('text', ['rsce_data'])['rsce_data'] ?? '',
        );
    }

    public function testATypeWithoutAReadablePaletteSaysSo(): void
    {
        $mapper = $this->mapper();

        self::assertFalse($mapper->hasPalette('dynamic_one'));
        self::assertStringContainsString(
            'no static palette',
            $mapper->rejectedFields('dynamic_one', ['whatever'])['whatever'] ?? '',
        );
    }

    public function testCoreRefusalsComeBeforeExtensionRefusals(): void
    {
        self::assertSame(
            ['nope', 'rsce_data'],
            array_keys($this->mapper()->rejectedFields('text', ['rsce_data', 'nope', 'text'])),
        );
    }
}
