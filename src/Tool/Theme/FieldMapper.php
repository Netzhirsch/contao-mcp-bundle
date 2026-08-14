<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Theme;

use Contao\FilesModel;
use Contao\StringUtil;
use Contao\ThemeModel;

/**
 * Maps the MCP-facing parameter dict to ThemeModel column writes.
 *
 * - name / author / templates: stored verbatim (strings).
 * - folders[]:                  list of paths → list of binary UUIDs → serialized.
 * - screenshot:                 single path → binary UUID.
 *
 * Returns a normalized `{errors: list, applied: int}` result so the Tool layer
 * can short-circuit on validation errors before persisting.
 */
final class FieldMapper
{
    /**
     * @return array{errors: list<string>, applied: int}
     */
    public function apply(ThemeModel $theme, array $input): array
    {
        $errors = [];
        $applied = 0;

        if (\array_key_exists('name', $input)) {
            $value = (string) $input['name'];
            if ($value === '') {
                $errors[] = 'name must not be empty';
            } else {
                $theme->name = mb_substr($value, 0, 128);
                ++$applied;
            }
        }

        if (\array_key_exists('author', $input)) {
            $value = (string) $input['author'];
            if ($value === '') {
                $errors[] = 'author must not be empty';
            } else {
                $theme->author = mb_substr($value, 0, 128);
                ++$applied;
            }
        }

        if (\array_key_exists('templates', $input)) {
            $theme->templates = (string) $input['templates'];
            ++$applied;
        }

        if (\array_key_exists('folders', $input)) {
            [$serialized, $foldersErr] = $this->mapFolders($input['folders']);
            if ($foldersErr !== null) {
                $errors[] = $foldersErr;
            } else {
                $theme->folders = $serialized;
                ++$applied;
            }
        }

        if (\array_key_exists('screenshot', $input)) {
            [$uuid, $screenshotErr] = $this->mapScreenshot($input['screenshot']);
            if ($screenshotErr !== null) {
                $errors[] = $screenshotErr;
            } else {
                $theme->screenshot = $uuid;
                ++$applied;
            }
        }

        return ['errors' => $errors, 'applied' => $applied];
    }

    /**
     * @return array{0: ?string, 1: ?string} [serialized-blob, error]
     */
    private function mapFolders(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [null, null];
        }
        if (!\is_array($value) || !array_is_list($value)) {
            return [null, 'folders must be a list of file paths'];
        }

        $uuids = [];
        foreach ($value as $path) {
            if (!\is_string($path) || $path === '') {
                return [null, 'folders entries must be non-empty strings (file paths)'];
            }
            $model = FilesModel::findByPath($path);
            if ($model === null) {
                return [null, sprintf('folders entry "%s" does not exist in tl_files — register it first via file_upload / folder_create', $path)];
            }
            $uuids[] = $model->uuid;
        }

        return [serialize($uuids), null];
    }

    /**
     * @return array{0: ?string, 1: ?string} [binary-uuid, error]
     */
    private function mapScreenshot(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [null, null];
        }
        if (!\is_string($value)) {
            return [null, 'screenshot must be a file path or null'];
        }
        $model = FilesModel::findByPath($value);
        if ($model === null) {
            return [null, sprintf('screenshot file "%s" does not exist in tl_files', $value)];
        }
        if ($model->type !== 'file') {
            return [null, 'screenshot must point at a file, not a folder'];
        }

        return [(string) $model->uuid, null];
    }

    /**
     * Helper for callers — serializes a list of UUIDs the same way Contao expects.
     */
    public static function uuidListToBlob(array $uuids): string
    {
        return serialize($uuids);
    }

    /**
     * Helper for callers — deserializes a blob back into a list.
     *
     * @return list<string>
     */
    public static function blobToUuidList(mixed $blob): array
    {
        $list = StringUtil::deserialize($blob, true);

        return array_values(array_filter(
            $list,
            static fn ($v): bool => \is_string($v) && $v !== '',
        ));
    }
}
