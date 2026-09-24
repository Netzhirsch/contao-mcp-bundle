<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Tool\Extension\Rsce;

use Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce\RsceData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The fixture is shaped like the element from the v1.33.0 report: a themore
 * grid with a select, a button list and a background image, plus the entry
 * kinds RSCE configs mix in (a group heading, a standardField).
 */
#[CoversClass(RsceData::class)]
final class RsceDataTest extends TestCase
{
    private const TYPE = 'rsce_themoreGrid';

    /**
     * @return array<string, mixed>
     */
    private static function fields(): array
    {
        return [
            'layout_legend' => 'Layout',
            'grid' => [
                'label' => ['de' => ['Raster', 'Spaltenaufteilung'], 'en' => ['Grid', 'Column layout']],
                'inputType' => 'select',
                'options' => ['grid2Col' => 'Two columns', 'grid3Col' => 'Three columns'],
                'eval' => ['mandatory' => true],
            ],
            'bgColor' => ['label' => ['Background', ''], 'inputType' => 'text'],
            'fullWidth' => ['label' => ['Full width', ''], 'inputType' => 'checkbox'],
            'visibleOn' => [
                'label' => ['Visible on', ''],
                'inputType' => 'checkbox',
                'options' => ['mobile', 'desktop'],
                'eval' => ['multiple' => true],
            ],
            'bgImage' => ['label' => ['Image', ''], 'inputType' => 'fileTree', 'eval' => ['filesOnly' => true]],
            'gallery' => ['label' => ['Gallery', ''], 'inputType' => 'fileTree', 'eval' => ['multiple' => true]],
            'showFrom' => ['label' => ['Show from', ''], 'inputType' => 'text', 'eval' => ['rgxp' => 'date']],
            'buttons' => [
                'label' => ['Buttons', ''],
                'inputType' => 'list',
                'maxItems' => 3,
                'fields' => [
                    'text' => ['label' => ['Text', ''], 'inputType' => 'text'],
                    'url' => ['label' => ['URL', ''], 'inputType' => 'url'],
                ],
            ],
            'headline' => ['inputType' => 'standardField'],
            'spacing_group' => ['label' => ['Spacing', ''], 'inputType' => 'group'],
        ];
    }

    private static function convert(array $patch, array $stored = []): array
    {
        return RsceData::convert($patch, self::fields(), $stored, self::TYPE);
    }

    public function testAJsonStringAndADecodedObjectReadTheSame(): void
    {
        self::assertSame(['grid' => 'grid3Col'], RsceData::parse('{"grid":"grid3Col"}'));
        self::assertSame(['grid' => 'grid3Col'], RsceData::parse(['grid' => 'grid3Col']));
        self::assertSame(['grid' => 'grid3Col'], RsceData::parse((object) ['grid' => 'grid3Col']));
        self::assertSame([], RsceData::parse('{}'));
    }

    /**
     * The request from the report: checked as JSON BEFORE anything is saved.
     */
    public function testInvalidJsonIsRefusedWithTheParserMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rsce_data is not valid JSON');

        RsceData::parse('{"grid": "grid3Col"');
    }

    public function testAListOrAScalarIsNotAnObject(): void
    {
        foreach (['["grid3Col"]', '"grid3Col"', ['a', 'b'], 42] as $input) {
            try {
                RsceData::parse($input);
                self::fail('Accepted '.json_encode($input));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('must be a JSON object', $e->getMessage());
            }
        }
    }

    public function testAnEmptyStringIsNotTakenForEmptyData(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RsceData::parse('   ');
    }

    /**
     * The quiet failure the key check exists for: a typo stored, reported as
     * applied, never rendered.
     */
    public function testAnUnknownKeyIsRefusedAndTheRealOnesAreNamed(): void
    {
        try {
            self::convert(['gird' => 'grid3Col']);
            self::fail('A key the type does not have was accepted.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"gird" is not a field of rsce_themoreGrid', $e->getMessage());
            self::assertStringContainsString('grid, bgColor', $e->getMessage());
            self::assertStringContainsString('content_palette_get("rsce_themoreGrid")', $e->getMessage());
            self::assertStringNotContainsString('layout_legend', $e->getMessage(), 'a group heading is no field');
        }
    }

    /**
     * Read → change → write back must survive a field the config no longer
     * knows.
     */
    public function testAKeyThatIsAlreadyStoredPassesEvenWhenTheConfigDropped(): void
    {
        self::assertSame(['legacyKey' => 'x'], self::convert(['legacyKey' => 'x'], ['legacyKey' => 'old']));
    }

    public function testAStandardFieldIsSentNextToRsceDataNotInside(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"headline" is a regular column of rsce_themoreGrid');

        self::convert(['headline' => 'x']);
    }

    public function testAGroupHeadingHoldsNoValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('group heading');

        self::convert(['spacing_group' => 'x']);
    }

    /**
     * What the backend widgets would have written for the same input.
     */
    public function testValuesAreStoredTheWayTheBackendStoresThem(): void
    {
        $converted = self::convert([
            'fullWidth' => true,
            'visibleOn' => ['desktop', 'mobile'],
            'bgImage' => 'A1B2C3D4E5F60718293A4B5C6D7E8F90',
            'gallery' => ['a1b2c3d4-e5f6-0718-293a-4b5c6d7e8f90', '0123456789abcdef0123456789abcdef'],
            'bgColor' => 42,
        ]);

        self::assertSame('1', $converted['fullWidth']);
        self::assertSame(serialize(['desktop', 'mobile']), $converted['visibleOn']);
        self::assertSame('a1b2c3d4-e5f6-0718-293a-4b5c6d7e8f90', $converted['bgImage']);
        self::assertSame(
            serialize(['a1b2c3d4-e5f6-0718-293a-4b5c6d7e8f90', '01234567-89ab-cdef-0123-456789abcdef']),
            $converted['gallery'],
        );
        self::assertSame('42', $converted['bgColor']);

        self::assertSame('', self::convert(['fullWidth' => false])['fullWidth']);
    }

    public function testAStoredSerialisedFileListGoesBackUnchanged(): void
    {
        $stored = serialize(['a1b2c3d4-e5f6-0718-293a-4b5c6d7e8f90']);

        self::assertSame($stored, self::convert(['gallery' => $stored])['gallery']);
    }

    public function testSomethingThatIsNoUuidIsRefusedForAFileField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"bgImage" expects a file UUID');

        self::convert(['bgImage' => 'files/theme/bg.jpg']);
    }

    public function testADateFieldTakesIso8601(): void
    {
        self::assertSame((string) strtotime('2026-09-24'), self::convert(['showFrom' => '2026-09-24'])['showFrom']);
        self::assertSame('1790000000', self::convert(['showFrom' => '1790000000'])['showFrom']);
    }

    public function testListItemsAreCheckedWithTheirOwnFields(): void
    {
        $converted = self::convert(['buttons' => [
            ['text' => 'Kontakt', 'url' => '{{link_url::12}}'],
            ['text' => 'Mehr', 'url' => null],
        ]]);

        self::assertSame([['text' => 'Kontakt', 'url' => '{{link_url::12}}'], ['text' => 'Mehr']], $converted['buttons']);

        try {
            self::convert(['buttons' => [['text' => 'ok'], ['txt' => 'typo']]]);
            self::fail('A typo inside a list item was accepted.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"buttons[1].txt" is not a field', $e->getMessage());
            self::assertStringContainsString('text, url', $e->getMessage());
        }
    }

    public function testAListMustBeAListAndRespectsMaxItems(): void
    {
        try {
            self::convert(['buttons' => ['text' => 'not a list']]);
            self::fail('An object was accepted for a list.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('is a list', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 3 items, got 4');

        self::convert(['buttons' => [['text' => 'a'], ['text' => 'b'], ['text' => 'c'], ['text' => 'd']]]);
    }

    /**
     * Without a readable config there are no rules to apply — the column is
     * still writable, as sent.
     */
    public function testWithoutAConfigTheValuesPassAsSent(): void
    {
        self::assertSame(
            ['anything' => ['nested' => true]],
            RsceData::convert(['anything' => ['nested' => true]], null, [], self::TYPE),
        );
    }

    /**
     * The headline-tuple lesson: changing one setting must not reset the others.
     */
    public function testAWriteIsMergedIntoWhatIsStored(): void
    {
        $stored = ['grid' => 'grid2Col', 'bgColor' => '#fff', 'buttons' => [['text' => 'a']]];

        self::assertSame(
            ['grid' => 'grid3Col', 'buttons' => [['text' => 'b']]],
            RsceData::merge($stored, ['grid' => 'grid3Col', 'bgColor' => null, 'buttons' => [['text' => 'b']]]),
        );
    }

    public function testEncodingMatchesRsceItself(): void
    {
        self::assertSame('{}', RsceData::encode([]));
        // json_encode's defaults, as RSCE's saveDataCallback writes them:
        // escaped slashes and escaped non-ASCII.
        $json = RsceData::encode(['url' => 'https://example.org', 'text' => 'Grüße']);
        self::assertSame(json_encode(['url' => 'https://example.org', 'text' => 'Grüße']), $json);
        self::assertStringContainsString('https:\/\/example.org', $json);
        self::assertStringNotContainsString('ü', $json);
    }

    public function testAnUnreadableColumnDecodesToNothing(): void
    {
        self::assertSame([], RsceData::decode(null));
        self::assertSame([], RsceData::decode(''));
        self::assertSame([], RsceData::decode('[1,2]'));
        self::assertSame([], RsceData::decode('{broken'));
        self::assertSame(['grid' => 'grid2Col'], RsceData::decode('{"grid":"grid2Col"}'));
    }

    public function testTheDescriptionListsTheDataFieldsWithWhatTheyTake(): void
    {
        $GLOBALS['TL_LANGUAGE'] = 'de';

        try {
            $described = RsceData::describe(self::fields());
        } finally {
            unset($GLOBALS['TL_LANGUAGE']);
        }

        $byName = array_column($described, null, 'name');

        self::assertSame(
            ['grid', 'bgColor', 'fullWidth', 'visibleOn', 'bgImage', 'gallery', 'showFrom', 'buttons'],
            array_keys($byName),
            'group headings and standardField entries hold no rsce_data value',
        );

        self::assertSame('Raster', $byName['grid']['label']);
        self::assertSame('Spaltenaufteilung', $byName['grid']['description']);
        self::assertTrue($byName['grid']['mandatory']);
        self::assertSame(
            [['value' => 'grid2Col', 'label' => 'Two columns'], ['value' => 'grid3Col', 'label' => 'Three columns']],
            $byName['grid']['options'],
        );
        self::assertSame([['value' => 'mobile'], ['value' => 'desktop']], $byName['visibleOn']['options']);
        self::assertTrue($byName['visibleOn']['multiple']);
        self::assertStringContainsString('file UUID', $byName['bgImage']['value']);
        self::assertStringContainsString('list of file UUIDs', $byName['gallery']['value']);
        self::assertSame(3, $byName['buttons']['max_items']);
        self::assertSame(['text', 'url'], array_column($byName['buttons']['fields'], 'name'));
    }
}
