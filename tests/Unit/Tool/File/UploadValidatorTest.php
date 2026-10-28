<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Tool\File;

use Netzhirsch\ContaoMcpBundle\Tool\File\UploadValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An extension whitelist answers "may this NAME be uploaded". It says nothing
 * about what the server will execute, and nothing about what is inside the
 * file — and both of those decide whether the upload ends as a stored file or
 * as code running on the site's own origin.
 */
#[CoversClass(UploadValidator::class)]
final class UploadValidatorTest extends TestCase
{
    private function validator(): UploadValidator
    {
        // The validator reads uploadTypes/maxFileSize off Contao's Config
        // adapter; the content checks below never touch it.
        return new UploadValidator(new class {
            public function get(string $key): mixed
            {
                return match ($key) {
                    'uploadTypes' => 'jpg,jpeg,png,gif,svg,svgz,pdf,html,zip',
                    'maxFileSize' => 10485760,
                    default => null,
                };
            }
        });
    }

    private const PNG = "\x89PNG\r\n\x1A\n".'rest of the file';

    /**
     * `invoice.php.jpg` passes a whitelist that only looks at the last
     * extension, and is still a .php file to a server with a legacy
     * `AddHandler … .php` line.
     */
    public function testAnInnerExecutableExtensionIsRefused(): void
    {
        $result = $this->validator()->validateUpload('invoice.php.jpg', 100);

        self::assertFalse($result['ok']);
        self::assertSame('invalid_filename', $result['error']);
        self::assertStringContainsString('php', (string) $result['message']);
    }

    public function testAnOrdinaryNameWithDotsIsStillAccepted(): void
    {
        self::assertTrue($this->validator()->validateUpload('team.photo.2026.jpg', 100)['ok']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function activeMarkup(): iterable
    {
        yield 'html script' => ['page.html', '<html><body><script>alert(1)</script></body></html>'];
        yield 'htm handler' => ['page.htm', '<html><body onload="alert(1)"></body></html>'];
        yield 'xhtml uppercase' => ['page.xhtml', '<html><SCRIPT>alert(1)</SCRIPT></html>'];
        yield 'xml entity' => ['data.xml', '<!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><x>&xxe;</x>'];
    }

    /**
     * Markup the browser runs from `files/`, and for which there is no
     * sanitiser: active content is refused.
     */
    #[DataProvider('activeMarkup')]
    public function testActiveMarkupIsRefused(string $name, string $bytes): void
    {
        $result = $this->validator()->validateContent($name, $bytes);

        self::assertFalse($result['ok'], $name);
        self::assertSame('active_content_refused', $result['error']);
    }

    /**
     * SVG is cleaned by SvgSanitizer, the way the backend cleans it, so the
     * content check does not refuse it by pattern any more.
     */
    public function testSvgIsLeftToTheSanitiser(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        self::assertTrue($this->validator()->validateContent('logo.svg', $svg)['ok']);
        self::assertContains('svg', UploadValidator::SVG_EXTENSIONS);
    }

    /**
     * The pattern check that remains (HTML, and SVG on a Contao without the
     * sanitiser) must not fire on a document that merely mentions the word.
     * It used to refuse any DOCTYPE plus "entity" anywhere — a logo titled
     * "Corporate Identity" was refused as XXE.
     */
    public function testTheWordEntityIsNotAnEntityDeclaration(): void
    {
        $svg = "<!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd\">\n<svg><title>Corporate Identity</title></svg>";

        self::assertNull(UploadValidator::activeMarkupIn($svg));
        self::assertNotNull(UploadValidator::activeMarkupIn('<!DOCTYPE x [<!ENTITY a "b">]><x/>'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function backendNames(): iterable
    {
        yield 'question mark' => ['Foto?.png', 'Foto-.png'];
        yield 'angle brackets' => ['x<y>.png', 'x-y-.png'];
        yield 'colon' => ['12:30 Termin.pdf', '12-30 Termin.pdf'];
        yield 'tab' => ["tab\tname.png", 'tabname.png'];
        yield 'umlauts stay' => ['Grüße aus Köln.png', 'Grüße aus Köln.png'];
        yield 'hash and spaces stay' => ['Bild #1 (final).png', 'Bild #1 (final).png'];
    }

    /**
     * The backend file manager runs every name through
     * StringUtil::sanitizeFileName(); this path now does too, so a name the
     * backend would never have stored is not stored here either.
     */
    #[DataProvider('backendNames')]
    public function testNamesAreCleanedLikeTheBackendCleansThem(string $given, string $stored): void
    {
        $result = UploadValidator::sanitiseName($given);

        self::assertTrue($result['ok'], $given);
        self::assertSame($stored, $result['name']);
    }

    /**
     * The backend would turn the slash into a dash as well. Here it is a
     * mistake to point out: the folder belongs in parent_path.
     */
    public function testAPathAsNameIsRefusedNotFlattened(): void
    {
        $result = UploadValidator::sanitiseName('bilder/logo.png');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('parent_path', $result['message']);
    }

    public function testAPdfHeaderMayFollowABom(): void
    {
        self::assertTrue($this->validator()->validateContent('doc.pdf', "\xEF\xBB\xBF%PDF-1.7\n")['ok']);
        self::assertFalse($this->validator()->validateContent('doc.pdf', str_repeat(' ', 2000).'%PDF-1.7')['ok']);
    }

    /**
     * WebP lives in a RIFF container like WAV and AVI; "RIFF" alone says
     * nothing, the format name at byte 8 does.
     */
    public function testWebpNeedsItsFormatNameNotJustRiff(): void
    {
        $validator = new UploadValidator(new class {
            public function get(string $key): mixed
            {
                return $key === 'uploadTypes' ? 'webp' : null;
            }
        });

        self::assertTrue($validator->validateContent('a.webp', 'RIFF'."\x00\x00\x00\x00".'WEBPVP8 ')['ok']);
        self::assertFalse($validator->validateContent('a.webp', 'RIFF'."\x00\x00\x00\x00".'WAVEfmt ')['ok']);
    }

    public function testOnlyMarkupIsReadWhole(): void
    {
        self::assertTrue(UploadValidator::needsFullContent('HTML'));
        self::assertFalse(UploadValidator::needsFullContent('jpg'));
        self::assertFalse(UploadValidator::needsFullContent('svg'));
    }

    public function testAPlainSvgIsAccepted(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';

        self::assertTrue($this->validator()->validateContent('logo.svg', $svg)['ok']);
    }

    /**
     * The server decides what to serve by the name. A file called .png whose
     * body is HTML therefore hosts the attacker's markup on this origin.
     */
    public function testContentThatDoesNotMatchTheExtensionIsRefused(): void
    {
        $result = $this->validator()->validateContent('logo.png', '<html><body>hi</body></html>');

        self::assertFalse($result['ok']);
        self::assertSame('content_does_not_match_extension', $result['error']);
    }

    public function testARealPngIsAccepted(): void
    {
        self::assertTrue($this->validator()->validateContent('logo.png', self::PNG)['ok']);
    }

    /**
     * Only formats with an unambiguous signature are checked. A text format
     * has none, and inventing one would reject legitimate files.
     */
    public function testAFormatWithoutASignatureIsNotGuessedAt(): void
    {
        self::assertTrue($this->validator()->validateContent('notes.txt', 'anything at all')['ok']);
        self::assertTrue($this->validator()->validateContent('data.csv', 'a;b;c')['ok']);
    }
}
