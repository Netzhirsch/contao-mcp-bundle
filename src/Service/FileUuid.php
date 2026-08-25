<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Contao\FilesModel;
use Contao\StringUtil;
use Contao\Validator;

/**
 * Turns what a caller can reasonably send for a `fileTree` field into the
 * 16-byte binary UUID the column actually holds.
 *
 * Contao stores file references as `binary(16)`. The backend picker writes that
 * shape; a programmatic Model write does not, because DCA save_callbacks only
 * run for form submissions. So a raw 36-character UUID string or a `files/…`
 * path goes straight into a 16-byte column and MySQL answers
 * "SQLSTATE[22001]: Data too long" — the write fails hard rather than
 * truncating, which is the better of the two, but unusable either way.
 *
 * Accepted, matching what the backend picker itself produces:
 *   - string UUID, with or without dashes
 *   - a path below the upload directory (`files/layout/logo.png`)
 *   - an already-binary UUID (idempotent, so re-writing a read value works)
 *   - empty string or null → null, i.e. "no file"
 */
final class FileUuid
{
    /**
     * @throws \InvalidArgumentException when the value is neither a UUID nor a known path
     */
    public static function toBinary(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (!\is_string($value)) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" expects a file UUID or a path below the upload directory, got %s.',
                $field,
                get_debug_type($value),
            ));
        }

        // Already binary — a value read back from the database, handed straight
        // back in. Checked first: a 16-byte blob can otherwise look like junk.
        if (Validator::isBinaryUuid($value)) {
            return $value;
        }

        if (Validator::isStringUuid($value)) {
            return StringUtil::uuidToBin($value);
        }

        // A path, the form a human (and an LLM reading files_list) actually has.
        $file = FilesModel::findByPath($value);
        if ($file !== null && $file->uuid !== null) {
            return (string) $file->uuid;
        }

        throw new \InvalidArgumentException(sprintf(
            '"%s": "%s" is neither a file UUID nor a path known to tl_files. Use files_list / files_search to find the file, then pass its uuid or its path.',
            $field,
            $value,
        ));
    }

    /**
     * The serialised `list<binary>` a multiple fileTree column holds.
     *
     * @throws \InvalidArgumentException
     */
    public static function toBinaryList(mixed $value, string $field): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        if (!\is_array($value)) {
            $value = [$value];
        }

        $binary = [];
        foreach ($value as $entry) {
            $one = self::toBinary($entry, $field);
            if ($one !== null) {
                $binary[] = $one;
            }
        }

        return $binary === [] ? '' : serialize($binary);
    }
}
