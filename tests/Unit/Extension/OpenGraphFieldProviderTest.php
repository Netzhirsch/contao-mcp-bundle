<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Extension;

use Contao\Controller;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Model;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\OpenGraph\NewsProvider;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\OpenGraph\PageProvider;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\OpenGraph\Refusal;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\OpenGraph\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The rules this covers are the ones that are invisible from the outside and
 * cost data when they are wrong: which og:type keeps which property, and the
 * exact shape of the `og_properties` blob.
 *
 * The DCA is seeded by hand rather than loaded, so the test states the upstream
 * contract it relies on instead of inheriting whatever happens to be installed.
 * If numero2 ever changes that contract, the live probe catches it — this file
 * is here to pin OUR behaviour against the contract as we understood it.
 */
final class OpenGraphFieldProviderTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['opengraph_fields'] = [
            'og_subpalettes' => [
                '__basic__' => 'og_title,og_type,og_image',
                '__all__' => 'og_description',
                'website' => 'og_locale,og_site_name',
                'article' => 'og_article_author,og_article_section',
                'product' => 'og_product_brand',
            ],
            'fields' => [
                'og_title' => ['sql' => "varchar(255) NOT NULL default ''"],
                'og_type' => ['sql' => "varchar(32) NOT NULL default ''"],
                'og_image' => ['sql' => 'binary(16) NULL'],
                'og_properties' => ['sql' => 'blob NULL'],
                'og_description' => [],
                'og_locale' => [],
                'og_site_name' => [],
                'og_article_author' => [],
                'og_article_section' => [],
                'og_product_brand' => [],
            ],
        ];

        $GLOBALS['TL_DCA']['tl_page'] = ['fields' => ['og_title' => ['sql' => 'varchar(255)']]];
        $GLOBALS['TL_DCA']['tl_news'] = [
            'fields' => ['og_title' => ['sql' => 'varchar(255)']],
            'config' => ['allowedOpenGraphTypes' => ['article']],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    public function testColumnsAreTheFieldsWithSqlExceptTheBlob(): void
    {
        self::assertSame(['og_title', 'og_type', 'og_image'], $this->schema()->columns());
    }

    public function testPropertiesAreTheFieldsWithoutSql(): void
    {
        self::assertContains('og_article_author', $this->schema()->propertyNames());
        self::assertNotContains('og_title', $this->schema()->propertyNames());
    }

    public function testWithoutATypeOnlyTheAlwaysOnPropertiesApply(): void
    {
        self::assertSame(['og_description'], $this->schema()->propertiesForType(''));
    }

    public function testATypeAddsItsOwnPropertiesOnTop(): void
    {
        self::assertSame(
            ['og_article_author', 'og_article_section', 'og_description'],
            $this->schema()->propertiesForType('article'),
        );
    }

    public function testATableCanRestrictTheTypesItOffers(): void
    {
        self::assertSame(['article'], $this->schema()->typesForTable('tl_news'));
        self::assertContains('product', $this->schema()->typesForTable('tl_page'));
    }

    public function testAPropertyOutsideTheTypeIsRefusedAndTheRightTypeIsNamed(): void
    {
        $model = $this->model(['og_type' => 'article']);

        try {
            $this->provider()->apply($model, ['og_product_brand' => 'Netzhirsch'], true);
            self::fail('A property the type does not keep must be refused.');
        } catch (Refusal $e) {
            self::assertSame('property_not_valid_for_type', $e->errorCode);
            self::assertStringContainsString('"product"', $e->getMessage());
        }
    }

    public function testTheTypeInTheSameCallDecidesWhatIsValid(): void
    {
        $model = $this->model(['og_type' => 'website']);

        $touched = $this->provider()->apply(
            $model,
            ['og_type' => 'article', 'og_article_author' => 'Jan'],
            true,
        );

        self::assertContains('og_type', $touched);
        self::assertContains('og_properties', $touched);
        self::assertStringContainsString('og_article_author', (string) $model->og_properties);
    }

    public function testChangingTheTypePrunesExactlyWhatTheBackendWouldDrop(): void
    {
        $model = $this->model([
            'og_type' => 'article',
            'og_properties' => serialize([['og_article_author', 'Jan'], ['og_description', 'bleibt']]),
        ]);

        $this->provider()->apply($model, ['og_type' => 'website'], true);

        $rows = unserialize((string) $model->og_properties, ['allowed_classes' => false]);
        self::assertIsArray($rows);
        self::assertSame([['og_description', 'bleibt']], $rows);
    }

    /**
     * Order follows the DCA, not the order the caller happened to pass the
     * fields in — so the same content always serialises to the same bytes and a
     * re-write does not show up as a change.
     */
    public function testTheBlobKeepsTheWidgetsOwnShapeInDcaOrder(): void
    {
        $model = $this->model(['og_type' => 'article']);

        $this->provider()->apply($model, ['og_article_author' => 'Jan', 'og_description' => 'Kurz'], true);

        self::assertSame(
            serialize([['og_description', 'Kurz'], ['og_article_author', 'Jan']]),
            $model->og_properties,
        );
    }

    public function testAnEmptyValueRemovesThePropertyRatherThanStoringAnEmptyRow(): void
    {
        $model = $this->model([
            'og_type' => 'article',
            'og_properties' => serialize([['og_article_author', 'Jan']]),
        ]);

        $this->provider()->apply($model, ['og_article_author' => ''], true);

        self::assertNull($model->og_properties);
    }

    public function testATypeTheTableDoesNotOfferIsRefused(): void
    {
        $provider = new NewsProvider($this->framework(), $this->schema());

        try {
            $provider->apply($this->model([]), ['og_type' => 'product'], true);
            self::fail('tl_news only offers "article".');
        } catch (Refusal $e) {
            self::assertSame('type_not_allowed', $e->errorCode);
        }
    }

    public function testAStringForTheBlobIsRefusedInsteadOfCorruptingIt(): void
    {
        try {
            $this->provider()->apply($this->model([]), ['og_properties' => 'kaputt'], true);
            self::fail('The blob is a structure, not a string.');
        } catch (Refusal $e) {
            self::assertSame('invalid_value', $e->errorCode);
        }
    }

    public function testReadingMergesColumnsAndPropertiesIntoOneFlatMap(): void
    {
        $model = $this->model([
            'og_title' => 'Titel',
            'og_type' => 'article',
            'og_properties' => serialize([['og_article_author', 'Jan']]),
        ]);

        $out = $this->provider()->serialize($model);

        self::assertSame('Titel', $out['og_title']);
        self::assertSame('Jan', $out['og_article_author']);
    }

    private function schema(): Schema
    {
        return new Schema($this->framework());
    }

    private function provider(): PageProvider
    {
        return new PageProvider($this->framework(), $this->schema());
    }

    /**
     * loadDataContainer must do nothing — the DCA is already seeded above.
     */
    private function framework(): ContaoFramework
    {
        $controller = $this->createMock(Adapter::class);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(
            static fn (string $class): Adapter => $controller,
        );

        return $framework;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function model(array $data): Model
    {
        return new class($data) extends Model {
            /** @var array<string, mixed> */
            private array $values;

            /**
             * @param array<string, mixed> $values
             */
            public function __construct(array $values)
            {
                $this->values = $values;
            }

            public function __get($key)
            {
                return $this->values[$key] ?? null;
            }

            public function __set($key, $value)
            {
                $this->values[$key] = $value;
            }

            public function __isset($key)
            {
                return isset($this->values[$key]);
            }
        };
    }
}
