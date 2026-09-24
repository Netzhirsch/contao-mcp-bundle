<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Tool\Extension\Rsce;

use Netzhirsch\ContaoMcpBundle\Service\DcaPalette;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce\RscePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * An RSCE type has no palette until the edit mask opens. What the backend form
 * would show has to be rebuilt from the config — and nothing more than that,
 * or the write path offers columns the form never shows.
 */
#[CoversClass(RscePalette::class)]
final class RscePaletteTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function dca(bool $withTitle = true): array
    {
        $fields = [
            'type' => [], 'headline' => [], 'text' => [], 'addImage' => [], 'customTpl' => [],
            'protected' => [], 'guests' => [], 'cssID' => [], 'invisible' => [], 'start' => [],
            'stop' => [], 'singleSRC' => [], 'rs_columns_large' => [], 'rs_columns_medium' => [],
        ];
        if ($withTitle) {
            $fields['title'] = [];
        }

        return [
            'fields' => $fields,
            'palettes' => [
                'rs_columns_start' => '{type_legend},type;{rs_columns_legend},rs_columns_large,rs_columns_medium;{template_legend:hide},customTpl',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private static function fields(array $config, bool $withTitle = true, bool $slider = false): array
    {
        return DcaPalette::extractFields(RscePalette::build($config, self::dca($withTitle), $slider));
    }

    public function testTheColumnsEveryRsceFormShows(): void
    {
        self::assertSame(
            ['type', 'title', 'customTpl', 'protected', 'guests', 'invisible', 'start', 'stop'],
            self::fields(['fields' => []]),
        );
    }

    /**
     * RSCE adds the element title from Contao 5.6 on. The DCA says whether it
     * exists; no version check needed.
     */
    public function testTheTitleOnlyWhereContaoHasIt(): void
    {
        self::assertNotContains('title', self::fields(['fields' => []], withTitle: false));
    }

    public function testStandardFieldsBecomeTheirColumns(): void
    {
        $fields = self::fields(['standardFields' => ['headline', 'text', 'image', 'cssID', 'columns'], 'fields' => []]);

        foreach (['headline', 'text', 'addImage', 'cssID', 'rs_columns_large', 'rs_columns_medium'] as $expected) {
            self::assertContains($expected, $fields);
        }
    }

    public function testAStandardFieldEntryIsItsColumnIfTheColumnExists(): void
    {
        $fields = self::fields(['fields' => [
            'singleSRC' => ['inputType' => 'standardField'],
            'doesNotExist' => ['inputType' => 'standardField'],
            'grid' => ['inputType' => 'select'],
        ]]);

        self::assertContains('singleSRC', $fields);
        self::assertNotContains('doesNotExist', $fields);
        self::assertNotContains('grid', $fields, 'a virtual field is no column — its value lives in rsce_data');
        self::assertNotContains('rsce_data', $fields, 'written by its FieldProvider, not through the palette');
    }

    public function testSliderFieldsOnlyWithTheSliderInstalled(): void
    {
        $config = ['standardFields' => ['slider'], 'fields' => []];

        self::assertNotContains('rsce_slider', self::fields($config));
        self::assertContains('rsce_slider', self::fields($config, slider: true));
    }
}
