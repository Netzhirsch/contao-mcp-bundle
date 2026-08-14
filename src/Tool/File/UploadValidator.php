<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\File;

use Contao\StringUtil;

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

        return ['ok' => true];
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
