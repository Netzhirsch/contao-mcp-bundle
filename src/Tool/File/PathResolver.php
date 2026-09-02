<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\File;

/**
 * Resolves user-provided file paths to safe absolute filesystem paths.
 *
 * Tool parameters use paths RELATIVE to the upload folder (typically `files`),
 * so the LLM can pass `content/foo.jpg` instead of `files/content/foo.jpg` —
 * less ambiguous and matches what users see in the Backend.
 *
 *   Tool param   "content/foo.jpg"
 *   DBAFS path   "files/content/foo.jpg"
 *   Filesystem   "C:/laragon/.../contao-mcp/files/content/foo.jpg"
 *
 * Validates against path traversal, NUL bytes, leading slashes, and
 * absolute paths.
 */
final class PathResolver
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $uploadPath,
    ) {
    }

    public function uploadPath(): string
    {
        return $this->uploadPath;
    }

    /**
     * Resolves a user-supplied relative path to an absolute filesystem path,
     * verifying it's inside the upload tree. Works for both existing paths
     * (returns the canonicalized path) and not-yet-existing paths (returns
     * the join after validating the parent directory).
     *
     * @throws \InvalidArgumentException on invalid / unsafe paths
     */
    public function resolveAbsolute(string $relative): string
    {
        $relative = $this->normaliseRelative($relative);

        $absolute = $this->projectDir.\DIRECTORY_SEPARATOR.$this->uploadPath;
        if ($relative !== '') {
            $absolute .= \DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $relative);
        }

        $base = realpath($this->projectDir.\DIRECTORY_SEPARATOR.$this->uploadPath);
        if ($base === false) {
            throw new \RuntimeException("Upload base directory does not exist: {$this->uploadPath}");
        }

        $real = realpath($absolute);
        if ($real !== false) {
            // Existing path — must be inside base.
            if (!self::isInside($real, $base)) {
                throw new \InvalidArgumentException("Path escapes upload directory: {$relative}");
            }
            return $real;
        }

        // Not-yet-existing path (e.g. upload target). Walk up to the deepest
        // existing ancestor and verify THAT lives inside base — otherwise
        // the candidate parent itself was constructed with `..`.
        $parent = \dirname($absolute);
        while ($parent !== '' && $parent !== '.' && $parent !== '/' && !is_dir($parent)) {
            $next = \dirname($parent);
            if ($next === $parent) {
                break;
            }
            $parent = $next;
        }
        $realParent = realpath($parent);
        if ($realParent === false || !self::isInside($realParent, $base)) {
            throw new \InvalidArgumentException("Path escapes upload directory: {$relative}");
        }

        return $absolute;
    }

    /**
     * The DBAFS / tl_files.path representation: includes the upload-dir prefix.
     */
    public function toDbafsPath(string $relative): string
    {
        $relative = $this->normaliseRelative($relative);
        return $relative === '' ? $this->uploadPath : $this->uploadPath.'/'.$relative;
    }

    /**
     * Inverse: strip the upload-dir prefix.
     */
    public function fromDbafsPath(string $dbafsPath): string
    {
        $prefix = $this->uploadPath.'/';
        if (str_starts_with($dbafsPath, $prefix)) {
            return substr($dbafsPath, \strlen($prefix));
        }
        if ($dbafsPath === $this->uploadPath) {
            return '';
        }

        return $dbafsPath;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function normaliseRelative(string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        $relative = trim($relative, '/');

        if ($relative === '') {
            return '';
        }
        if (str_contains($relative, "\0")) {
            throw new \InvalidArgumentException('Path contains NUL byte.');
        }
        // Reject explicit traversal segments early — realpath would catch
        // them too, but a clearer error helps the LLM.
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException("Path contains invalid segment: '{$segment}'.");
            }
        }
        // Reject Windows drive-letter or UNC paths.
        if (preg_match('#^[A-Za-z]:#', $relative) === 1 || str_starts_with($relative, '//')) {
            throw new \InvalidArgumentException('Absolute paths are not allowed.');
        }

        return $this->stripRedundantUploadPrefix($relative);
    }

    /**
     * Accept the DBAFS spelling of a path as well as the upload-relative one.
     *
     * `files_search` returns both — `path` without the upload directory and
     * `dbafs_path` with it — and handing the second to a tool that expects the
     * first produced `not_found` for a file the same search had just listed.
     * That reads like a missing file rather than a naming mismatch.
     *
     * The prefix is only dropped when keeping it resolves to nothing and
     * dropping it resolves to something, so a genuine `files/files/…` folder
     * still wins and no path silently changes meaning.
     */
    private function stripRedundantUploadPrefix(string $relative): string
    {
        $prefix = $this->uploadPath.'/';

        if ($this->uploadPath === '' || !str_starts_with($relative, $prefix)) {
            return $relative;
        }

        $base = $this->projectDir.\DIRECTORY_SEPARATOR.$this->uploadPath.\DIRECTORY_SEPARATOR;
        $asGiven = $base.str_replace('/', \DIRECTORY_SEPARATOR, $relative);
        $stripped = substr($relative, \strlen($prefix));

        if (file_exists($asGiven) || !file_exists($base.str_replace('/', \DIRECTORY_SEPARATOR, $stripped))) {
            return $relative;
        }

        return $stripped;
    }

    private static function isInside(string $child, string $parent): bool
    {
        // Windows-friendly comparison: case-insensitive on Windows, exact
        // elsewhere. realpath() already normalises separators.
        // Suffix with separator so e.g. `.../files-secret/foo` does NOT
        // match base `.../files` — the explicit `..` segment check in
        // normaliseRelative() catches the common case but a symlink
        // pointing outside-then-back would slip past without this guard
        // (audit finding M1, 2026-05-21).
        $parent = rtrim($parent, \DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR;
        // `$child === $parent` (same dir) is also "inside".
        $child = rtrim($child, \DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR;
        if (\PHP_OS_FAMILY === 'Windows') {
            return str_starts_with(strtolower($child), strtolower($parent));
        }

        return str_starts_with($child, $parent);
    }
}
