<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\File;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Util\SymlinkUtil;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Dbafs;
use Contao\File as ContaoFile;
use Contao\FilesModel;
use Contao\Folder as ContaoFolder;
use Contao\StringUtil;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * MCP facade for tl_files (Contao's file manager).
 *
 * Paths are relative to the upload directory (`files/` by default). All
 * write operations are followed by a Dbafs sync so tl_files stays in
 * lock-step with the filesystem.
 *
 * Security:
 *   - PathResolver rejects path-traversal (.. / NUL / absolute paths).
 *   - UploadValidator enforces tl_settings.uploadTypes + tl_settings.maxFileSize.
 *   - file_get caps base64 payload at 1 MB to avoid blowing up the LLM context.
 */
final class Tool
{
    /** Hard cap for base64-encoded content returned by file_get. */
    private const FILE_GET_MAX_BYTES = 1_048_576; // 1 MB

    /**
     * Per-locale value shape for the localised-meta param. Used as
     * `additionalProperties` so the param emits a CONCRETE top-level
     * `type: object` (not `mixed`) — fragile MCP clients drop typeless
     * properties (CWA-26 family). Param-level #[Schema] needs the explicit
     * fields; `definition` is NOT honoured per-parameter.
     *
     * @var array<string, mixed>
     */
    private const META_ITEM_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'alt' => ['type' => 'string'],
            'link' => ['type' => 'string'],
            'caption' => ['type' => 'string'],
        ],
    ];

    /**
     * Hard ceiling on files visited by files_search before we abort the scan.
     * Prevents a runaway glob (e.g. `**` on a million-file tree) from blocking
     * the ReactPHP event loop. 100k is well above any sane Contao upload tree.
     */
    private const SEARCH_MAX_VISITED = 100_000;

    /** Hard ceiling for a source_url pull before the configured maxFileSize check. */
    private const SOURCE_URL_MAX_BYTES = 64 * 1024 * 1024; // 64 MB

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly LoggerInterface $logger,
        private readonly PathResolver $paths,
        private readonly Serializer $serializer,
        private readonly AuthorResolver $authorResolver,
        private readonly string $projectDir,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, count: int, path: string, limit: int, offset: int}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'files_list',
        description: 'Lists files and folders under the given path (relative to the upload folder, default `files/`). Pass path="" for the root. Use type="files"|"folders"|"all" to filter. Each entry includes type, name, extension, size, last_modified, uuid, hash, meta. Returns up to 200 entries per page.',
    )]
    public function list(string $path = '', int $limit = 100, int $offset = 0, string $type = 'all'): array
    {
        $this->framework->initialize();

        try {
            $absolute = $this->paths->resolveAbsolute($path);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_path', 'message' => $e->getMessage()];
        }

        if (!is_dir($absolute)) {
            return ['error' => 'not_a_directory', 'message' => "Path is not a directory: {$path}"];
        }

        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);
        $type = \in_array($type, ['all', 'files', 'folders'], true) ? $type : 'all';

        $entries = @scandir($absolute) ?: [];
        $entries = array_values(array_filter($entries, static fn (string $n): bool => $n !== '.' && $n !== '..'));
        sort($entries, \SORT_NATURAL | \SORT_FLAG_CASE);

        $out = [];
        $matched = 0;
        foreach ($entries as $name) {
            $childAbs = $absolute.\DIRECTORY_SEPARATOR.$name;
            $isDir = is_dir($childAbs);
            if ($type === 'files' && $isDir) continue;
            if ($type === 'folders' && !$isDir) continue;
            ++$matched;
            if ($matched <= $offset) {
                continue;
            }
            if (\count($out) >= $limit) {
                continue;
            }
            $relative = ($path === '' ? '' : trim($path, '/').'/').$name;
            $dbafsPath = $this->paths->toDbafsPath($relative);
            $model = FilesModel::findByPath($dbafsPath);
            $out[] = $model !== null
                ? $this->serializer->summary($model)
                : $this->serializer->fromFilesystem($childAbs, $relative);
        }

        return [
            'items' => $out,
            'count' => \count($out),
            'total' => $matched,
            'path' => $path,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, count: int, total: int, query: string, path: string, case_sensitive: bool, type: string, truncated: bool}|array{error: string, message: string}
     */
    #[McpTool(
        name: 'files_search',
        description: <<<'DESC'
            Recursively searches the upload tree (default: `files/`) for entries
            whose RELATIVE PATH matches a glob pattern. Use this instead of
            files_list when you don't know which folder a file lives in.

            Glob syntax (POSIX-style, extended with `**` and `{a,b}`):
              *       — matches any chars except `/`
              **      — matches any number of path segments (incl. zero)
              ?       — matches exactly one char except `/`
              [abc]   — character class (negate with `[!abc]`)
              {a,b,c} — alternation. Alternatives may contain glob chars
                        themselves (e.g. `*.{jpg,png}`). Nesting is NOT
                        supported — `{a,{b,c}}` is rejected.

            Examples:
              query="*.jpg"              — every .jpg anywhere in the tree
              query="**/*.{jpg,png,svg}" — every image of those types anywhere
              query="banner-*.png"       — every file named banner-*.png anywhere
              query="content/news-*"     — only direct children of content/ matching news-*
              query="content/**/*.png"   — every .png at any depth under content/
              query="{2024,2025}-*.pdf"  — files starting with either year prefix
              query="**/banner/**/*.png" — every .png anywhere under a banner/ folder

            Hint: if your query contains NO `/`, it's matched against the file's
            basename at ANY depth (LLM-friendly default). If it contains a `/`,
            the full relative path is matched and `*` does NOT cross slashes.

            Parameters:
              - query:          required glob (matched against the path RELATIVE to `path` or upload root)
              - path:           optional sub-directory of files/ to limit the scan (perf + scoping)
              - type:           "all" | "files" | "folders" — what to return (default "files")
              - case_sensitive: default false (Backend / Windows convention)
              - limit + offset: pagination over the matched set

            Returns the same entry shape as files_list (uuid, hash, size, meta, …).
            Symlinks are NOT followed. Scan is hard-capped at 100k visited entries to
            keep the daemon responsive. `truncated: true` means more matches exist
            past offset+limit.
        DESC,
    )]
    public function search(
        string $query,
        ?string $path = null,
        string $type = 'files',
        bool $case_sensitive = false,
        int $limit = 100,
        int $offset = 0,
    ): array {
        $this->framework->initialize();

        if (trim($query) === '') {
            return ['error' => 'invalid_input', 'message' => 'query must not be empty.'];
        }
        if (!\in_array($type, ['all', 'files', 'folders'], true)) {
            return ['error' => 'invalid_input', 'message' => 'type must be one of: all, files, folders.'];
        }

        $relRoot = $path !== null ? trim($path, '/') : '';

        try {
            $absoluteRoot = $this->paths->resolveAbsolute($relRoot);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_path', 'message' => $e->getMessage()];
        }
        if (!is_dir($absoluteRoot)) {
            return ['error' => 'not_a_directory', 'message' => "Search root is not a directory: {$relRoot}"];
        }

        // If the query has no `/`, treat it as a basename pattern that matches
        // at any depth (`*.jpg`, `banner-*.png`, `?eadme.md`). This matches
        // what most CLI tools do (`fd '*.jpg'`, `git ls-files '*.jpg'`) and
        // is more LLM-friendly than strict POSIX which would only match the
        // root level. To force path-anchored matching, use a query with a
        // `/` (e.g. `news/banner-*.jpg`) or a `**/` prefix.
        $matchBasenameOnly = !str_contains($query, '/');
        try {
            $regex = self::globToRegex($query, $case_sensitive);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_query', 'message' => $e->getMessage()];
        }

        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);

        $finder = (new Finder())
            ->in($absoluteRoot)
            ->ignoreDotFiles(true)        // .git, .DS_Store etc.
            ->ignoreVCS(true)
            ;
        // Note: Finder::followLinks() is a binary toggle (no arg → enables).
        // We INTENTIONALLY do NOT call it — Finder defaults to NOT following
        // symlinks, which is what we want to keep traversal inside the upload
        // tree.

        if ($type === 'files') {
            $finder->files();
        } elseif ($type === 'folders') {
            $finder->directories();
        }

        $matched = 0;
        $visited = 0;
        $aborted = false;
        $out = [];

        foreach ($finder as $entry) {
            if (++$visited > self::SEARCH_MAX_VISITED) {
                $aborted = true;
                break;
            }
            // Symfony Finder gives forward-slash relative paths on all OSes
            // when iterating after ->in(); double-check & normalise.
            $rel = str_replace('\\', '/', $entry->getRelativePathname());
            $haystack = $matchBasenameOnly ? basename($rel) : $rel;
            if (preg_match($regex, $haystack) !== 1) {
                continue;
            }
            ++$matched;
            if ($matched <= $offset) {
                continue;
            }
            if (\count($out) >= $limit) {
                // Keep counting matches so `total` is accurate, but stop emitting.
                continue;
            }

            // Build the relative path FROM the upload root (not from the
            // search subdir) so it matches what files_list / file_get expect.
            $relFromUploadRoot = $relRoot === '' ? $rel : $relRoot.'/'.$rel;
            $dbafsPath = $this->paths->toDbafsPath($relFromUploadRoot);
            $model = FilesModel::findByPath($dbafsPath);

            $out[] = $model !== null
                ? $this->serializer->summary($model)
                : $this->serializer->fromFilesystem($entry->getRealPath() ?: $entry->getPathname(), $relFromUploadRoot);
        }

        return [
            'items' => $out,
            'count' => \count($out),
            'total' => $matched,
            'query' => $query,
            'path' => $relRoot,
            'type' => $type,
            'case_sensitive' => $case_sensitive,
            'truncated' => $matched > $offset + \count($out),
            'scan_aborted_at_cap' => $aborted,
        ];
    }

    /**
     * Converts a POSIX-style glob (with `**` extension AND brace expansion)
     * into an anchored PCRE regex. Supports:
     *   *        — any chars except `/`
     *   **       — any number of path segments (incl. zero)
     *   ?        — exactly one char except `/`
     *   [abc]    — character class (with `[!abc]` for negation)
     *   {a,b,c}  — alternation (brace expansion)
     *
     * Brace alternatives may themselves contain glob metachars (e.g.
     * `*.{jpg,png}`, `{img-*,banner-*}.png`). Nested braces are NOT supported
     * — `{a,{b,c}}` raises `invalid_query`.
     *
     * @throws \InvalidArgumentException on unbalanced classes/braces or nested braces
     */
    private static function globToRegex(string $glob, bool $caseSensitive): string
    {
        $glob = str_replace('\\', '/', $glob);
        $glob = ltrim($glob, '/');

        return '#^'.self::globBody($glob, allowBraces: true).'$#'.($caseSensitive ? '' : 'i');
    }

    /**
     * Inner converter — emits the regex BODY (no anchors / no flags) so it
     * can be called recursively for each brace alternative.
     *
     * @throws \InvalidArgumentException
     */
    private static function globBody(string $glob, bool $allowBraces): string
    {
        $regex = '';
        $i = 0;
        $len = \strlen($glob);

        while ($i < $len) {
            $c = $glob[$i];

            if ($c === '*') {
                if ($i + 1 < $len && $glob[$i + 1] === '*') {
                    // `**` matches any number of path segments including zero.
                    // Eat an optional trailing `/` so "**/foo" matches "foo".
                    $i += 2;
                    if ($i < $len && $glob[$i] === '/') {
                        $regex .= '(?:.*/)?';
                        ++$i;
                    } else {
                        $regex .= '.*';
                    }
                    continue;
                }
                $regex .= '[^/]*';
                ++$i;
                continue;
            }

            if ($c === '?') {
                $regex .= '[^/]';
                ++$i;
                continue;
            }

            if ($c === '[') {
                $end = strpos($glob, ']', $i + 1);
                if ($end === false) {
                    throw new \InvalidArgumentException('Unbalanced character class in glob: '.$glob);
                }
                $inner = substr($glob, $i + 1, $end - $i - 1);
                if (str_starts_with($inner, '!')) {
                    $inner = '^'.substr($inner, 1);
                }
                $regex .= '['.$inner.']';
                $i = $end + 1;
                continue;
            }

            if ($c === '{') {
                if (!$allowBraces) {
                    // Reached during recursion for a nested brace — caller's
                    // job to refuse. Defence-in-depth: explicit error.
                    throw new \InvalidArgumentException('Nested braces are not supported in glob: '.$glob);
                }

                // Find the matching `}`. We forbid nesting so any inner `{`
                // is a hard error.
                $end = $i + 1;
                while ($end < $len && $glob[$end] !== '}') {
                    if ($glob[$end] === '{') {
                        throw new \InvalidArgumentException('Nested braces are not supported in glob: '.$glob);
                    }
                    ++$end;
                }
                if ($end >= $len) {
                    throw new \InvalidArgumentException('Unbalanced brace in glob: '.$glob);
                }

                $inner = substr($glob, $i + 1, $end - $i - 1);
                $alternatives = explode(',', $inner);
                // Recursively convert each alternative so it can itself
                // contain `*`, `?`, `[abc]` etc. — but NOT another `{...}`.
                $altRegexes = array_map(
                    static fn (string $alt): string => self::globBody($alt, allowBraces: false),
                    $alternatives,
                );
                $regex .= '(?:'.implode('|', $altRegexes).')';
                $i = $end + 1;
                continue;
            }

            // Literal char — escape regex meta.
            if (\in_array($c, ['.', '(', ')', '+', '|', '^', '$', '@', '%', '\\', '/', '}'], true)) {
                $regex .= '\\'.$c;
            } else {
                $regex .= $c;
            }
            ++$i;
        }

        return $regex;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'file_get',
        description: 'Returns metadata for a single file or folder by path (relative to the upload folder). When include_content=true AND the file is < 1 MB, the response includes `content_base64`. For folders, content is never returned.',
    )]
    public function get(string $path, bool $include_content = false): array
    {
        $this->framework->initialize();

        try {
            $absolute = $this->paths->resolveAbsolute($path);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_path', 'message' => $e->getMessage()];
        }
        if (!file_exists($absolute)) {
            return ['error' => 'not_found', 'message' => "Path not found: {$path}"];
        }

        $dbafsPath = $this->paths->toDbafsPath($path);
        $model = FilesModel::findByPath($dbafsPath);
        $summary = $model !== null
            ? $this->serializer->summary($model)
            : $this->serializer->fromFilesystem($absolute, ltrim($path, '/'));

        if ($include_content && !is_dir($absolute)) {
            $size = (int) @filesize($absolute);
            if ($size > self::FILE_GET_MAX_BYTES) {
                $summary['content_omitted'] = [
                    'reason' => 'too_large',
                    'limit_bytes' => self::FILE_GET_MAX_BYTES,
                    'actual_bytes' => $size,
                ];
            } else {
                $bytes = @file_get_contents($absolute);
                if ($bytes !== false) {
                    $summary['content_base64'] = base64_encode($bytes);
                }
            }
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'file_upload',
        description: 'Uploads a new file. parent_path is the destination folder relative to the upload directory ("" for root). Provide the content EITHER as content_base64 (raw bytes, base-64) OR as source_url (the server pulls the file itself — REQUIRED for files above ~50 KB, since inline base64 is truncated by the MCP transport). source_url must be http/https to a public host (private/loopback/link-local addresses are refused). Validates extension against tl_settings.uploadTypes and size against tl_settings.maxFileSize. Refuses to overwrite by default (pass overwrite=true). meta is an optional {locale: {title, alt, link, caption}} dict. Logged to tl_log.',
    )]
    public function upload(
        string $parent_path,
        string $name,
        string $content_base64 = '',
        bool $overwrite = false,
        #[Schema(type: 'object', additionalProperties: self::META_ITEM_SCHEMA, description: 'Localised file metadata: {locale: {title, alt, link, caption}}. Example: {"de": {"title": "…"}}.')] mixed $meta = null,
        ?string $source_url = null,
    ): array {
        $this->framework->initialize();
        $validator = $this->makeValidator();

        try {
            $parentAbs = $this->paths->resolveAbsolute($parent_path);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_path', 'message' => $e->getMessage()];
        }
        if (!is_dir($parentAbs)) {
            return ['error' => 'parent_not_found', 'message' => "Parent folder is not a directory: {$parent_path}"];
        }

        // Obtain the bytes from exactly one source: a server-side URL pull
        // (robust for large files) or inline base64 (fine for small files).
        $hasUrl = $source_url !== null && $source_url !== '';
        if ($hasUrl && $content_base64 !== '') {
            return ['error' => 'invalid_input', 'message' => 'Provide either content_base64 OR source_url, not both.'];
        }
        if ($hasUrl) {
            [$bytes, $err] = $this->fetchFromUrl($source_url);
            if ($err !== null) {
                return $err;
            }
        } else {
            if ($content_base64 === '') {
                return ['error' => 'invalid_input', 'message' => 'Provide content_base64 (small files) or source_url (large files).'];
            }
            // Strip whitespace before strict decoding: MCP/LLM transports often
            // line-wrap or pad large base64 strings, and base64_decode(strict)
            // rejects ANY non-alphabet char (incl. \n) — a wrapped-but-valid
            // payload would otherwise fail as "invalid_base64". Tolerate padding.
            $clean = preg_replace('/\s+/', '', $content_base64) ?? $content_base64;
            $bytes = base64_decode($clean, true);
            if ($bytes === false) {
                // Diagnostics: length + tail reveal truncation (the inline transport
                // truncates very large strings — use source_url for the robust path).
                $len = \strlen($content_base64);
                return [
                    'error' => 'invalid_base64',
                    'message' => sprintf(
                        'content_base64 is not valid base64 after whitespace stripping. Received %d chars (tail: "%s"). Inline base64 is unreliable above ~50–60 KB binary (the transport truncates the string); use source_url to let the server pull the file instead.',
                        $len,
                        substr($clean, -24),
                    ),
                    'received_length' => $len,
                ];
            }
        }

        $validation = $validator->validateUpload($name, \strlen($bytes));
        if (!($validation['ok'] ?? false)) {
            unset($validation['ok']);
            return $validation;
        }

        // Validate meta BEFORE touching the filesystem — atomicity: a bad
        // meta payload must not leave a file orphaned in FS + DBAFS that a
        // retry then trips over ("file already exists"). Encode now, apply
        // after the write succeeds.
        $encodedMeta = null;
        if ($meta !== null) {
            try {
                $encodedMeta = UploadValidator::encodeMeta($meta);
            } catch (\InvalidArgumentException $e) {
                return ['error' => 'invalid_meta', 'message' => $e->getMessage()];
            }
        }

        $targetRelative = ($parent_path === '' ? '' : trim($parent_path, '/').'/').$name;
        $targetAbs = $parentAbs.\DIRECTORY_SEPARATOR.$name;

        if (file_exists($targetAbs) && !$overwrite) {
            return ['error' => 'file_exists', 'message' => "File already exists: {$targetRelative}. Pass overwrite=true to replace."];
        }

        if (false === @file_put_contents($targetAbs, $bytes)) {
            return ['error' => 'write_failed', 'message' => "Could not write file to {$targetAbs}"];
        }

        // Sync to tl_files so subsequent file_get / list calls see it.
        $dbafsPath = $this->paths->toDbafsPath($targetRelative);
        $model = Dbafs::addResource($dbafsPath);

        // Apply the already-validated meta.
        if ($encodedMeta !== null && $model instanceof FilesModel) {
            $model->meta = $encodedMeta;
            $model->save();
        }

        $this->log(sprintf('Uploaded file %s (%d bytes) via MCP', $dbafsPath, \strlen($bytes)), __METHOD__);

        return ($model instanceof FilesModel
                ? $this->serializer->summary($model)
                : $this->serializer->fromFilesystem($targetAbs, $targetRelative))
            + ['uploaded' => true, 'size_bytes' => \strlen($bytes)];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'file_update_meta',
        description: 'Updates the localised metadata (title, alt, caption, link) of a file. Pass meta as a dict like {"de": {"title": "…", "alt": "…"}, "en": {...}}. Replaces existing meta. Logged to tl_log.',
    )]
    public function updateMeta(string $path, #[Schema(type: 'object', additionalProperties: self::META_ITEM_SCHEMA, description: 'Localised file metadata: {locale: {title, alt, link, caption}}. Example: {"de": {"title": "…"}}.')] mixed $meta = null): array
    {
        if ($meta === null) {
            return ['error' => 'meta_required', 'message' => "'meta' is required for file_update_meta. Pass a {locale: {field: value}} dict."];
        }
        $this->framework->initialize();

        try {
            $absolute = $this->paths->resolveAbsolute($path);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_path', 'message' => $e->getMessage()];
        }
        if (!file_exists($absolute)) {
            return ['error' => 'not_found', 'message' => "File not found: {$path}"];
        }

        $dbafsPath = $this->paths->toDbafsPath($path);
        $model = FilesModel::findByPath($dbafsPath);
        if ($model === null) {
            // Try to sync first.
            $model = Dbafs::addResource($dbafsPath);
        }
        if (!($model instanceof FilesModel)) {
            return ['error' => 'dbafs_sync_failed', 'message' => "Could not register file in tl_files: {$path}"];
        }

        try {
            $encoded = UploadValidator::encodeMeta($meta);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_meta', 'message' => $e->getMessage()];
        }

        $model->meta = $encoded;
        $model->tstamp = time();
        $model->save();

        $this->log(sprintf('Updated meta for %s via MCP', $dbafsPath), __METHOD__);

        return $this->serializer->summary($model) + ['updated' => true];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'file_delete',
        description: 'Deletes a file from disk and from tl_files. Refuses folders — use folder_delete for those. Logged to tl_log. Requires confirm_destructive=true to proceed.',
    )]
    public function delete(string $path, bool $confirm_destructive = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'file_delete is irreversible. Pass confirm_destructive=true to proceed.',
            ];
        }

        try {
            $absolute = $this->paths->resolveAbsolute($path);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_path', 'message' => $e->getMessage()];
        }
        if (!file_exists($absolute)) {
            return ['error' => 'not_found', 'message' => "File not found: {$path}"];
        }
        if (is_dir($absolute)) {
            return ['error' => 'is_directory', 'message' => "Path is a folder, use folder_delete: {$path}"];
        }

        $dbafsPath = $this->paths->toDbafsPath($path);

        try {
            $file = new ContaoFile($dbafsPath);
            $file->delete();          // unlinks AND removes from tl_files
        } catch (\Throwable $e) {
            return ['error' => 'delete_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }
        Dbafs::deleteResource($dbafsPath); // belt-and-braces

        $this->log(sprintf('Deleted file %s via MCP', $dbafsPath), __METHOD__);

        return ['deleted' => true, 'path' => $path];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'file_rename',
        description: 'Renames a file or folder in place (same parent directory). new_name must be a plain filename without slashes. Use file_move to change the parent directory. Logged to tl_log.',
    )]
    public function rename(string $path, string $new_name): array
    {
        $this->framework->initialize();
        $validator = $this->makeValidator();

        try {
            $absolute = $this->paths->resolveAbsolute($path);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_path', 'message' => $e->getMessage()];
        }
        if (!file_exists($absolute)) {
            return ['error' => 'not_found', 'message' => "Path not found: {$path}"];
        }

        $check = is_dir($absolute)
            ? $validator->validateFolderName($new_name)
            : $validator->validateUpload($new_name, 0);  // size 0 — just filename check
        if (!($check['ok'] ?? false)) {
            unset($check['ok']);
            return $check;
        }

        $relative = ltrim(str_replace('\\', '/', $path), '/');
        $parent = trim(\dirname($relative === '' ? '.' : $relative), '/');
        if ($parent === '.' || $parent === '') {
            $newRelative = $new_name;
        } else {
            $newRelative = $parent.'/'.$new_name;
        }

        $oldDbafs = $this->paths->toDbafsPath($relative);
        $newDbafs = $this->paths->toDbafsPath($newRelative);

        $newAbs = $this->paths->resolveAbsolute($newRelative);
        if (file_exists($newAbs)) {
            return ['error' => 'target_exists', 'message' => "Target path already exists: {$newRelative}"];
        }

        // Dbafs::moveResource only updates tl_files — it expects the actual
        // filesystem move to have happened already. Do that first; on
        // Dbafs failure, roll the filesystem move back.
        if (!@rename($absolute, $newAbs)) {
            return ['error' => 'rename_failed', 'message' => "Filesystem rename failed: {$absolute} → {$newAbs}"];
        }
        try {
            Dbafs::moveResource($oldDbafs, $newDbafs);
        } catch (\Throwable $e) {
            @rename($newAbs, $absolute);
            return ['error' => 'rename_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->log(sprintf('Renamed %s → %s via MCP', $oldDbafs, $newDbafs), __METHOD__);

        $model = FilesModel::findByPath($newDbafs);

        return ($model !== null ? $this->serializer->summary($model) : ['path' => $newRelative])
            + ['renamed' => true, 'from' => $relative, 'to' => $newRelative];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'file_move',
        description: 'Moves a file or folder to a different parent directory. new_parent_path is the destination folder relative to the upload directory. The file keeps its name. Logged to tl_log.',
    )]
    public function move(string $path, string $new_parent_path): array
    {
        $this->framework->initialize();

        try {
            $absolute = $this->paths->resolveAbsolute($path);
            $newParentAbs = $this->paths->resolveAbsolute($new_parent_path);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_path', 'message' => $e->getMessage()];
        }
        if (!file_exists($absolute)) {
            return ['error' => 'not_found', 'message' => "Path not found: {$path}"];
        }
        if (!is_dir($newParentAbs)) {
            return ['error' => 'parent_not_found', 'message' => "Target parent is not a directory: {$new_parent_path}"];
        }

        $name = basename($absolute);
        $relative = ltrim(str_replace('\\', '/', $path), '/');
        $newParent = trim(str_replace('\\', '/', $new_parent_path), '/');
        $newRelative = ($newParent === '' ? '' : $newParent.'/').$name;

        $oldDbafs = $this->paths->toDbafsPath($relative);
        $newDbafs = $this->paths->toDbafsPath($newRelative);

        if ($oldDbafs === $newDbafs) {
            return ['error' => 'no_change', 'message' => 'Source and target paths are identical.'];
        }

        $newAbs = $this->paths->resolveAbsolute($newRelative);
        if (file_exists($newAbs)) {
            return ['error' => 'target_exists', 'message' => "Target path already exists: {$newRelative}"];
        }

        // See rename(): Dbafs only updates the DB. Filesystem move first,
        // rollback on Dbafs failure.
        if (!@rename($absolute, $newAbs)) {
            return ['error' => 'move_failed', 'message' => "Filesystem rename failed: {$absolute} → {$newAbs}"];
        }
        try {
            Dbafs::moveResource($oldDbafs, $newDbafs);
        } catch (\Throwable $e) {
            @rename($newAbs, $absolute);
            return ['error' => 'move_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->log(sprintf('Moved %s → %s via MCP', $oldDbafs, $newDbafs), __METHOD__);

        $model = FilesModel::findByPath($newDbafs);

        return ($model !== null ? $this->serializer->summary($model) : ['path' => $newRelative])
            + ['moved' => true, 'from' => $relative, 'to' => $newRelative];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'folder_create',
        description: 'Creates a new folder under parent_path. name must be a plain folder name without slashes. Logged to tl_log.',
    )]
    public function folderCreate(string $parent_path, string $name): array
    {
        $this->framework->initialize();
        $validator = $this->makeValidator();

        $check = $validator->validateFolderName($name);
        if (!($check['ok'] ?? false)) {
            unset($check['ok']);
            return $check;
        }

        try {
            $parentAbs = $this->paths->resolveAbsolute($parent_path);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_path', 'message' => $e->getMessage()];
        }
        if (!is_dir($parentAbs)) {
            return ['error' => 'parent_not_found', 'message' => "Parent folder is not a directory: {$parent_path}"];
        }

        $relative = ($parent_path === '' ? '' : trim($parent_path, '/').'/').$name;
        $absolute = $parentAbs.\DIRECTORY_SEPARATOR.$name;

        if (file_exists($absolute)) {
            return ['error' => 'already_exists', 'message' => "Folder already exists: {$relative}"];
        }

        try {
            $folder = new ContaoFolder($this->paths->toDbafsPath($relative));
            // The ContaoFolder constructor auto-creates the folder on disk.
            $folder->getModel(); // forces Dbafs sync
        } catch (\Throwable $e) {
            return ['error' => 'create_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }

        $this->log(sprintf('Created folder %s via MCP', $this->paths->toDbafsPath($relative)), __METHOD__);

        $model = FilesModel::findByPath($this->paths->toDbafsPath($relative));

        return ($model !== null ? $this->serializer->summary($model) : ['path' => $relative])
            + ['created' => true];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'folder_delete',
        description: 'Deletes a folder. Safe by default: refuses if the folder is not empty. Pass cascade=true to delete recursively (folder + all contained files / sub-folders). Logged to tl_log. Requires confirm_destructive=true to proceed.',
    )]
    public function folderDelete(string $path, bool $confirm_destructive = false, bool $cascade = false): array
    {
        $this->framework->initialize();

        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'folder_delete is irreversible. Pass confirm_destructive=true to proceed. Use cascade=true to also drop children.',
            ];
        }

        try {
            $absolute = $this->paths->resolveAbsolute($path);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_path', 'message' => $e->getMessage()];
        }
        if (!is_dir($absolute)) {
            return ['error' => 'not_a_directory', 'message' => "Not a folder: {$path}"];
        }
        if ($path === '' || trim($path, '/') === '') {
            return ['error' => 'refuse_root', 'message' => 'Refusing to delete the upload root folder.'];
        }

        $entries = array_values(array_filter((array) @scandir($absolute), static fn (string $n): bool => $n !== '.' && $n !== '..'));
        if ($entries !== [] && !$cascade) {
            return [
                'error' => 'folder_not_empty',
                'message' => "Folder is not empty (".\count($entries)." entries). Pass cascade=true to cascade.",
                'entry_count' => \count($entries),
            ];
        }

        $dbafsPath = $this->paths->toDbafsPath($path);

        try {
            $folder = new ContaoFolder($dbafsPath);
            $folder->delete();   // recursive on disk; clears tl_files entries
        } catch (\Throwable $e) {
            return ['error' => 'delete_failed', 'message' => $e->getMessage(), 'class' => $e::class];
        }
        Dbafs::deleteResource($dbafsPath);

        $this->log(sprintf('Deleted folder %s via MCP (cascade=%s, cascaded=%d)', $dbafsPath, $cascade ? 'true' : 'false', \count($entries)), __METHOD__);

        return ['deleted' => true, 'path' => $path, 'cascaded_entries' => \count($entries)];
    }

    // -----------------------------------------------------------------

    /**
     * Server-side pull for source_url, with an SSRF guard: http/https only,
     * the resolved host must be a PUBLIC IP (private/reserved/loopback/
     * link-local — incl. the 169.254.169.254 cloud-metadata address — are
     * refused), redirects are NOT followed (a redirect could bypass the host
     * check), and the body is hard-capped before the configured maxFileSize
     * check runs on the actual bytes.
     *
     * @return array{0: string|null, 1: array<string, mixed>|null} [bytes, error]
     */
    private function fetchFromUrl(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!\in_array($scheme, ['http', 'https'], true) || $host === '') {
            return [null, ['error' => 'invalid_source_url', 'message' => 'source_url must be an http(s) URL with a host.']];
        }

        // Resolve + block non-public targets. A bracketed IPv6 literal arrives
        // with brackets stripped by parse_url's host.
        $ips = $this->resolveHost($host);
        if ($ips === []) {
            return [null, ['error' => 'source_url_unresolvable', 'message' => sprintf('Could not resolve host "%s".', $host)]];
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) === false) {
                return [null, ['error' => 'source_url_blocked', 'message' => sprintf('Refusing to fetch from a private/reserved address (%s → %s).', $host, $ip)]];
            }
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 20,
                'max_redirects' => 0,        // a redirect could point back at a blocked host
                'max_duration' => 30,
                'headers' => ['Accept' => '*/*'],
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                return [null, ['error' => 'source_url_http_error', 'message' => sprintf('source_url returned HTTP %d.', $status)]];
            }

            // Stream with a hard byte cap to avoid pulling an unbounded body.
            $bytes = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                $bytes .= $chunk->getContent();
                if (\strlen($bytes) > self::SOURCE_URL_MAX_BYTES) {
                    return [null, ['error' => 'source_url_too_large', 'message' => sprintf('Remote file exceeds the %d MB pull ceiling.', self::SOURCE_URL_MAX_BYTES >> 20)]];
                }
            }

            if ($bytes === '') {
                return [null, ['error' => 'source_url_empty', 'message' => 'source_url returned an empty body.']];
            }

            return [$bytes, null];
        } catch (\Throwable $e) {
            return [null, ['error' => 'source_url_failed', 'message' => 'Could not fetch source_url: '.$e->getMessage()]];
        }
    }

    /**
     * @return list<string> resolved IP addresses (the literal itself if $host is an IP)
     */
    private function resolveHost(string $host): array
    {
        if (filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];
        $records = @dns_get_record($host, \DNS_A | \DNS_AAAA) ?: [];
        foreach ($records as $r) {
            if (isset($r['ip'])) {
                $ips[] = (string) $r['ip'];
            } elseif (isset($r['ipv6'])) {
                $ips[] = (string) $r['ipv6'];
            }
        }
        if ($ips === []) {
            $v4 = gethostbyname($host);
            if ($v4 !== $host) {
                $ips[] = $v4;
            }
        }

        return $ips;
    }

    private function makeValidator(): UploadValidator
    {
        return new UploadValidator($this->framework->getAdapter(Config::class));
    }

    private function log(string $message, string $caller): void
    {
        $this->logger->info($message, ['contao' => new ContaoContext($caller, ContaoContext::FILES, $this->authorResolver->getLogUsername(), null, null, $this->authorResolver->getLogSource())]);
    }

    /**
     * Contao's marker for "serve this folder directly from the web root".
     * `SymlinksCommand` finds these and links `<web>/files/<folder>` at them.
     */
    private const PUBLIC_MARKER = '.public';

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'folder_set_public',
        description: <<<'DESC'
            Makes a folder public, the same thing the file manager's "make public" does:
            it writes Contao's `.public` marker into the folder and creates the symlink
            `public/files/<folder>` so the webserver can serve those files directly.

            Needed for everything delivered as-is instead of going through the image
            pipeline — webfonts loaded via url() from compiled CSS, your own JavaScript,
            favicons and site.webmanifest, plain SVGs. Without it those paths 404 no
            matter what the folder contains.

            `public: false` removes the marker and the symlink again.

            Only folders inside the upload directory, and not the upload root itself —
            Contao only links sub-folders. Uploading a file named ".public" stays
            rejected; dot-files have no business arriving over an upload channel.

            Returns {path, public, symlink, symlink_created, warnings}. If the symlink
            could not be created (missing permission, Windows without the privilege), the
            marker is still written and `symlink_created` is false with the reason in
            `warnings` — a `contao:symlinks` run or the next deployment finishes it.
        DESC,
    )]
    public function folderSetPublic(string $path, bool $public = true): array
    {
        $this->framework->initialize();

        try {
            $absolute = $this->paths->resolveAbsolute($path);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_path', 'message' => $e->getMessage()];
        }

        if (!is_dir($absolute)) {
            return ['error' => 'not_a_directory', 'message' => sprintf('Not a folder: %s', $path)];
        }

        $relative = trim($path, '/');

        if ($relative === '') {
            // SymlinksCommand searches with depth('> 0'), so a marker in the
            // upload root would be found by nothing and silently do nothing.
            return [
                'error' => 'refuse_root',
                'message' => 'The upload root cannot be made public — Contao only links sub-folders.',
            ];
        }

        $marker = $absolute.\DIRECTORY_SEPARATOR.self::PUBLIC_MARKER;

        // Both paths go to SymlinkUtil relative to the project dir, exactly as
        // SymlinksCommand builds them: files/<folder> ← public/files/<folder>.
        $target = $this->paths->uploadPath().'/'.$relative;
        $linkRelative = $this->publicDirName().'/'.$target;
        $linkAbsolute = $this->projectDir.\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $linkRelative);
        $warnings = [];

        if (!$public) {
            if (is_file($marker) && !@unlink($marker)) {
                return [
                    'error' => 'write_failed',
                    'message' => sprintf('Could not remove %s/%s', $target, self::PUBLIC_MARKER),
                ];
            }

            if (is_link($linkAbsolute) && !@unlink($linkAbsolute)) {
                $warnings[] = sprintf(
                    'Marker removed, but the symlink %s could not be deleted — remove it manually or run contao:symlinks.',
                    $linkRelative,
                );
            }

            $this->log(sprintf('Removed public marker from %s via MCP', $target), __METHOD__);

            return [
                'path' => $relative,
                'public' => false,
                'symlink' => $linkRelative,
                'symlink_created' => false,
                'warnings' => $warnings,
            ];
        }

        if (!is_file($marker) && false === @file_put_contents($marker, '')) {
            return [
                'error' => 'write_failed',
                'message' => sprintf('Could not write %s/%s — check the folder permissions.', $target, self::PUBLIC_MARKER),
            ];
        }

        $symlinkCreated = true;

        if (is_link($linkAbsolute)) {
            // Already linked — SymlinkUtil would refuse an existing path.
            $symlinkCreated = false;
            $warnings[] = 'The symlink already existed and was left untouched.';
        } else {
            try {
                SymlinkUtil::symlink($target, $linkRelative, $this->projectDir);
            } catch (\Throwable $e) {
                // The marker is the durable part; the symlink is what the next
                // deployment recreates anyway. Failing the whole call here would
                // leave the caller believing nothing happened at all.
                $symlinkCreated = false;
                $warnings[] = sprintf(
                    'Marker written, but the symlink could not be created (%s). Run "vendor/bin/contao-console contao:symlinks" or deploy to finish it.',
                    $e->getMessage(),
                );
            }
        }

        $this->log(sprintf('Made %s public via MCP', $target), __METHOD__);

        return [
            'path' => $relative,
            'public' => true,
            'symlink' => $linkRelative,
            'symlink_created' => $symlinkCreated,
            'warnings' => $warnings,
        ];
    }

    /**
     * Name of the web root directory, relative to the project dir. Contao 5
     * fixes it at "public"; "web" is only honoured for installations carrying
     * the pre-4.6 layout.
     */
    private function publicDirName(): string
    {
        return is_dir($this->projectDir.\DIRECTORY_SEPARATOR.'web')
            && !is_dir($this->projectDir.\DIRECTORY_SEPARATOR.'public')
            ? 'web'
            : 'public';
    }

}
