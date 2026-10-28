<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\File;

use Contao\StringUtil;
use Contao\Validator;

/**
 * Centralises the "may we accept this file?" decisions: extension whitelist,
 * size limit, filename safety. Reads its rules from `Contao\Config` so it
 * always matches what the Backend would enforce — `uploadTypes` /
 * `maxFileSize` are exactly what the regular Backend file picker checks.
 *
 * The constructor accepts the framework Adapter wrapper rather than the
 * Config object directly because `Contao\Config` is a singleton; the
 * adapter is what `ContaoFramework::getAdapter(Config::class)` returns.
 *
 * The `$config` parameter is typed as `object` rather than a precise
 * `object{get(string): mixed}` shape because PHPStan's docblock parser
 * trips on the parenthesised method-shape syntax. Runtime contract: the
 * object must respond to `get(string $key): mixed`.
 */
final class UploadValidator
{
    public function __construct(
        private readonly object $config,
    ) {
    }

    /**
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        $raw = (string) $this->config->get('uploadTypes');
        $out = [];
        foreach (explode(',', $raw) as $ext) {
            $ext = strtolower(trim($ext));
            if ($ext !== '') {
                $out[] = $ext;
            }
        }

        return $out;
    }

    public function maxFileSize(): int
    {
        return (int) $this->config->get('maxFileSize');
    }

    /**
     * The name the file will be stored under, as the backend would store it.
     *
     * Contao's file manager runs every uploaded name through
     * `StringUtil::sanitizeFileName()`: control characters go, and the
     * characters Windows does not allow in a name (`: * ? " < > |`) become `-`.
     * This path skipped that, so `Foto?.png` was stored as such — a name whose
     * URL ends at the `?`, and which a Windows host refuses outright.
     *
     * A path separator is NOT turned into `-` here although the backend would:
     * `bilder/logo.png` as a name almost always means the folder belongs in
     * `parent_path`, and quietly storing `bilder-logo.png` would hide that.
     *
     * @return array{ok: true, name: string}|array{ok: false, error: string, message: string}
     */
    public static function sanitiseName(string $filename): array
    {
        $name = trim($filename);

        if (str_contains($name, '/') || str_contains($name, '\\')) {
            return [
                'ok' => false,
                'error' => 'invalid_filename',
                'message' => "\"{$name}\" is a path, not a file name. Put the folder into parent_path and only the file name into name.",
            ];
        }

        try {
            $clean = trim(StringUtil::sanitizeFileName($name));
        } catch (\InvalidArgumentException) {
            return ['ok' => false, 'error' => 'invalid_filename', 'message' => 'The file name is not valid UTF-8.'];
        }

        if ($clean === '' || !Validator::isValidFileName($clean)) {
            return ['ok' => false, 'error' => 'invalid_filename', 'message' => 'The file name is empty or longer than 255 characters once cleaned up.'];
        }

        return ['ok' => true, 'name' => $clean];
    }

    /**
     * @return array{ok: bool, error?: string, message?: string, allowed_extensions?: list<string>, max_file_size?: int, actual_size?: int}
     */
    public function validateUpload(string $filename, int $contentSize): array
    {
        $name = trim($filename);
        if ($name === '') {
            return ['ok' => false, 'error' => 'invalid_filename', 'message' => 'Filename is empty.'];
        }
        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            return ['ok' => false, 'error' => 'invalid_filename', 'message' => "Filename contains illegal characters: {$name}"];
        }
        // Contao's StringUtil::standardize/Validator are aimed at slugs, not
        // user-uploaded filenames, so we do the same basic check the Backend
        // does: pathinfo + extension.
        if (str_starts_with($name, '.')) {
            return ['ok' => false, 'error' => 'invalid_filename', 'message' => "Hidden / dot-prefixed filenames are not allowed."];
        }

        $extension = strtolower(pathinfo($name, \PATHINFO_EXTENSION));
        if ($extension === '') {
            return ['ok' => false, 'error' => 'no_extension', 'message' => "Filename has no extension: {$name}"];
        }

        $allowed = $this->allowedExtensions();
        if ($allowed !== [] && !\in_array($extension, $allowed, true)) {
            return [
                'ok' => false,
                'error' => 'extension_not_allowed',
                'message' => "Extension '{$extension}' is not in the Contao upload whitelist (tl_settings.uploadTypes).",
                'allowed_extensions' => $allowed,
            ];
        }

        $max = $this->maxFileSize();
        if ($max > 0 && $contentSize > $max) {
            return [
                'ok' => false,
                'error' => 'file_too_large',
                'message' => "File size {$contentSize} bytes exceeds tl_settings.maxFileSize ({$max} bytes).",
                'max_file_size' => $max,
                'actual_size' => $contentSize,
            ];
        }

        // A name like `invoice.php.jpg` passes the whitelist on its LAST
        // extension while still being a .php file to a server configured with
        // `AddHandler application/x-httpd-php .php` — a legacy setting, still
        // found in shared hosting. Contao's whitelist only ever looks at the
        // last segment, so the check belongs here.
        foreach (array_slice(explode('.', strtolower($name)), 1, -1) as $inner) {
            if (\in_array($inner, self::EXECUTABLE_EXTENSIONS, true)) {
                return [
                    'ok' => false,
                    'error' => 'invalid_filename',
                    'message' => \sprintf(
                        'The name carries "%s" as an inner extension (%s). Some server configurations execute by an inner extension, so this is refused regardless of the final one.',
                        $inner,
                        $name,
                    ),
                ];
            }
        }

        return ['ok' => true];
    }

    /**
     * Extensions a web server might execute rather than serve. Not a complete
     * list of dangerous types — a complete list does not exist — but the ones
     * that turn a stored file into code.
     *
     * @var list<string>
     */
    private const EXECUTABLE_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar',
        'pl', 'py', 'cgi', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'jspx', 'htaccess',
    ];

    /**
     * Markup types that the browser runs when it fetches them from `files/`,
     * and for which there is no sanitiser — so active content is refused.
     *
     * SVG is not in this list: it is cleaned by {@see SvgSanitizer}, the same
     * way the Contao backend cleans it, instead of being refused by pattern.
     *
     * @var list<string>
     */
    private const ACTIVE_MARKUP_EXTENSIONS = ['html', 'htm', 'xhtml', 'xml'];

    /** Extensions {@see SvgSanitizer} handles. */
    public const SVG_EXTENSIONS = ['svg', 'svgz'];

    /**
     * How far into the file a signature may start. Only PDF uses it: the
     * format allows the `%PDF-` header anywhere in the first kilobyte, and
     * generators that put a BOM or a blank line in front of it exist.
     */
    private const PDF_HEADER_WINDOW = 1024;

    /**
     * What the first bytes of a file must look like for the extension it
     * claims. Only formats with an unambiguous signature — a text format has
     * none, and guessing one would reject legitimate files.
     *
     * @var array<string, list<string>>
     */
    private const MAGIC = [
        'jpg' => ["\xFF\xD8\xFF"],
        'jpeg' => ["\xFF\xD8\xFF"],
        'png' => ["\x89PNG\r\n\x1A\n"],
        'gif' => ['GIF87a', 'GIF89a'],
        'webp' => ['RIFF'],
        'pdf' => ['%PDF-'],
        'zip' => ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"],
        'gz' => ["\x1F\x8B"],
        'svgz' => ["\x1F\x8B"],
        'ico' => ["\x00\x00\x01\x00"],
        'bmp' => ['BM'],
        'mp4' => ["\x00\x00\x00"],
        'woff' => ['wOFF'],
        'woff2' => ['wOF2'],
    ];

    /**
     * Checks the CONTENT, which the name cannot tell us anything about.
     *
     * Two questions, both of which an extension whitelist leaves open:
     *
     *   1. Does an image actually contain an image? A file named `logo.png`
     *      whose body is HTML is served from `files/` as whatever the server
     *      sniffs, and the site then hosts the attacker's markup on its own
     *      origin.
     *   2. Does an SVG or HTML file carry script? Contao's default upload
     *      types include `svg`, and an SVG is a document: `<script>`, event
     *      handlers and external entities all run when the file is opened
     *      directly. That is stored XSS on the site's own domain.
     *
     * Stricter than the backend upload mask on purpose. A person clicking
     * "upload" chose the file; an agent can be talked into it by text it read
     * somewhere — which is exactly the path {@see \Netzhirsch\ContaoMcpBundle\Security\UntrustedContent}
     * exists to flag. An operator who really needs a scripted SVG can still
     * add it through the backend.
     *
     * @return array{ok: bool, error?: string, message?: string}
     */
    public function validateContent(string $filename, string $bytes): array
    {
        $extension = strtolower(pathinfo(trim($filename), \PATHINFO_EXTENSION));

        if (\in_array($extension, self::ACTIVE_MARKUP_EXTENSIONS, true)) {
            $found = self::activeMarkupIn($bytes);
            if ($found !== null) {
                return [
                    'ok' => false,
                    'error' => 'active_content_refused',
                    'message' => \sprintf(
                        'This %s contains %s. Served from files/ it would run on the site\'s own origin, so it is refused here. Remove it, or upload the file through the Contao backend if that is really intended.',
                        $extension,
                        $found,
                    ),
                ];
            }
        }

        $signatures = self::MAGIC[$extension] ?? null;
        if ($signatures !== null) {
            foreach ($signatures as $signature) {
                if (self::startsLike($extension, $bytes, $signature)) {
                    return ['ok' => true];
                }
            }

            return [
                'ok' => false,
                'error' => 'content_does_not_match_extension',
                'message' => \sprintf(
                    'The content does not start like a %s file. Either the name is wrong or the file is something else; both are refused because the server decides what to serve by the name.',
                    $extension,
                ),
            ];
        }

        return ['ok' => true];
    }

    /**
     * Whether {@see validateContent()} has to see the whole file for this
     * extension. Markup can carry script anywhere; every other check looks at
     * the first bytes only, so a large file is never read whole for it.
     */
    public static function needsFullContent(string $extension): bool
    {
        return \in_array(strtolower($extension), self::ACTIVE_MARKUP_EXTENSIONS, true);
    }

    /**
     * Whether the file begins with the signature its extension promises.
     *
     * WebP is a RIFF container like WAV and AVI, so "RIFF" alone says nothing;
     * the format name sits at byte 8. A PDF header may start anywhere in the
     * first kilobyte.
     */
    private static function startsLike(string $extension, string $bytes, string $signature): bool
    {
        return match ($extension) {
            'pdf' => str_contains(substr($bytes, 0, self::PDF_HEADER_WINDOW), $signature),
            'webp' => str_starts_with($bytes, $signature) && substr($bytes, 8, 4) === 'WEBP',
            default => str_starts_with($bytes, $signature),
        };
    }

    /**
     * Names what makes a markup file active, or null when nothing does.
     *
     * A REJECTION, not a sanitiser — used for HTML/XML, for which there is no
     * sanitiser, and for SVG only on a Contao too old to ship one (see
     * {@see SvgSanitizer}). Sanitising markup correctly is a project of its own,
     * and a sanitiser that is 95% right is worse than a refusal.
     */
    public static function activeMarkupIn(string $bytes): ?string
    {
        // Script can sit anywhere in the document, so the whole body is
        // scanned. These files are small by nature.
        $haystack = strtolower($bytes);

        if (str_contains($haystack, '<script')) {
            return 'a <script> element';
        }
        if (str_contains($haystack, '<foreignobject')) {
            return 'a <foreignObject> element, which embeds arbitrary HTML';
        }
        // Only an actual declaration. This used to fire on any DOCTYPE as soon
        // as the word "entity" appeared anywhere in the file — a logo titled
        // "Corporate Identity" was refused as XXE.
        if (str_contains($haystack, '<!entity')) {
            return 'an entity declaration (XXE)';
        }
        if (preg_match('/\son[a-z]+\s*=/', $haystack) === 1) {
            return 'an inline event handler (on… attribute)';
        }
        if (preg_match('/(href|xlink:href|src)\s*=\s*["\']?\s*javascript:/', $haystack) === 1) {
            return 'a javascript: URL';
        }

        return null;
    }

    /**
     * @return array{ok: bool, error?: string, message?: string}
     */
    public function validateFolderName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => 'invalid_name', 'message' => 'Folder name is empty.'];
        }
        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            return ['ok' => false, 'error' => 'invalid_name', 'message' => "Folder name contains illegal characters: {$name}"];
        }
        if ($name === '.' || $name === '..') {
            return ['ok' => false, 'error' => 'invalid_name', 'message' => 'Folder name . / .. is reserved.'];
        }

        return ['ok' => true];
    }

    /**
     * Decodes a meta blob (the {locale: {title, alt, …}} dict Contao stores)
     * into a JSON-friendly array.
     *
     * @return array<string, array<string, string>>
     */
    public static function decodeMeta(mixed $value): array
    {
        $arr = StringUtil::deserialize($value, true);
        if (!\is_array($arr)) {
            return [];
        }

        $out = [];
        foreach ($arr as $locale => $fields) {
            if (!\is_string($locale) || !\is_array($fields)) {
                continue;
            }
            $row = [];
            foreach ($fields as $k => $v) {
                $row[(string) $k] = (string) $v;
            }
            $out[$locale] = $row;
        }

        return $out;
    }

    /**
     * Inverse of decodeMeta. Accepts both arrays and stdClass.
     *
     * @return string|null Serialised PHP value ready for SQL, or null if empty.
     */
    public static function encodeMeta(mixed $input): ?string
    {
        if ($input instanceof \stdClass) {
            $input = (array) $input;
        }
        if (!\is_array($input)) {
            throw new \InvalidArgumentException("'meta' must be a dict<locale, dict<field, string>>.");
        }

        $clean = [];
        foreach ($input as $locale => $fields) {
            if ($fields instanceof \stdClass) {
                $fields = (array) $fields;
            }
            if (!\is_string($locale) || !\is_array($fields)) {
                continue;
            }
            $row = [];
            foreach ($fields as $k => $v) {
                if (!\is_string($k) || $k === '') {
                    continue;
                }
                $row[$k] = (string) $v;
            }
            if ($row !== []) {
                $clean[$locale] = $row;
            }
        }

        return $clean === [] ? null : serialize($clean);
    }
}
