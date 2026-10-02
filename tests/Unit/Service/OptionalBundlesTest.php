<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service;

use Netzhirsch\ContaoMcpBundle\Service\OptionalBundles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OptionalBundles::class)]
final class OptionalBundlesTest extends TestCase
{
    public function testAnInstalledBundleIsNoReasonToRefuse(): void
    {
        // The dev dependencies install all five, so the class check finds them.
        $bundles = new OptionalBundles();

        foreach ([OptionalBundles::NEWS, OptionalBundles::CALENDAR, OptionalBundles::FAQ, OptionalBundles::COMMENTS, OptionalBundles::NEWSLETTER] as $package) {
            self::assertTrue($bundles->has($package), $package);
            self::assertNull($bundles->unavailable($package), $package);
        }
    }

    public function testAMissingBundleNamesItself(): void
    {
        $refusal = (new OptionalBundles([OptionalBundles::NEWS => false]))->unavailable(OptionalBundles::NEWS);

        self::assertSame('extension_not_available', $refusal['error'] ?? null);
        self::assertSame('contao/news-bundle', $refusal['required_extension'] ?? null);
        self::assertStringContainsString('contao/news-bundle', $refusal['message'] ?? '');
    }

    public function testTablesAreTracedToTheirBundle(): void
    {
        $bundles = new OptionalBundles([
            OptionalBundles::NEWS => false,
            OptionalBundles::CALENDAR => false,
            OptionalBundles::FAQ => true,
        ]);

        self::assertSame('contao/news-bundle', $bundles->unavailableForTable('tl_news_archive')['required_extension'] ?? null);
        self::assertSame('contao/calendar-bundle', $bundles->unavailableForTable('tl_calendar_events')['required_extension'] ?? null);
        self::assertNull($bundles->unavailableForTable('tl_faq'), 'installed');
        self::assertNull($bundles->unavailableForTable('tl_page'), 'core table');
        self::assertNull($bundles->unavailableForTable('tl_module'), 'core table the bundles only extend');
    }

    public function testAnUnknownPackageIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new OptionalBundles())->has('terminal42/contao-leads');
    }
}
