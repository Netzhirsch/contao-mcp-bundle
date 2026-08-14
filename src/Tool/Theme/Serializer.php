<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Theme;

use Contao\FilesModel;
use Contao\StringUtil;
use Contao\ThemeModel;

/**
 * Renders ThemeModel rows for MCP responses. Binary UUID fields (folders[],
 * screenshot) are translated back to file paths so the LLM sees a stable
 * filesystem location instead of opaque binary blobs.
 */
final class Serializer
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(ThemeModel $theme): array
    {
        return [
            'id' => (int) $theme->id,
            'name' => (string) $theme->name,
            'author' => (string) $theme->author,
            'templates' => (string) $theme->templates,
            'tstamp' => (int) $theme->tstamp,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function full(ThemeModel $theme): array
    {
        return self::summary($theme) + [
            'folders' => self::resolveFolderPaths($theme->folders),
            'screenshot' => self::resolveSinglePath($theme->screenshot),
        ];
    }

    /**
     * Returns the on-disk paths of the file UUIDs stored in a multi-fileTree blob.
     *
     * @return list<string>
     */
    private static function resolveFolderPaths(mixed $blob): array
    {
        $uuids = StringUtil::deserialize($blob, true);
        $out = [];
        foreach ($uuids as $uuid) {
            if (!\is_string($uuid) || $uuid === '') {
                continue;
            }
            $model = FilesModel::findByUuid($uuid);
            if ($model !== null) {
                $out[] = (string) $model->path;
            }
        }

        return $out;
    }

    private static function resolveSinglePath(mixed $uuid): ?string
    {
        if (!\is_string($uuid) || $uuid === '') {
            return null;
        }
        $model = FilesModel::findByUuid($uuid);

        return $model !== null ? (string) $model->path : null;
    }
}
