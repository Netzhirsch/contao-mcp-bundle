<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Tool;

use Netzhirsch\ContaoMcpBundle\Security\McpPermissionEnforcer;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Security\ToolPermissionMap;
use Netzhirsch\ContaoMcpBundle\Service\OptionalBundles;
use Netzhirsch\ContaoMcpBundle\Tool\Calendar\Tool as CalendarTool;
use Netzhirsch\ContaoMcpBundle\Tool\CalendarEvent\Tool as CalendarEventTool;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\OpenGraph\Tool as OpenGraphTool;
use Netzhirsch\ContaoMcpBundle\Tool\Faq\Tool as FaqTool;
use Netzhirsch\ContaoMcpBundle\Tool\FaqCategory\Tool as FaqCategoryTool;
use Netzhirsch\ContaoMcpBundle\Tool\News\Tool as NewsTool;
use Netzhirsch\ContaoMcpBundle\Tool\NewsArchive\Tool as NewsArchiveTool;
use Netzhirsch\ContaoMcpBundle\Tool\System\Tool as SystemTool;
use PhpMcp\Server\Attributes\McpTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Without contao/news-bundle, contao/calendar-bundle or contao/faq-bundle their
 * tools answer extension_not_available — and say so before they touch anything.
 *
 * Each tool is built with placeholders for all its dependencies: objects whose
 * constructor never ran. A guard that came after the first statement would
 * reach one of them and throw here instead of answering.
 */
final class OptionalBundleToolsTest extends TestCase
{
    private const FAMILIES = [
        NewsTool::class => OptionalBundles::NEWS,
        NewsArchiveTool::class => OptionalBundles::NEWS,
        CalendarTool::class => OptionalBundles::CALENDAR,
        CalendarEventTool::class => OptionalBundles::CALENDAR,
        FaqTool::class => OptionalBundles::FAQ,
        FaqCategoryTool::class => OptionalBundles::FAQ,
    ];

    /**
     * @return iterable<string, array{class-string, string, string}>
     */
    public static function bundleTools(): iterable
    {
        foreach (self::FAMILIES as $class => $package) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(McpTool::class) as $attribute) {
                    yield (string) $attribute->newInstance()->name => [$class, $method->getName(), $package];
                }
            }
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('bundleTools')]
    public function testTheToolAnswersNotAvailableWithoutItsBundle(string $class, string $method, string $package): void
    {
        $tool = $this->build($class, new OptionalBundles([$package => false]));
        $result = $tool->{$method}(...$this->requiredArguments(new \ReflectionMethod($class, $method)));

        self::assertIsArray($result);
        self::assertSame('extension_not_available', $result['error'] ?? null);
        self::assertSame($package, $result['required_extension'] ?? null);
    }

    public function testEveryToolOfTheThreeBundlesIsCovered(): void
    {
        // Five per family. A new tool in one of these classes lands in the
        // data provider by itself; this only guards against the provider
        // silently finding nothing.
        self::assertCount(30, iterator_to_array(self::bundleTools()));
    }

    public function testAnInstalledBundleLetsTheCallThrough(): void
    {
        $tool = $this->build(NewsArchiveTool::class, new OptionalBundles([OptionalBundles::NEWS => true]));

        try {
            $result = $tool->list();
        } catch (\Throwable) {
            // It went on and used a placeholder dependency: past the guard.
            $result = [];
        }

        self::assertNotSame('extension_not_available', $result['error'] ?? null);
    }

    public function testThePermissionCheckSaysNotAvailableBeforeItAsksAVoter(): void
    {
        // The guard is a placeholder: asking it anything would throw.
        $enforcer = new McpPermissionEnforcer(
            new ToolPermissionMap(),
            (new \ReflectionClass(McpPermissionGuard::class))->newInstanceWithoutConstructor(),
            new OptionalBundles([OptionalBundles::NEWS => false, OptionalBundles::FAQ => false]),
        );

        $calls = [
            'news_get' => ['id' => 1],
            'news_archives_list' => [],
            'faq_category_delete' => ['id' => 1, 'confirm_destructive' => true],
            // Generic tools resolve the table from their arguments.
            'entity_move' => ['table' => 'tl_news', 'id' => 1, 'position' => 'first'],
            'opengraph_set' => ['table' => 'tl_faq', 'id' => 1, 'fields' => []],
        ];

        foreach ($calls as $tool => $args) {
            $result = $enforcer->check($tool, $args);

            self::assertSame('extension_not_available', $result['error'] ?? null, $tool);
        }
    }

    public function testEntityQueryOptionsNamesTheMissingBundle(): void
    {
        $tool = $this->build(SystemTool::class, new OptionalBundles([OptionalBundles::CALENDAR => false]));

        self::assertSame('contao/calendar-bundle', $tool->entityQueryOptions('tl_calendar_events')['required_extension'] ?? null);
    }

    public function testOpenGraphNamesTheMissingBundleOfTheTable(): void
    {
        $tool = $this->build(OpenGraphTool::class, new OptionalBundles([OptionalBundles::NEWS => false]));

        self::assertSame('contao/news-bundle', $tool->types('tl_news')['required_extension'] ?? null);
        self::assertSame('contao/news-bundle', $tool->get('tl_news', 1)['required_extension'] ?? null);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function build(string $class, OptionalBundles $bundles): object
    {
        $arguments = [];

        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $arguments[] = $type instanceof \ReflectionNamedType && $type->getName() === OptionalBundles::class
                ? $bundles
                : $this->placeholder($type);
        }

        return new $class(...$arguments);
    }

    /**
     * @return array<string, mixed> the required parameters, by name
     */
    private function requiredArguments(\ReflectionMethod $method): array
    {
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            if (!$parameter->isDefaultValueAvailable()) {
                $arguments[$parameter->getName()] = $this->placeholder($parameter->getType());
            }
        }

        return $arguments;
    }

    private function placeholder(?\ReflectionType $type): mixed
    {
        if (!$type instanceof \ReflectionNamedType) {
            return null;
        }

        $name = $type->getName();

        if ($type->isBuiltin()) {
            return match ($name) {
                'int' => 1,
                'float' => 1.0,
                'string' => 'x',
                'bool' => false,
                'array', 'iterable' => [],
                default => null,
            };
        }

        if (interface_exists($name)) {
            return $this->createStub($name);
        }

        /** @var class-string $name */
        return (new \ReflectionClass($name))->newInstanceWithoutConstructor();
    }
}
