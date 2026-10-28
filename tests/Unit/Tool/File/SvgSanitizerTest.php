<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Tool\File;

use enshrined\svgSanitize\Sanitizer;
use Netzhirsch\ContaoMcpBundle\Tool\File\SvgSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SVG uploads are cleaned the way the Contao backend cleans them, and then
 * checked once more for being an SVG at all.
 *
 * The cases come from measuring the previous pattern check against real
 * files: it refused an Illustrator export and a logo titled "Corporate
 * Identity", while Contao's own sanitiser — which the backend uses — handled
 * both. And Contao's sanitiser on its own turns the Illustrator file into
 * invalid XML; that is the second half of what this class pins.
 */
#[CoversClass(SvgSanitizer::class)]
final class SvgSanitizerTest extends TestCase
{
    private const ILLUSTRATOR = <<<'SVG'
        <?xml version="1.0" encoding="utf-8"?>
        <!-- Generator: Adobe Illustrator 24.0.0, SVG Export Plug-In . SVG Version: 6.00 Build 0)  -->
        <!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd" [
        	<!ENTITY ns_extend "http://ns.adobe.com/Extensibility/1.0/">
        	<!ENTITY ns_ai "http://ns.adobe.com/AdobeIllustrator/10.0/">
        	<!ENTITY ns_graphs "http://ns.adobe.com/Graphs/1.0/">
        ]>
        <svg version="1.1" xmlns:x="&ns_extend;" xmlns:i="&ns_ai;" xmlns:graph="&ns_graphs;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#c00"/></svg>
        SVG;

    protected function setUp(): void
    {
        if (!class_exists(Sanitizer::class)) {
            self::markTestSkipped('enshrined/svg-sanitize is not installed (Contao < 5.3.38).');
        }
    }

    /**
     * The file that started this: refused as XXE before, broken XML after
     * Contao's sanitiser alone. Now it is accepted and parses.
     */
    public function testAnIllustratorExportIsAcceptedAndStaysValid(): void
    {
        $result = (new SvgSanitizer())->sanitize(self::ILLUSTRATOR, false);

        self::assertTrue($result['ok'], $result['message'] ?? '');
        self::assertStringContainsString('xmlns:i="http://ns.adobe.com/AdobeIllustrator/10.0/"', $result['bytes']);
        self::assertStringNotContainsString('&ns_', $result['bytes']);
        self::assertTrue((new \DOMDocument())->loadXML($result['bytes'], LIBXML_NONET));
        self::assertSame([], $result['removed'], 'dropping the DOCTYPE is housekeeping, not a finding');
    }

    public function testATitleThatMentionsIdentityIsNotAnEntity(): void
    {
        $svg = "<?xml version=\"1.0\"?>\n<!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd\">\n"
            .'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><title>Corporate Identity</title><rect width="10" height="10"/></svg>';

        $result = (new SvgSanitizer())->sanitize($svg, false);

        self::assertTrue($result['ok']);
        self::assertStringContainsString('Corporate Identity', $result['bytes']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function activeSvgs(): iterable
    {
        yield 'script element' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>', '<script'];
        yield 'uppercase script' => ['<svg xmlns="http://www.w3.org/2000/svg"><SCRIPT>alert(1)</SCRIPT><rect width="1" height="1"/></svg>', 'alert(1)'];
        yield 'event handler' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect onload="alert(1)" width="1" height="1"/></svg>', 'onload'];
        yield 'javascript url' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="javascript:alert(1)"><rect width="1" height="1"/></a></svg>', 'javascript:'];
        yield 'foreign object' => ['<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject></svg>', 'foreignObject'];
        yield 'remote use' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="https://evil.example/x.svg#a"/></svg>', 'evil.example'];
    }

    /**
     * What makes an SVG active is removed — the file is stored as safe as one
     * uploaded through the backend — and the answer says what was removed.
     */
    #[DataProvider('activeSvgs')]
    public function testActiveContentIsRemovedAndReported(string $svg, string $mustBeGone): void
    {
        $result = (new SvgSanitizer())->sanitize($svg, false);

        self::assertTrue($result['ok']);
        self::assertStringNotContainsStringIgnoringCase($mustBeGone, $result['bytes']);
        self::assertNotSame([], $result['removed']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dangerousDeclarations(): iterable
    {
        yield 'external entity (XXE)' => ["<?xml version=\"1.0\"?>\n<!DOCTYPE svg [<!ENTITY xxe SYSTEM \"file:///etc/passwd\">]>\n<svg xmlns=\"http://www.w3.org/2000/svg\"><text>&xxe;</text></svg>"];
        yield 'nested entity (billion laughs)' => ["<?xml version=\"1.0\"?>\n<!DOCTYPE svg [<!ENTITY a \"aaaa\"><!ENTITY b \"&a;&a;&a;&a;\">]>\n<svg xmlns=\"http://www.w3.org/2000/svg\"><text>&b;</text></svg>"];
        yield 'parameter entity' => ["<?xml version=\"1.0\"?>\n<!DOCTYPE svg [<!ENTITY % p \"x\">]>\n<svg xmlns=\"http://www.w3.org/2000/svg\"/>"];
        yield 'public identifier' => ["<?xml version=\"1.0\"?>\n<!DOCTYPE svg [<!ENTITY e PUBLIC \"-//x\" \"http://evil.example/e\">]>\n<svg xmlns=\"http://www.w3.org/2000/svg\">&e;</svg>"];
    }

    /**
     * Only plain text entities are resolved. Anything that could read a file,
     * fetch a URL or expand into itself is refused, not resolved.
     */
    #[DataProvider('dangerousDeclarations')]
    public function testEntitiesThatAreNotPlainTextAreRefused(string $svg): void
    {
        self::assertNull(SvgSanitizer::resolvePlainEntities($svg));

        $result = (new SvgSanitizer())->sanitize($svg, false);

        self::assertFalse($result['ok']);
        self::assertSame('invalid_svg', $result['error']);
    }

    public function testAValueIsEscapedForTheAttributeItLandsIn(): void
    {
        $svg = "<!DOCTYPE svg [<!ENTITY t \"a > b\">]>\n<svg xmlns=\"http://www.w3.org/2000/svg\" data-x=\"&t;\"/>";

        $resolved = SvgSanitizer::resolvePlainEntities($svg);

        self::assertIsString($resolved);
        self::assertStringContainsString('data-x="a &gt; b"', $resolved);
        self::assertStringNotContainsString('<!ENTITY', $resolved);
    }

    public function testADocumentWithoutAnInternalSubsetIsLeftAlone(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"/>';

        self::assertSame($svg, SvgSanitizer::resolvePlainEntities($svg));
    }

    public function testSomethingThatIsNotAnSvgIsRefused(): void
    {
        $result = (new SvgSanitizer())->sanitize('this is not xml at all', false);

        self::assertFalse($result['ok']);
        self::assertSame('invalid_svg', $result['error']);
    }

    public function testACompressedSvgStaysCompressed(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>';

        $result = (new SvgSanitizer())->sanitize((string) gzencode($svg), true);

        self::assertTrue($result['ok']);
        self::assertStringStartsWith("\x1F\x8B", $result['bytes']);
        self::assertStringNotContainsString('<script', (string) gzdecode($result['bytes']));
    }
}
