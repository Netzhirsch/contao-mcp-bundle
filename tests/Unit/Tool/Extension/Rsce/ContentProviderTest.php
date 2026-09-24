<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Tool\Extension\Rsce;

use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use MadeYourDay\RockSolidCustomElements\CustomElements;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce\ContentProvider;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce\RsceElements;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The v1.33.0 report: an RSCE element could be created through the content
 * tools but not configured — rsce_data was readable and refused on every write.
 * Runs against a stand-in for RSCE's CustomElements, so "installed" and the
 * type's config are under the test's control.
 */
#[CoversClass(ContentProvider::class)]
#[CoversClass(RsceElements::class)]
final class ContentProviderTest extends TestCase
{
    private const TYPE = 'rsce_themoreGrid';

    protected function setUp(): void
    {
        if (!class_exists(CustomElements::class, false)) {
            require_once __DIR__.'/../../../../Fixtures/Rsce/CustomElements.php';
        }

        CustomElements::$lookups = 0;
        CustomElements::$configs = [
            self::TYPE => [
                'standardFields' => ['headline'],
                'fields' => [
                    'grid' => ['label' => ['Grid', ''], 'inputType' => 'select', 'options' => ['grid2Col', 'grid3Col']],
                    'buttonUrl' => ['label' => ['Button URL', ''], 'inputType' => 'url'],
                    'bgColor' => ['label' => ['Background', ''], 'inputType' => 'text'],
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        CustomElements::$configs = [];
    }

    private function rsce(): RsceElements
    {
        return new RsceElements($this->createStub(ContaoFramework::class));
    }

    private function provider(): ContentProvider
    {
        return new ContentProvider($this->rsce());
    }

    /**
     * A ContentModel without Contao behind it: columns live in an array.
     *
     * @param array<string, mixed> $row
     */
    private static function element(array $row): ContentModel
    {
        return new class($row) extends ContentModel {
            /** @param array<string, mixed> $data */
            public function __construct(public array $data)
            {
            }

            public function __get($strKey): mixed
            {
                return $this->data[$strKey] ?? null;
            }

            public function __set($strKey, $varValue): void
            {
                $this->data[$strKey] = $varValue;
            }

            public function __isset($strKey): bool
            {
                return isset($this->data[$strKey]);
            }
        };
    }

    public function testTheColumnBelongsToRsceTypesOnly(): void
    {
        $provider = $this->provider();

        self::assertTrue($provider->isAvailable());
        self::assertSame(['rsce_data'], $provider->getAllowedFields(self::TYPE));
        self::assertSame([], $provider->getAllowedFields('text'));
        self::assertSame('madeyourday/contao-rocksolid-custom-elements', $provider->getRequiredExtension());
    }

    /**
     * Changing the button URL must leave the grid and the colour alone.
     */
    public function testAWriteIsMergedIntoTheStoredSettings(): void
    {
        $element = self::element(['type' => self::TYPE, 'rsce_data' => '{"grid":"grid2Col","bgColor":"#fff"}']);

        $changed = $this->provider()->apply($element, ['rsce_data' => ['buttonUrl' => 'https://example.org', 'bgColor' => null]], true);

        self::assertSame(['rsce_data'], $changed);
        self::assertSame(['grid' => 'grid2Col', 'buttonUrl' => 'https://example.org'], json_decode($element->rsce_data, true));
    }

    public function testTheJsonStringFromTheReportIsAccepted(): void
    {
        $element = self::element(['type' => self::TYPE, 'rsce_data' => null]);

        $this->provider()->apply($element, ['rsce_data' => '{"grid":"grid3Col"}'], false);

        self::assertSame('{"grid":"grid3Col"}', $element->rsce_data);
    }

    public function testAKeyTheTypeDoesNotHaveStopsTheWrite(): void
    {
        $element = self::element(['type' => self::TYPE, 'rsce_data' => '{"grid":"grid2Col"}']);

        try {
            $this->provider()->apply($element, ['rsce_data' => ['buttonURL' => 'x']], true);
            self::fail('A key of another spelling was stored.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"buttonURL" is not a field of rsce_themoreGrid', $e->getMessage());
        }

        self::assertSame('{"grid":"grid2Col"}', $element->rsce_data, 'a refused write leaves the column as it was');
    }

    /**
     * Sending back what content_get returned is not a change — no new version,
     * no "updated: true" for nothing.
     */
    public function testWritingTheStoredValueBackChangesNothing(): void
    {
        $element = self::element(['type' => self::TYPE, 'rsce_data' => '{"grid":"grid2Col"}']);

        self::assertSame([], $this->provider()->apply($element, ['rsce_data' => '{"grid": "grid2Col"}'], true));
    }

    public function testNotOnAnElementOfAnotherType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only exists on RSCE elements');

        $this->provider()->apply(self::element(['type' => 'text']), ['rsce_data' => '{}'], false);
    }

    public function testTheConfigIsLookedUpOncePerType(): void
    {
        $rsce = $this->rsce();

        $rsce->config(self::TYPE);
        $rsce->config(self::TYPE);
        $rsce->config('rsce_unknown');
        $rsce->config('rsce_unknown');

        self::assertSame(2, CustomElements::$lookups);
        self::assertNull($rsce->config('text'), 'not an RSCE type — RSCE is not even asked');
        self::assertSame(2, CustomElements::$lookups);
    }

    public function testThePaletteGetsTheStandardColumnsOfTheType(): void
    {
        $palette = (string) $this->rsce()->paletteFor(self::TYPE, ['fields' => ['headline' => []]]);

        self::assertStringContainsString('headline', $palette);
        self::assertStringContainsString('customTpl', $palette);
        self::assertNull($this->rsce()->paletteFor('rsce_unknown', []));
    }

    public function testTheDescriptionNamesTheKeysAndTheMergeRule(): void
    {
        $described = $this->rsce()->describe(self::TYPE);

        self::assertSame('rsce_data', $described['column']);
        self::assertStringContainsString('MERGED', $described['format']);
        self::assertSame(['grid', 'buttonUrl', 'bgColor'], array_column($described['fields'], 'name'));

        $unreadable = $this->rsce()->describe('rsce_unknown');
        self::assertNull($unreadable['fields']);
        self::assertStringContainsString('could not be read', $unreadable['message']);
    }
}
