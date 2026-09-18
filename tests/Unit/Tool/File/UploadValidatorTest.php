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
    public static function activeSvgs(): iterable
    {
        yield 'script element' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'];
        yield 'event handler' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'];
        yield 'javascript url' => ['logo.svg', '<svg><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>'];
        yield 'foreign object' => ['logo.svg', '<svg><foreignObject><body>hi</body></foreignObject></svg>'];
        yield 'entity' => ['logo.svg', '<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg>&xxe;</svg>'];
        yield 'html file' => ['page.html', '<html><body><script>alert(1)</script></body></html>'];
        yield 'uppercase tag' => ['logo.svg', '<svg><SCRIPT>alert(1)</SCRIPT></svg>'];
    }

    /**
     * Contao's default upload types include svg, and an SVG is a document: it
     * runs when a visitor opens it directly, on the site's own origin.
     */
    #[DataProvider('activeSvgs')]
    public function testMarkupCarryingScriptIsRefused(string $name, string $bytes): void
    {
        $result = $this->validator()->validateContent($name, $bytes);

        self::assertFalse($result['ok'], $name);
        self::assertSame('active_content_refused', $result['error']);
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
