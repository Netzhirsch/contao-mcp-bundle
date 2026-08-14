<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\File;

use Contao\FilesModel;
use Contao\StringUtil;

/**
 * Converts a tl_files row (FilesModel) into the flat shape we expose over
 * MCP. UUIDs are returned as their string form, paths as relative-to-upload
 * (matching what the tool parameters accept), and the meta blob is decoded
 * into a JSON-friendly dict.
 */
final class Serializer
{
    public function __construct(
        private readonly PathResolver $paths,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(FilesModel $f): array
    {
        $absolute = $this->paths->resolveAbsolute($this->paths->fromDbafsPath((string) $f->path));
        $stats = is_file($absolute) ? @stat($absolute) : false;

        return [
            'id' => (int) $f->id,
            'uuid' => self::uuidToString($f->uuid),
            'pid_uuid' => $f->pid ? self::uuidToString($f->pid) : null,
            'type' => (string) $f->type,
            'name' => (string) $f->name,
            'extension' => (string) $f->extension,
            'path' => $this->paths->fromDbafsPath((string) $f->path),
            'dbafs_path' => (string) $f->path,
            'hash' => (string) $f->hash,
            'size' => $stats !== false ? (int) $stats['size'] : null,
            'last_modified' => $f->lastModified !== null ? (int) $f->lastModified : ($stats !== false ? (int) $stats['mtime'] : null),
            'meta' => UploadValidator::decodeMeta($f->meta),
            'important_part' => [
                'x' => (float) $f->importantPartX,
                'y' => (float) $f->importantPartY,
                'width' => (float) $f->importantPartWidth,
                'height' => (float) $f->importantPartHeight,
            ],
        ];
    }

    /**
     * For the filesystem-scan variant of files_list — when a row may not
     * (yet) exist in tl_files. We still want a uniform shape.
     *
     * @return array<string, mixed>
     */
    public function fromFilesystem(string $absolute, string $relativeToUpload): array
    {
        $stats = @stat($absolute);
        $isDir = is_dir($absolute);
        $name = basename($absolute);

        return [
            'id' => null,
            'uuid' => null,
            'pid_uuid' => null,
            'type' => $isDir ? 'folder' : 'file',
            'name' => $name,
            'extension' => $isDir ? '' : strtolower(pathinfo($name, \PATHINFO_EXTENSION)),
            'path' => $relativeToUpload,
            'dbafs_path' => $this->paths->toDbafsPath($relativeToUpload),
            'hash' => '',
            'size' => $stats !== false && !$isDir ? (int) $stats['size'] : null,
            'last_modified' => $stats !== false ? (int) $stats['mtime'] : null,
            'meta' => new \stdClass(),
            'important_part' => null,
            'not_synced' => true,
        ];
    }

    public static function uuidToString(string|null $binaryUuid): ?string
    {
        if ($binaryUuid === null || $binaryUuid === '') {
            return null;
        }

        return StringUtil::binToUuid($binaryUuid);
    }
}
