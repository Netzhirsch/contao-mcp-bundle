<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service;

use Netzhirsch\ContaoMcpBundle\Service\DcaPalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The fixture is the real AL-07 case, trimmed: two module types of
 * netzhirsch/contao-bootstrap-bundle plus the core login sub-palettes. It is
 * the shape that made a `netzhirsch_megamenu` module report seven offcanvas
 * fields it does not have, next to `reg_homeDir` from the login module.
 */
#[CoversClass(DcaPalette::class)]
final class DcaPaletteTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function dca(): array
    {
        return [
            'palettes' => [
                '__selector__' => ['type', 'defineRoot', 'protected', 'reg_assignDir', 'netzhirsch_nav_offcanvas'],
                'netzhirsch_megamenu' => '{title_legend},name,headline,type;{nav_legend},rootPage,showProtected;{template_legend:hide},netzhirsch_megamenu_styling,customTpl;{protected_legend:hide},protected;{expert_legend:hide},cssID',
                'netzhirsch_navigation' => '{title_legend},name,headline,type;{nav_legend},netzhirsch_nav_type,rootPage;{config_legend},netzhirsch_nav_burger,netzhirsch_nav_sticky,netzhirsch_nav_offcanvas;{template_legend:hide},netzhirsch_nav_styling',
                'login' => '{title_legend},name,type;{config_legend},reg_assignDir;{redirect_legend},jumpTo',
                'navigation' => '{title_legend},name,type;{reference_legend:hide},defineRoot',
            ],
            'subpalettes' => [
                'defineRoot' => 'rootPage',
                'protected' => 'groups',
                'reg_assignDir' => 'reg_homeDir',
                'netzhirsch_nav_offcanvas' => 'netzhirsch_offcanvas_headline,netzhirsch_offcanvas_class',
            ],
        ];
    }

    /**
     * The bug this class exists for: sub-palettes of OTHER types used to be
     * merged into every type, so the write path accepted columns the backend
     * would never show. The value lands in the row (one wide table per DCA) and
     * nothing renders it — a write that looks like it worked.
     */
    public function testASubPaletteOfAnotherTypeIsNotOffered(): void
    {
        $fields = DcaPalette::resolve(self::dca(), 'netzhirsch_megamenu')['fields'];

        self::assertNotContains('netzhirsch_offcanvas_headline', $fields);
        self::assertNotContains('netzhirsch_offcanvas_class', $fields);
        self::assertNotContains('reg_homeDir', $fields);
    }

    /**
     * …and the mirror image: the type that DOES carry the toggle keeps its
     * children, because a caller must be able to switch a toggle on and fill
     * its fields in the same call.
     */
    public function testTheTypeCarryingTheToggleKeepsItsChildren(): void
    {
        $resolved = DcaPalette::resolve(self::dca(), 'netzhirsch_navigation');

        self::assertContains('netzhirsch_offcanvas_headline', $resolved['fields']);
        self::assertContains('netzhirsch_offcanvas_class', $resolved['fields']);
        self::assertSame(
            ['netzhirsch_offcanvas_headline', 'netzhirsch_offcanvas_class'],
            $resolved['subpalettes']['netzhirsch_nav_offcanvas'] ?? [],
        );
    }

    /**
     * The six fields AL-07 could not write. They were never part of the
     * megamenu palette — they belong to netzhirsch_navigation, and there they
     * were writable all along.
     */
    public function testTheNavigationFieldsBelongToTheNavigationType(): void
    {
        $megamenu = DcaPalette::resolve(self::dca(), 'netzhirsch_megamenu')['fields'];
        $navigation = DcaPalette::resolve(self::dca(), 'netzhirsch_navigation')['fields'];

        foreach (['netzhirsch_nav_type', 'netzhirsch_nav_burger', 'netzhirsch_nav_sticky', 'netzhirsch_nav_offcanvas', 'netzhirsch_nav_styling'] as $field) {
            self::assertContains($field, $navigation, "$field should be writable on netzhirsch_navigation.");
            self::assertNotContains($field, $megamenu, "$field is not part of the megamenu palette.");
        }
    }

    public function testACheckboxToggleInThePaletteOpensItsChildren(): void
    {
        $resolved = DcaPalette::resolve(self::dca(), 'navigation');

        self::assertContains('defineRoot', $resolved['fields']);
        self::assertContains('rootPage', $resolved['fields']);
        self::assertSame(['rootPage'], $resolved['subpalettes']['defineRoot'] ?? []);
    }

    public function testPlainPaletteFieldsSurvive(): void
    {
        $fields = DcaPalette::resolve(self::dca(), 'login')['fields'];

        self::assertSame(['name', 'type', 'reg_assignDir', 'jumpTo', 'reg_homeDir'], $fields);
    }

    public function testLegendMarkersAreNotFields(): void
    {
        $fields = DcaPalette::resolve(self::dca(), 'login')['fields'];

        foreach ($fields as $field) {
            self::assertStringNotContainsString('{', $field);
        }
    }

    public function testAnUnknownTypeResolvesToNothing(): void
    {
        $resolved = DcaPalette::resolve(self::dca(), 'no_such_type');

        self::assertSame([], $resolved['fields']);
        self::assertSame([], $resolved['subpalettes']);
    }

    /**
     * Select-style sub-palettes are keyed `<selector>_<value>`. Which one is
     * open depends on the stored value, which the write path must not depend on
     * — otherwise you could never change the value and its fields together. So
     * every variant is allowed…
     */
    public function testEveryVariantOfASelectSelectorIsAllowed(): void
    {
        $dca = [
            'palettes' => [
                '__selector__' => ['type', 'source'],
                'download' => '{title_legend},name,type,source',
            ],
            'subpalettes' => [
                'source_default' => 'singleSRC',
                'source_external' => 'url,target',
            ],
        ];

        $fields = DcaPalette::resolve($dca, 'download')['fields'];

        self::assertContains('singleSRC', $fields);
        self::assertContains('url', $fields);
        self::assertContains('target', $fields);
    }

    /**
     * …with one exception. The type selector's value IS the resolved type, so
     * only its own variant applies — otherwise every type would inherit every
     * other type's `type_*` sub-palette.
     */
    public function testTheTypeSelectorIsRestrictedToTheResolvedType(): void
    {
        $dca = [
            'palettes' => [
                '__selector__' => ['type'],
                'alpha' => '{title_legend},name,type',
                'beta' => '{title_legend},name,type',
            ],
            'subpalettes' => [
                'type_alpha' => 'alphaOnly',
                'type_beta' => 'betaOnly',
            ],
        ];

        self::assertContains('alphaOnly', DcaPalette::resolve($dca, 'alpha')['fields']);
        self::assertNotContains('betaOnly', DcaPalette::resolve($dca, 'alpha')['fields']);
        self::assertNotContains('alphaOnly', DcaPalette::resolve($dca, 'beta')['fields']);
    }

    /**
     * An extension can register a sub-palette without adding its selector to
     * `__selector__` — the backend toggle then does not work, but the fields
     * are real. Widening for a sloppy DCA beats silently dropping its columns.
     */
    public function testASubPaletteWithoutASelectorEntryStillCounts(): void
    {
        $dca = [
            'palettes' => [
                'thing' => '{title_legend},name,type,addExtra',
            ],
            'subpalettes' => [
                'addExtra' => 'extraOne,extraTwo',
            ],
        ];

        $fields = DcaPalette::resolve($dca, 'thing')['fields'];

        self::assertContains('extraOne', $fields);
        self::assertContains('extraTwo', $fields);
    }

    public function testFieldsAreNotRepeatedWhenAToggleAlsoSitsInThePalette(): void
    {
        $dca = [
            'palettes' => [
                '__selector__' => ['defineRoot'],
                'thing' => '{title_legend},name,defineRoot,rootPage',
            ],
            'subpalettes' => ['defineRoot' => 'rootPage'],
        ];

        $fields = DcaPalette::resolve($dca, 'thing')['fields'];

        self::assertSame(array_values(array_unique($fields)), $fields);
    }

    /**
     * Sub-palettes nest. A text element reaches `alt` only through
     * addImage → overwriteMeta → alt, so stopping after one pass drops fields
     * the backend really shows — the opposite mistake to the one this class was
     * written for, and just as invisible.
     */
    public function testNestedSubPalettesAreExpanded(): void
    {
        $dca = [
            'palettes' => [
                '__selector__' => ['addImage', 'overwriteMeta'],
                'text' => '{title_legend},type,text,addImage',
            ],
            'subpalettes' => [
                'addImage' => 'singleSRC,overwriteMeta',
                'overwriteMeta' => 'alt,imageTitle,caption',
            ],
        ];

        $resolved = DcaPalette::resolve($dca, 'text');

        self::assertContains('singleSRC', $resolved['fields']);
        self::assertContains('alt', $resolved['fields'], 'The second level was not reached.');
        self::assertContains('caption', $resolved['fields']);
        self::assertArrayHasKey('overwriteMeta', $resolved['subpalettes']);
    }

    /**
     * A DCA that points two selectors at each other must not hang the request.
     */
    public function testACyclicSubPaletteTerminates(): void
    {
        $dca = [
            'palettes' => [
                '__selector__' => ['a', 'b'],
                'thing' => '{title_legend},type,a',
            ],
            'subpalettes' => [
                'a' => 'b',
                'b' => 'a',
            ],
        ];

        $fields = DcaPalette::resolve($dca, 'thing')['fields'];

        self::assertContains('a', $fields);
        self::assertContains('b', $fields);
    }

    /**
     * The exact shape netzhirsch/contao-bootstrap-bundle ships for the grid
     * fields, and a regression 1.8.0 introduced: the sub-palette keys are
     * select-style (`netzhirsch_grid_element_row`), the selector
     * `netzhirsch_grid_element` sits in the palette — and it is deliberately
     * NOT in `__selector__`, so the backend does not render the group twice.
     *
     * Looking only at `__selector__` and at keys that are themselves palette
     * fields misses both, and the fields become unwritable on every type. The
     * DCA even documents that it declares these sub-palettes purely so a write
     * tool accepts the fields, which is exactly what stopped working.
     */
    public function testASelectStyleKeyFindsItsSelectorByPrefix(): void
    {
        $dca = [
            'palettes' => [
                // No __selector__ entry for netzhirsch_grid_element, on purpose.
                '__selector__' => ['type'],
                'element_group' => '{type_legend},type,netzhirsch_grid_element;{expert_legend},cssID',
                'text' => '{type_legend},type,text',
            ],
            'subpalettes' => [
                'netzhirsch_grid_element_row' => 'netzhirsch_grid_rowcols,netzhirsch_grid_gx',
                'netzhirsch_grid_element_col' => 'netzhirsch_grid_col,netzhirsch_grid_offset',
            ],
        ];

        $group = DcaPalette::resolve($dca, 'element_group');

        // Both variants, because which one is open depends on the stored value.
        self::assertContains('netzhirsch_grid_rowcols', $group['fields']);
        self::assertContains('netzhirsch_grid_gx', $group['fields']);
        self::assertContains('netzhirsch_grid_col', $group['fields']);
        self::assertContains('netzhirsch_grid_offset', $group['fields']);
        self::assertArrayHasKey('netzhirsch_grid_element', $group['subpalettes']);

        // …and still only for the type that carries the selector.
        $text = DcaPalette::resolve($dca, 'text')['fields'];
        self::assertNotContains('netzhirsch_grid_col', $text);
        self::assertNotContains('netzhirsch_grid_rowcols', $text);
    }

    /**
     * The prefix walk must not invent a selector out of a coincidence: a
     * sub-palette key whose prefix is not a field of THIS palette stays out.
     */
    public function testAPrefixThatIsNotAPaletteFieldIsNoSelector(): void
    {
        $dca = [
            'palettes' => [
                'thing' => '{title_legend},type,name',
            ],
            'subpalettes' => [
                'some_toggle_on' => 'secretField',
            ],
        ];

        self::assertNotContains('secretField', DcaPalette::resolve($dca, 'thing')['fields']);
    }

    public function testAMissingPalettesSectionDoesNotCrash(): void
    {
        self::assertSame(['fields' => [], 'subpalettes' => []], DcaPalette::resolve([], 'anything'));
    }
}
