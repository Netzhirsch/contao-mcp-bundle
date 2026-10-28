<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\File;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\CoreBundle\Util\SymlinkUtil;
use Contao\Dbafs;
use Contao\File as ContaoFile;
use Contao\FilesModel;
use Contao\Folder as ContaoFolder;
use Contao\StringUtil;
use Contao\System;
use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\Routing\EndpointPath;
use Netzhirsch\ContaoMcpBundle\Security\FileScope;
use Netzhirsch\ContaoMcpBundle\Security\McpPermissionGuard;
use Netzhirsch\ContaoMcpBundle\Service\AuthorResolver;
use Netzhirsch\ContaoMcpBundle\Service\McpCallContext;
use Netzhirsch\ContaoMcpBundle\Service\ToolError;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\RequestStack;

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

    /**
     * Formats the backend holds to tl_settings.imageWidth / imageHeight — the
     * list Contao's FileUpload uses.
     */
    private const RASTER_EXTENSIONS = ['gif', 'jpg', 'jpeg', 'png', 'webp', 'avif', 'heic', 'jxl'];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly LoggerInterface $logger,
        private readonly PathResolver $paths,
        private readonly Serializer $serializer,
        private readonly AuthorResolver $authorResolver,
        private readonly string $projectDir,
        private readonly SourceUrlFetcher $sourceUrls,
        private readonly McpCallContext $callContext,
        private readonly McpPermissionGuard $permissionGuard,
        private readonly RequestStack $requestStack,
        private readonly McpServerConfigStorage $configStorage,
        private readonly ContainerBagInterface $parameters,
    ) {
    }

    /**
     * Drops every entry the caller may not see from a listing or a search.
     *
     * The permission guard checks the paths a call NAMES; these two tools
     * return paths nobody named, so they have to ask separately. A folder stays
     * visible when it leads to a mount — otherwise a user mounted deep could
     * never navigate to their own folder.
     */
    private static function visibleTo(?FileScope $scope, string $dbafsPath, bool $isDir): bool
    {
        if ($scope === null) {
            return true; // admin or trusted mode
        }

        return $isDir ? $scope->mayDescend($dbafsPath) : $scope->allows($dbafsPath);
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

        $scope = $this->permissionGuard->fileScope();

        $out = [];
        $matched = 0;
        foreach ($entries as $name) {
            $childAbs = $absolute.\DIRECTORY_SEPARATOR.$name;
            $isDir = is_dir($childAbs);
            if ($type === 'files' && $isDir) continue;
            if ($type === 'folders' && !$isDir) continue;
            $relative = ($path === '' ? '' : trim($path, '/').'/').$name;
            $dbafsPath = $this->paths->toDbafsPath($relative);

            // Before counting: a hidden entry must not show up in `total`
            // either, or the count alone would report how much lies outside
            // the caller's mounts.
            if (!self::visibleTo($scope, $dbafsPath, $isDir)) {
                continue;
            }
            ++$matched;
            if ($matched <= $offset) {
                continue;
            }
            if (\count($out) >= $limit) {
                continue;
            }
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

        $scope = $this->permissionGuard->fileScope();

        // Without a `path` this walks the whole upload tree, and nothing in the
        // call names a single one of the paths it returns — which is how a
        // restricted user could read every folder and file name on the install
        // with one glob. Prune at the directory level so the scan does not even
        // enter a foreign folder (cheaper than filtering afterwards), and still
        // test every entry below, because pruning alone would pass the files
        // sitting directly in an ancestor folder.
        if ($scope !== null) {
            $finder->filter(function (\SplFileInfo $entry) use ($scope, $relRoot): bool {
                $rel = str_replace('\\', '/', $entry->getRelativePathname());
                $dbafs = $this->paths->toDbafsPath($relRoot === '' ? $rel : $relRoot.'/'.$rel);

                return self::visibleTo($scope, $dbafs, $entry->isDir());
            }, true); // prune: skip the whole subtree, don't just drop its entries
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

        $result = [
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

        // Zero hits on a query with no wildcard is nearly always the same
        // mistake: this is a glob, not a substring search, so `green-it` only
        // matches a file called exactly that. Nothing distinguishes the empty
        // answer from "genuinely not there", so say which one it probably is
        // rather than leaving the caller to conclude the file is missing.
        if ($matched === 0 && !preg_match('/[*?\[{]/', $query)) {
            $result['hint'] = sprintf(
                'No match. `query` is a glob pattern, not a substring: "%1$s" matches only that exact name. '
                .'Try "*%1$s*" to find it anywhere in the name.',
                $query,
            );
        }

        return $result;
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
        description: <<<'DESC'
            Uploads a new file in ONE call. parent_path is the destination folder relative
            to the upload directory ("" for the root), name is only the file name.

            Pick the way the bytes travel by what you have:
              - You can run a shell command → file_upload_begin, then PUT the raw file to
                the direct_upload URL it returns (one curl call, the bytes never pass
                through you), then file_upload_finish. Works for any size up to the limit.
              - The file is on a public http(s) URL → source_url; the server pulls it
                (redirects are followed, private/loopback addresses are refused).
              - Small text you produced yourself (SVG, CSV, TXT, JSON) → content_base64.
                Keep inline base64 under ~30 KB of raw data: longer strings get cut or
                garbled in the tool call. Pass sha256 if you can compute it.
              - Larger bytes and no shell → file_upload_begin / file_upload_chunk.

            The name is cleaned the way the Contao backend cleans it (`: * ? " < > |` and
            control characters become `-`); the answer says so if it changed. SVGs are
            sanitised exactly like a backend upload (scripts, event handlers, external
            references removed). Images larger than the configured maximum dimensions are
            scaled down, as in the backend. Validates extension against
            tl_settings.uploadTypes and size against tl_settings.maxFileSize. Refuses to
            overwrite by default (pass overwrite=true). meta is an optional
            {locale: {title, alt, link, caption}} dict. Logged to tl_log.
            DESC,
    )]
    public function upload(
        string $parent_path,
        string $name,
        string $content_base64 = '',
        bool $overwrite = false,
        #[Schema(type: 'object', additionalProperties: self::META_ITEM_SCHEMA, description: 'Localised file metadata: {locale: {title, alt, link, caption}}. Example: {"de": {"title": "…"}}.')] mixed $meta = null,
        ?string $source_url = null,
        ?string $sha256 = null,
    ): array {
        $this->framework->initialize();

        try {
            $parentAbs = $this->paths->resolveAbsolute($parent_path);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_path', 'message' => $e->getMessage()];
        }
        if (!is_dir($parentAbs)) {
            return ['error' => 'parent_not_found', 'message' => "Parent folder is not a directory: {$parent_path}"];
        }

        if ($sha256 !== null && preg_match('/^[0-9a-fA-F]{64}$/', $sha256) !== 1) {
            return ['error' => 'invalid_input', 'message' => 'sha256 must be 64 hex characters, or omitted.'];
        }

        // Obtain the bytes from exactly one source: a server-side URL pull
        // (robust for large files) or inline base64 (fine for small files).
        $hasUrl = $source_url !== null && $source_url !== '';
        if ($hasUrl && $content_base64 !== '') {
            return ['error' => 'invalid_input', 'message' => 'Provide either content_base64 OR source_url, not both.'];
        }
        if (!$hasUrl && $content_base64 === '') {
            return ['error' => 'invalid_input', 'message' => 'Provide content_base64 (small files) or source_url (a file on a public URL). For anything else, use file_upload_begin.'];
        }

        // Name, extension and an existing target, judged before fetching
        // anything: none of them should cost a 30 MB download first.
        $named = UploadValidator::sanitiseName($name);
        if (!$named['ok']) {
            return ['error' => $named['error'], 'message' => $named['message']];
        }
        $early = $this->makeValidator()->validateUpload($named['name'], 0);
        if (!($early['ok'] ?? false)) {
            unset($early['ok']);

            return $early;
        }
        if (!$overwrite && file_exists($parentAbs.\DIRECTORY_SEPARATOR.$named['name'])) {
            $target = ($parent_path === '' ? '' : trim($parent_path, '/').'/').$named['name'];

            return ['error' => 'file_exists', 'message' => "File already exists: {$target}. Pass overwrite=true to replace."];
        }

        $sessions = $this->sessions();
        $tmp = $sessions->tempFile();

        try {
            if ($hasUrl) {
                $error = $this->sourceUrls->fetchToFile((string) $source_url, $tmp, $this->makeValidator()->maxFileSize());
                if ($error !== null) {
                    return $error;
                }
            } else {
                // Strip whitespace before strict decoding: transports often
                // line-wrap or pad large base64 strings, and base64_decode(strict)
                // rejects ANY non-alphabet char (incl. \n).
                $clean = preg_replace('/\s+/', '', $content_base64) ?? $content_base64;
                $bytes = base64_decode($clean, true);

                if ($bytes === false) {
                    $len = \strlen($content_base64);

                    return [
                        'error' => 'invalid_base64',
                        'message' => sprintf(
                            'content_base64 is not valid base64 after whitespace stripping. Received %d chars (tail: "%s"). A long base64 argument is where uploads break: it gets cut off or garbled on the way. Use file_upload_begin — with a shell, PUT the file to its direct_upload URL; without one, send it in file_upload_chunk slices of %d bytes.',
                            $len,
                            substr($clean, -24),
                            UploadSessions::CHUNK_SIZE_RECOMMENDED,
                        ),
                        'received_length' => $len,
                    ];
                }

                if (false === @file_put_contents($tmp, $bytes)) {
                    return ['error' => 'write_failed', 'message' => 'Could not buffer the upload in var/mcp/uploads.'];
                }
                unset($bytes, $clean);
            }

            if ($sha256 !== null && strtolower($sha256) !== hash_file('sha256', $tmp)) {
                return [
                    'error' => 'checksum_mismatch',
                    'message' => sprintf('What arrived (%d bytes) does not match the sha256 you gave. Nothing was stored. If you sent base64 inline, it was most likely changed on the way — use file_upload_begin instead.', (int) filesize($tmp)),
                ];
            }

            return $this->storeFile($parent_path, $parentAbs, $name, $tmp, $overwrite, $meta);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'file_upload_begin',
        description: <<<'DESC'
            Opens an upload for bytes you hold yourself. Everything that can be checked
            before a byte is sent is checked here — destination folder and your rights
            there, file name, extension against tl_settings.uploadTypes, the declared
            size against tl_settings.maxFileSize, and the meta payload.

            Pass total_size_bytes exactly; pass sha256 of the whole file if you can, so
            the server verifies what arrived.

            Then send the bytes ONE of two ways:
              1. direct_upload (preferred whenever you can run a command): PUT the raw
                 file to direct_upload.url with the headers given — the answer contains a
                 ready curl line. The bytes never pass through you, so size and
                 base64 errors stop mattering. If the server answers 413 (a web-server
                 body limit), send it in several PUTs, each with Upload-Offset set to the
                 bytes already received.
              2. file_upload_chunk, in slices of chunk_size_recommended raw bytes.

            Finally call file_upload_finish. An idle upload expires after
            expires_in_seconds; every received slice resets that clock.
            DESC,
    )]
    public function uploadBegin(
        string $parent_path,
        string $name,
        int $total_size_bytes,
        bool $overwrite = false,
        #[Schema(type: 'object', additionalProperties: self::META_ITEM_SCHEMA, description: 'Localised file metadata: {locale: {title, alt, link, caption}}.')] mixed $meta = null,
        ?string $sha256 = null,
    ): array {
        $this->framework->initialize();

        try {
            $parentAbs = $this->paths->resolveAbsolute($parent_path);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_path', 'message' => $e->getMessage()];
        }

        if (!is_dir($parentAbs)) {
            return ['error' => 'parent_not_found', 'message' => "Parent folder is not a directory: {$parent_path}"];
        }

        if ($total_size_bytes <= 0) {
            return ['error' => 'invalid_input', 'message' => 'total_size_bytes must be the exact size of the file in bytes.'];
        }

        if ($sha256 !== null && preg_match('/^[0-9a-fA-F]{64}$/', $sha256) !== 1) {
            return ['error' => 'invalid_input', 'message' => 'sha256 must be 64 hex characters, or omitted.'];
        }

        // The name the file will really get — the target path reported back
        // must be the one finish writes to.
        $named = UploadValidator::sanitiseName($name);

        if (!$named['ok']) {
            return ['error' => $named['error'], 'message' => $named['message']];
        }

        // Name, extension and size, judged now rather than after the transfer.
        $validation = $this->makeValidator()->validateUpload($named['name'], $total_size_bytes);

        if (!($validation['ok'] ?? false)) {
            unset($validation['ok']);

            return $validation;
        }

        if ($meta !== null) {
            try {
                UploadValidator::encodeMeta($meta);
            } catch (\InvalidArgumentException $e) {
                return ['error' => 'invalid_meta', 'message' => $e->getMessage()];
            }
        }

        $targetRelative = ($parent_path === '' ? '' : trim($parent_path, '/').'/').$named['name'];

        if (file_exists($parentAbs.\DIRECTORY_SEPARATOR.$named['name']) && !$overwrite) {
            return ['error' => 'file_exists', 'message' => "File already exists: {$targetRelative}. Pass overwrite=true to replace."];
        }

        $sessions = $this->sessions();
        $sessions->sweep();

        $userId = $this->callContext->getUserId();

        if ($sessions->openFor($userId) >= UploadSessions::MAX_OPEN_PER_USER) {
            return [
                'error' => 'too_many_uploads',
                'message' => sprintf('You have %d unfinished uploads open. Finish or abort one (file_upload_abort) before starting another; idle ones expire after %d minutes.', UploadSessions::MAX_OPEN_PER_USER, intdiv(UploadSessions::TTL_SECONDS, 60)),
            ];
        }

        try {
            $id = $sessions->create([
                'parent_path' => $parent_path,
                'name' => $name,
                'total_size_bytes' => $total_size_bytes,
                'overwrite' => $overwrite,
                'meta' => $meta,
                'sha256' => $sha256 === null ? null : strtolower($sha256),
                'user_id' => $userId,
            ]);
            $token = $sessions->issueDirectToken($id);
        } catch (\Throwable $e) {
            return ToolError::opaque($this->logger, $e, 'upload_begin_failed', ['parent_path' => $parent_path]);
        }

        $answer = [
            'upload_id' => $id,
            'target_path' => $targetRelative,
            'total_size_bytes' => $total_size_bytes,
            'direct_upload' => $this->directUploadInfo($id, $token),
            'chunk_size_recommended' => UploadSessions::CHUNK_SIZE_RECOMMENDED,
            'expires_in_seconds' => UploadSessions::TTL_SECONDS,
            'next_sequence' => 0,
        ];

        if ($named['name'] !== trim($name)) {
            $answer['name_changed_from'] = $name;
        }

        return $answer;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'file_upload_chunk',
        description: <<<'DESC'
            Sends the next slice of an upload opened with file_upload_begin. Use this
            only when you cannot run a command — otherwise PUT the whole file to the
            direct_upload URL instead.

            sequence starts at 0 and goes up by one each call; the answer says which
            one it expects next, so a lost or repeated call is refused instead of
            corrupting the file.

            content_base64 is that slice of the RAW file, base-64 encoded. Slice the
            bytes, encode each slice — do not base64 the whole file and cut the string.
            Keep slices at chunk_size_recommended raw bytes or less.

            Pass size_bytes (the raw length of this slice) and, if you can, sha256 of
            the slice: a slice that was cut or changed on the way is then refused on
            its own, and you resend just that one instead of discovering a broken
            file at the end. The answer reports chunk_bytes either way — compare it.
            DESC,
    )]
    public function uploadChunk(string $upload_id, int $sequence, string $content_base64, ?int $size_bytes = null, ?string $sha256 = null): array
    {
        $sessions = $this->sessions();
        $clean = preg_replace('/\s+/', '', $content_base64) ?? $content_base64;
        $bytes = base64_decode($clean, true);

        if ($bytes === false || $bytes === '') {
            return [
                'error' => 'invalid_base64',
                'message' => 'content_base64 is not valid base64 after whitespace stripping, or decoded to nothing. Nothing was appended; resend this slice, smaller if it keeps failing.',
            ];
        }

        if ($size_bytes !== null && $size_bytes !== \strlen($bytes)) {
            return [
                'error' => 'chunk_truncated',
                'message' => sprintf('This slice decoded to %d bytes, not the %d you declared — it was cut or changed on the way. Nothing was appended; resend it, smaller if it keeps happening.', \strlen($bytes), $size_bytes),
                'chunk_bytes' => \strlen($bytes),
            ];
        }

        if ($sha256 !== null && strtolower($sha256) !== hash('sha256', $bytes)) {
            return [
                'error' => 'chunk_checksum_mismatch',
                'message' => 'This slice does not match the sha256 you gave — it was changed on the way. Nothing was appended; resend it.',
                'chunk_bytes' => \strlen($bytes),
            ];
        }

        try {
            $result = $sessions->locked($upload_id, function (array $manifest) use ($sessions, $upload_id, $sequence, $bytes): array {
                if (($denied = $this->denyForeignSession($manifest)) !== null) {
                    return $denied;
                }

                $expected = (int) $manifest['next_sequence'];

                if ($sequence !== $expected) {
                    return [
                        'error' => 'out_of_sequence',
                        'message' => sprintf('Expected sequence %d, got %d. Chunks must arrive in order and exactly once.', $expected, $sequence),
                        'next_sequence' => $expected,
                        'received_bytes' => (int) $manifest['received_bytes'],
                    ];
                }

                $total = (int) $manifest['total_size_bytes'];
                $after = (int) $manifest['received_bytes'] + \strlen($bytes);

                if ($after > $total) {
                    $sessions->discard($upload_id);

                    return [
                        'error' => 'size_exceeded',
                        'message' => sprintf('The chunks add up to %d bytes, more than the %d declared at begin. The upload was discarded.', $after, $total),
                    ];
                }

                $manifest = $sessions->append($manifest, $bytes);

                return [
                    'accepted' => true,
                    'chunk_bytes' => \strlen($bytes),
                    'received_bytes' => (int) $manifest['received_bytes'],
                    'remaining_bytes' => $total - (int) $manifest['received_bytes'],
                    'next_sequence' => (int) $manifest['next_sequence'],
                    'complete' => (int) $manifest['received_bytes'] === $total,
                ];
            });
        } catch (\Throwable $e) {
            return ToolError::opaque($this->logger, $e, 'upload_chunk_failed', ['upload_id' => $upload_id]);
        }

        return $result ?? [
            'error' => 'upload_not_found',
            'message' => 'No open upload with that id — it was never started, already finished, or expired. Start a new one with file_upload_begin.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'file_upload_finish',
        description: <<<'DESC'
            Completes an upload: checks that what arrived adds up to the declared size,
            verifies sha256 when one was given, and only then runs the same checks and
            steps a one-shot file_upload runs — name cleanup, magic bytes against the
            extension, SVG sanitising, image scaling, the overwrite decision — before
            the file is written and registered in tl_files.

            Those checks deliberately happen here and not per slice: a single slice
            says nothing about the file it ends up in.

            Returns the same shape as file_upload.
            DESC,
    )]
    public function uploadFinish(string $upload_id): array
    {
        $this->framework->initialize();

        $sessions = $this->sessions();

        try {
            $result = $sessions->locked($upload_id, fn (array $manifest): array => $this->finishLocked($sessions, $upload_id, $manifest));
        } catch (\Throwable $e) {
            return ToolError::opaque($this->logger, $e, 'upload_finish_failed', ['upload_id' => $upload_id]);
        }

        if ($result === null) {
            return [
                'error' => 'upload_not_found',
                'message' => 'No open upload with that id — it was never started, already finished, or expired.',
            ];
        }

        // Discarded outside the lock: discarding removes the lock file itself.
        if (!isset($result['error']) || ($result['discard'] ?? false)) {
            $sessions->discard($upload_id);
        }
        unset($result['discard']);

        return $result;
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>
     */
    private function finishLocked(UploadSessions $sessions, string $upload_id, array $manifest): array
    {
        if (($denied = $this->denyForeignSession($manifest)) !== null) {
            return $denied;
        }

        $total = (int) $manifest['total_size_bytes'];
        $received = (int) $manifest['received_bytes'];

        if ($received !== $total) {
            return [
                'error' => 'incomplete',
                'message' => sprintf('%d of %d bytes arrived. Send the rest before finishing.', $received, $total),
                'received_bytes' => $received,
                'remaining_bytes' => $total - $received,
                'next_sequence' => (int) $manifest['next_sequence'],
            ];
        }

        $data = $sessions->dataPath($upload_id);
        clearstatcache(true, $data);

        if ((int) @filesize($data) !== $total) {
            return [
                'error' => 'buffer_mismatch',
                'message' => 'The buffered file does not have the declared size any more. The upload was discarded; start again.',
                'discard' => true,
            ];
        }

        $expectedHash = $manifest['sha256'] ?? null;

        if (\is_string($expectedHash) && $expectedHash !== hash_file('sha256', $data)) {
            return [
                'error' => 'checksum_mismatch',
                'message' => 'The assembled file does not match the sha256 given at begin. The upload was discarded; start again.',
                'discard' => true,
            ];
        }

        // The folder is resolved again rather than trusted from the manifest:
        // it may have been renamed or removed while the transfer was running.
        try {
            $parentAbs = $this->paths->resolveAbsolute((string) $manifest['parent_path']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_path', 'message' => $e->getMessage(), 'discard' => true];
        }

        if (!is_dir($parentAbs)) {
            return [
                'error' => 'parent_not_found',
                'message' => sprintf('The destination folder "%s" no longer exists. The upload was discarded.', (string) $manifest['parent_path']),
                'discard' => true,
            ];
        }

        // On a refusal the session is kept, so the caller can fix the cause —
        // an overwrite flag, a folder — without sending everything again. It
        // expires on its own either way.
        return $this->storeFile(
            (string) $manifest['parent_path'],
            $parentAbs,
            (string) $manifest['name'],
            $data,
            (bool) $manifest['overwrite'],
            $manifest['meta'] ?? null,
        ) + ['chunked' => true];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'file_upload_abort',
        description: 'Throws away an unfinished upload and its buffered bytes. Uploads also expire on their own, so this is for tidiness — and for making room when too_many_uploads is reported.',
    )]
    public function uploadAbort(string $upload_id): array
    {
        $sessions = $this->sessions();
        $manifest = $sessions->load($upload_id);

        if ($manifest === null) {
            return ['aborted' => false, 'message' => 'No open upload with that id.'];
        }

        if (($denied = $this->denyForeignSession($manifest)) !== null) {
            return $denied;
        }

        $sessions->discard($upload_id);

        return ['aborted' => true, 'upload_id' => $upload_id];
    }

    /**
     * An upload belongs to whoever opened it.
     *
     * Two connectors can be mid-transfer at the same time, and an id that leaked
     * into a log must not let one of them finish — or discard — the other's
     * file. In trusted mode there is no user to compare, and the deployment has
     * already said it trusts its callers.
     *
     * @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>|null
     */
    private function denyForeignSession(array $manifest): ?array
    {
        $owner = $manifest['user_id'] ?? null;
        $caller = $this->callContext->getUserId();

        if ($owner === null || $caller === null || (int) $owner === $caller) {
            return null;
        }

        return [
            'error' => 'upload_not_found',
            'message' => 'No open upload with that id — it was never started, already finished, or expired.',
        ];
    }

    private function sessions(): UploadSessions
    {
        return new UploadSessions($this->projectDir);
    }

    /**
     * Where and how to PUT the raw file, so that a client with a shell does not
     * have to turn it into base64 and pass it through its own context.
     *
     * The URL needs the host the request came in on; without a request (CLI,
     * smoke test) only the path is known, and that is what is returned.
     *
     * @return array<string, mixed>
     */
    private function directUploadInfo(string $id, string $token): array
    {
        $endpoint = EndpointPath::normalise((string) ($this->configStorage->load()['path'] ?? ''));
        $path = '/'.($endpoint !== '' ? $endpoint : EndpointPath::DEFAULT).'/upload/'.$id;
        $request = $this->requestStack->getMainRequest();
        $url = $request !== null ? $request->getSchemeAndHttpHost().$request->getBasePath().$path : null;

        return [
            'url' => $url,
            'path' => $path,
            'method' => 'PUT',
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Upload-Offset' => '0',
                'Content-Type' => 'application/octet-stream',
            ],
            'curl' => sprintf(
                'curl -sS -X PUT -H "Authorization: Bearer %s" -H "Upload-Offset: 0" -H "Content-Type: application/octet-stream" --data-binary @<FILE> %s',
                $token,
                $url ?? '<SITE>'.$path,
            ),
            'note' => 'The token is valid for this upload only and expires with it. After the PUT answers complete=true, call file_upload_finish. In Windows PowerShell call curl.exe, not curl (an alias for something else there).',
        ];
    }

    /**
     * Validates a finished file and puts it in place — the one path every
     * upload ends in, whether its bytes came inline, from a URL, in slices or
     * by direct PUT.
     *
     * It does what the Contao file manager does with an upload, in the same
     * order, because a file that reaches `files/` through MCP should not be
     * different from one a person uploaded: the name is cleaned, an SVG is
     * sanitised, images are held to the configured dimensions, the
     * `postUpload` hook runs. On top of that it checks the content against the
     * extension, which the backend does not.
     *
     * It works on a file on disk, not on a string: the size of an upload must
     * not decide how much memory the request needs. Only the first bytes are
     * read for the signature check; markup is read whole, and is small.
     *
     * The file reaches its destination by copy-then-rename, so an overwrite
     * that fails half-way leaves the old file intact instead of a truncated
     * one.
     *
     * @return array<string, mixed>
     */
    private function storeFile(
        string $parent_path,
        string $parentAbs,
        string $requestedName,
        string $source,
        bool $overwrite,
        mixed $meta,
    ): array {
        $validator = $this->makeValidator();

        $named = UploadValidator::sanitiseName($requestedName);
        if (!$named['ok']) {
            return ['error' => $named['error'], 'message' => $named['message']];
        }
        $name = $named['name'];

        clearstatcache(true, $source);
        $size = (int) @filesize($source);

        $validation = $validator->validateUpload($name, $size);
        if (!($validation['ok'] ?? false)) {
            unset($validation['ok']);

            return $validation;
        }

        $extension = strtolower(pathinfo($name, \PATHINFO_EXTENSION));
        $extra = [];

        // The cleaned SVG is held here and written next to the target, never
        // back over $source: for a chunked upload $source is the session
        // buffer, which must keep its declared size so a finish that is refused
        // (file exists, folder gone) can simply be repeated.
        $sanitisedSvg = null;

        if (\in_array($extension, UploadValidator::SVG_EXTENSIONS, true)) {
            $raw = (string) @file_get_contents($source);
            $svg = (new SvgSanitizer())->sanitize($raw, str_starts_with($raw, "\x1F\x8B"));

            if (!$svg['ok']) {
                return ['error' => $svg['error'], 'message' => $svg['message']];
            }

            if ($svg['bytes'] !== $raw) {
                $sanitisedSvg = $svg['bytes'];
                $extra['sanitized'] = $svg['removed'] !== []
                    ? ['removed' => $svg['removed']]
                    : ['removed' => [], 'note' => 'Normalised the way the Contao backend normalises SVGs (DOCTYPE and comments dropped); nothing active was found.'];
            }
            unset($raw, $svg);
        } else {
            // The name says what the server will serve it as; only the bytes
            // say what it is. Checked before anything touches files/.
            $sample = UploadValidator::needsFullContent($extension)
                ? (string) @file_get_contents($source)
                : (string) @file_get_contents($source, false, null, 0, 4096);

            $contentCheck = $validator->validateContent($name, $sample);
            if (!($contentCheck['ok'] ?? false)) {
                unset($contentCheck['ok']);

                return $contentCheck;
            }
        }

        $tooLarge = $this->refuseOversizedImage($extension, $source);
        if ($tooLarge !== null) {
            return $tooLarge;
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

        $partial = $parentAbs.\DIRECTORY_SEPARATOR.'.mcp-upload-'.bin2hex(random_bytes(6));
        $written = $sanitisedSvg !== null
            ? false !== @file_put_contents($partial, $sanitisedSvg)
            : @copy($source, $partial);

        if (!$written || !@rename($partial, $targetAbs)) {
            @unlink($partial);

            return ['error' => 'write_failed', 'message' => "Could not write {$targetRelative}. Check that the folder is writable for the web server."];
        }
        unset($sanitisedSvg);

        // What the backend sets, so a file uploaded here is as readable to the
        // rest of the system as one uploaded there.
        @chmod($targetAbs, 0o666 & ~umask());

        $dbafsPath = $this->paths->toDbafsPath($targetRelative);

        $resized = $this->resizeIfConfigured($extension, $dbafsPath);
        if ($resized !== null) {
            $extra['resized_to'] = $resized;
        }

        try {
            $model = Dbafs::addResource($dbafsPath);
        } catch (\Throwable $e) {
            $this->logger->error('MCP upload: the file was written but could not be registered in tl_files.', ['path' => $dbafsPath, 'exception' => $e]);

            return [
                'error' => 'registration_failed',
                'message' => sprintf('%s was written, but could not be registered in tl_files, so it has no UUID yet. An administrator can fix that with dbafs_sync; re-uploading with overwrite=true also works.', $targetRelative),
            ];
        }

        // Apply the already-validated meta.
        if ($encodedMeta !== null && $model instanceof FilesModel) {
            $model->meta = $encodedMeta;
            $model->save();
        }

        $warnings = $this->runPostUploadHooks($dbafsPath);
        if ($warnings !== []) {
            $extra['warnings'] = $warnings;
        }

        clearstatcache(true, $targetAbs);
        $storedSize = (int) @filesize($targetAbs);

        $this->log(sprintf('Uploaded file %s (%d bytes) via MCP', $dbafsPath, $storedSize), __METHOD__);

        if ($name !== trim($requestedName)) {
            $extra['name_changed_from'] = $requestedName;
        }

        return ($model instanceof FilesModel
                ? $this->serializer->summary($model)
                : $this->serializer->fromFilesystem($targetAbs, $targetRelative))
            + ['uploaded' => true, 'size_bytes' => $storedSize]
            + $extra;
    }

    /**
     * Refuses an image larger than the configured maximum dimensions, when the
     * site is set to refuse instead of scale (`contao.image.reject_large_uploads`)
     * — the same rule and the same condition as the backend.
     *
     * @return array{error: string, message: string}|null
     */
    private function refuseOversizedImage(string $extension, string $source): ?array
    {
        if (!\in_array($extension, self::RASTER_EXTENSIONS, true)) {
            return null;
        }

        $config = $this->framework->getAdapter(Config::class);
        $maxWidth = (int) $config->get('imageWidth');
        $maxHeight = (int) $config->get('imageHeight');

        if ($maxWidth < 1 || $maxHeight < 1 || !$this->parameter('contao.image.reject_large_uploads')) {
            return null;
        }

        $dimensions = @getimagesize($source);

        if (!\is_array($dimensions) || ($dimensions[0] <= $maxWidth && $dimensions[1] <= $maxHeight)) {
            return null;
        }

        return [
            'error' => 'image_too_large',
            'message' => sprintf('The image is %d × %d px; this site accepts at most %d × %d px. Scale it down before uploading.', $dimensions[0], $dimensions[1], $maxWidth, $maxHeight),
        ];
    }

    /**
     * Scales a stored image down to tl_settings.imageWidth / imageHeight, as
     * the backend does after every upload. Off unless one of them is set.
     *
     * @return array{width: int, height: int}|null the new size, or null when nothing was scaled
     */
    private function resizeIfConfigured(string $extension, string $dbafsPath): ?array
    {
        $config = $this->framework->getAdapter(Config::class);
        $maxWidth = (int) $config->get('imageWidth');
        $maxHeight = (int) $config->get('imageHeight');

        if (($maxWidth < 1 && $maxHeight < 1) || !\in_array($extension, self::RASTER_EXTENSIONS, true)) {
            return null;
        }

        try {
            $file = new ContaoFile($dbafsPath);

            if (!$file->isGdImage) {
                return null;
            }

            [$width, $height] = $file->imageSize + [0, 0];

            if ($width < 1 || $height < 1) {
                return null;
            }

            $scale = min(
                $maxWidth > 0 ? $maxWidth / $width : 1.0,
                $maxHeight > 0 ? $maxHeight / $height : 1.0,
                1.0,
            );

            if ($scale >= 1.0) {
                return null;
            }

            $newWidth = (int) round($width * $scale);
            $newHeight = (int) round($height * $scale);
            $file->resizeTo($newWidth, $newHeight);

            return ['width' => $newWidth, 'height' => $newHeight];
        } catch (\Throwable $e) {
            // Scaling is a nicety on top of a successful upload; a GD that
            // cannot read the format must not turn that into a failure.
            $this->logger->warning('MCP upload: could not scale the image down.', ['path' => $dbafsPath, 'exception' => $e]);

            return null;
        }
    }

    /**
     * Runs the `postUpload` hook, as the file manager does after an upload.
     *
     * Extensions hang real work on it — image optimisation, WebP generation,
     * virus scanning — and a file that skipped it is a file those extensions
     * never saw. A hook that fails here is reported, not fatal: the file is
     * already in place, and most hooks were written for a backend request and
     * may trip over the lack of one.
     *
     * @return list<string> one line per hook that failed
     */
    private function runPostUploadHooks(string $dbafsPath): array
    {
        $hooks = $GLOBALS['TL_HOOKS']['postUpload'] ?? null;

        if (!\is_array($hooks) || $hooks === []) {
            return [];
        }

        $system = $this->framework->getAdapter(System::class);
        $warnings = [];

        foreach ($hooks as $callback) {
            try {
                if (\is_array($callback)) {
                    $system->importStatic($callback[0])->{$callback[1]}([$dbafsPath]);
                } elseif (\is_callable($callback)) {
                    $callback([$dbafsPath]);
                }
            } catch (\Throwable $e) {
                $label = \is_array($callback) ? implode('::', array_map('strval', $callback)) : 'closure';
                $this->logger->warning('MCP upload: a postUpload hook failed.', ['hook' => $label, 'path' => $dbafsPath, 'exception' => $e]);
                $warnings[] = sprintf('The postUpload hook %s failed (%s). The file is stored; whatever that hook does after an upload did not happen.', $label, $e->getMessage());
            }
        }

        return $warnings;
    }

    private function parameter(string $name): bool
    {
        return $this->parameters->has($name) && (bool) $this->parameters->get($name);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'file_update_meta',
        description: 'Updates the localised metadata (title, alt, caption, link) of a file. `path` is relative to the upload directory — the `path` field from files_search — but the `dbafs_path` spelling with the leading upload directory is accepted too. Pass meta as a dict like {"de": {"title": "…", "alt": "…"}, "en": {...}}. Replaces existing meta. Logged to tl_log.',
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
            return ToolError::opaque($this->logger, $e, 'delete_failed', 'Tool/File/Tool delete_failed');
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
            return ToolError::opaque($this->logger, $e, 'rename_failed', 'Tool/File/Tool rename_failed');
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
            return ToolError::opaque($this->logger, $e, 'move_failed', 'Tool/File/Tool move_failed');
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
            return ToolError::opaque($this->logger, $e, 'create_failed', 'Tool/File/Tool create_failed');
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
            return ToolError::opaque($this->logger, $e, 'delete_failed', 'Tool/File/Tool delete_failed');
        }
        Dbafs::deleteResource($dbafsPath);

        $this->log(sprintf('Deleted folder %s via MCP (cascade=%s, cascaded=%d)', $dbafsPath, $cascade ? 'true' : 'false', \count($entries)), __METHOD__);

        return ['deleted' => true, 'path' => $path, 'cascaded_entries' => \count($entries)];
    }

    // -----------------------------------------------------------------

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
