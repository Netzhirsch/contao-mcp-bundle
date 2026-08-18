<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service\Usage;

/**
 * Finds references to a file that live INSIDE other files.
 *
 * The database only knows references a backend widget wrote. A stylesheet
 * that does `@import 'base';` or `url(../img/logo.svg)`, a template that
 * hardcodes `files/theme/logo.svg` — none of that appears in tl_files or any
 * DCA column, yet deleting the target breaks the site just as thoroughly.
 *
 * Two kinds of hit, and the difference matters because one of them blocks a
 * deletion:
 *
 *   CERTAIN   the file's path or UUID appears literally, or a stylesheet
 *             `@import`/`@use`/`@forward`/`url()` resolves to it — including
 *             the SCSS partial spelling, where `_base.scss` is imported as
 *             `'base'` and a literal path search finds nothing.
 *   POSSIBLE  the bare file name shows up somewhere else. Reported so a human
 *             can look, never strong enough to refuse a deletion.
 */
final class FileContentScanner
{
    /** Text formats worth reading. Anything else is treated as opaque binary. */
    private const EXTENSIONS = [
        'css' => true, 'scss' => true, 'sass' => true, 'less' => true,
        'js' => true, 'mjs' => true, 'cjs' => true, 'ts' => true,
        'html' => true, 'htm' => true, 'html5' => true, 'twig' => true, 'php' => true,
        'xml' => true, 'json' => true, 'svg' => true, 'md' => true, 'txt' => true,
        'yml' => true, 'yaml' => true,
    ];

    /** Stylesheet formats whose import statements we resolve by name. */
    private const STYLESHEET_EXTENSIONS = ['css' => true, 'scss' => true, 'sass' => true, 'less' => true];

    private const MAX_FILES = 4000;
    private const MAX_FILE_BYTES = 1048576; // 1 MiB

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @return array{references: list<array<string, mixed>>, files_scanned: int, notes: list<string>}
     */
    public function scan(UsageTarget $target, int $limit): array
    {
        $references = [];
        $notes = [];
        $scanned = 0;

        if ($limit <= 0 || null === $target->path || '' === $target->path) {
            return ['references' => $references, 'files_scanned' => 0, 'notes' => $notes];
        }

        $needles = $this->certainNeedles($target);
        $basename = basename($target->path);
        $importName = self::normaliseImportName($basename);
        $targetReal = realpath($this->projectDir.'/'.$target->path) ?: null;

        foreach ($this->roots() as $root) {
            foreach ($this->walk($root) as $file) {
                if ($scanned >= self::MAX_FILES) {
                    $notes[] = sprintf('Stopped after %d files — file-content results may be incomplete.', self::MAX_FILES);
                    break 2;
                }

                if (\count($references) >= $limit) {
                    break 2;
                }

                // A file never counts as its own referrer, and neither does
                // anything inside a folder that is being deleted with it.
                if (null !== $targetReal && str_starts_with($file, $targetReal)) {
                    continue;
                }

                $content = $this->read($file);

                if (null === $content) {
                    continue;
                }

                ++$scanned;
                $relative = $this->relative($file);
                $hit = null;

                foreach ($needles as $needle => $identity) {
                    $position = strpos($content, (string) $needle);

                    if (false !== $position) {
                        $hit = [
                            'confidence' => UsageScanner::CONFIDENCE_CERTAIN,
                            'blocking' => true,
                            'identity' => $identity,
                            'detail' => sprintf(
                                '%s contains the literal %s',
                                $relative,
                                UsageScanner::IDENTITY_UUID === $identity ? 'UUID' : 'path',
                            ),
                            'snippet' => self::line($content, $position),
                        ];
                        break;
                    }
                }

                if (null === $hit && '' !== $importName && $this->importsByName($file, $content, $importName)) {
                    $hit = [
                        'confidence' => UsageScanner::CONFIDENCE_CERTAIN,
                        'blocking' => true,
                        // An @import resolves by file NAME, so renaming the
                        // partial breaks it just as surely as deleting it.
                        'identity' => UsageScanner::IDENTITY_PATH,
                        'detail' => sprintf('%s imports it by name (stylesheet @import/@use/url)', $relative),
                        'snippet' => self::line($content, (int) strpos($content, $importName)),
                    ];
                }

                if (null === $hit && false !== ($position = strpos($content, $basename))) {
                    $hit = [
                        'confidence' => UsageScanner::CONFIDENCE_POSSIBLE,
                        'blocking' => false,
                        'identity' => UsageScanner::IDENTITY_PATH,
                        'detail' => sprintf('%s mentions the file name — check whether it means this file', $relative),
                        'snippet' => self::line($content, $position),
                    ];
                }

                if (null !== $hit) {
                    $references[] = ['source' => 'file_content', 'file' => $relative, ...$hit];
                }
            }
        }

        return ['references' => $references, 'files_scanned' => $scanned, 'notes' => $notes];
    }

    /**
     * References from one template to another.
     *
     * A template that other templates build on cannot be deleted without
     * breaking them, and none of that is visible in the database:
     *
     *   Twig    {% extends "@Contao/ce_text_my.html.twig" %}, {% include %},
     *           {% embed %}, {% use %}, {{ include('…') }}
     *   Legacy  $this->extend('block_searchable'), $this->insert('mod_x')
     *
     * Matched on the template NAME, because that is what both syntaxes carry —
     * a path search would miss `{% extends "@Contao/x" %}` entirely.
     *
     * @return array{references: list<array<string, mixed>>, files_scanned: int, notes: list<string>}
     */
    public function scanTemplate(UsageTarget $target, int $limit): array
    {
        $references = [];
        $notes = [];
        $scanned = 0;
        $names = $target->aliases;

        if ($limit <= 0 || [] === $names) {
            return ['references' => $references, 'files_scanned' => 0, 'notes' => $notes];
        }

        $self = realpath($this->projectDir.'/'.(string) $target->path) ?: null;

        foreach ($this->templateRoots() as $root) {
            foreach ($this->walk($root) as $file) {
                if ($scanned >= self::MAX_FILES) {
                    $notes[] = sprintf('Stopped after %d files — template results may be incomplete.', self::MAX_FILES);
                    break 2;
                }

                if (\count($references) >= $limit) {
                    break 2;
                }

                // A template is not its own referrer.
                if (null !== $self && $file === $self) {
                    continue;
                }

                $content = $this->read($file);

                if (null === $content) {
                    continue;
                }

                ++$scanned;

                foreach ($names as $name) {
                    $position = self::findTemplateUse($content, $name);

                    if (null === $position) {
                        continue;
                    }

                    $references[] = [
                        'source' => 'template',
                        'file' => $this->relative($file),
                        'confidence' => UsageScanner::CONFIDENCE_CERTAIN,
                        'blocking' => true,
                        // Templates are pulled in by NAME, so a rename breaks
                        // this exactly as a deletion would.
                        'identity' => UsageScanner::IDENTITY_NAME,
                        'detail' => sprintf('%s extends/includes this template', $this->relative($file)),
                        'snippet' => self::line($content, $position),
                    ];
                    break;
                }
            }
        }

        return ['references' => $references, 'files_scanned' => $scanned, 'notes' => $notes];
    }

    /**
     * Position of a Twig or legacy statement that pulls in `$name`, or null.
     *
     * Anchored on the statement keyword AND on a delimiter after the name, so
     * `{% extends "ce_text_my_variant" %}` is not read as a use of
     * `ce_text_my`, and a name mentioned in a comment is not a dependency.
     */
    private static function findTemplateUse(string $content, string $name): ?int
    {
        $quoted = preg_quote($name, '/');

        // Twig: the logical name may carry a @Namespace prefix and the
        // extension; both are optional in Contao's managed namespace.
        $patterns = [
            '/\{%-?\s*(?:extends|include|embed|use|import|from)\s+[\'"][^\'"]*?'.$quoted.'(?:\.html\.twig|\.html5)?[\'"]/i',
            '/\binclude\s*\(\s*[\'"][^\'"]*?'.$quoted.'(?:\.html\.twig|\.html5)?[\'"]/i',
            // Legacy PHP templates.
            '/\$this->(?:extend|insert)\s*\(\s*[\'"]'.$quoted.'[\'"]/i',
        ];

        foreach ($patterns as $pattern) {
            if (1 === preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
                return (int) $m[0][1];
            }
        }

        return null;
    }

    /**
     * Where a template reference can be written. Only project templates —
     * a bundle's own templates never depend on a project override.
     *
     * @return list<string>
     */
    private function templateRoots(): array
    {
        $roots = [];

        foreach (['templates', 'contao/templates'] as $candidate) {
            $path = realpath($this->projectDir.'/'.$candidate);

            if (false !== $path && is_dir($path)) {
                $roots[] = $path;
            }
        }

        return $roots;
    }

    /**
     * Spellings that prove a reference, each mapped to what it is anchored on:
     * the DBAFS path, the same path without the upload prefix, and the UUID as
     * written in `{{file::…}}`.
     *
     * The identity matters because a rename changes the path and keeps the
     * UUID — so a hit on the UUID survives it and must not block.
     *
     * @return array<string, string> needle => identity
     */
    private function certainNeedles(UsageTarget $target): array
    {
        $needles = [];
        $path = (string) $target->path;

        if ('' !== $path) {
            $needles[$path] = UsageScanner::IDENTITY_PATH;

            if (false !== ($slash = strpos($path, '/'))) {
                $needles[substr($path, $slash + 1)] = UsageScanner::IDENTITY_PATH;
            }
        }

        if (null !== $target->uuid && '' !== $target->uuid) {
            $needles[$target->uuid] = UsageScanner::IDENTITY_UUID;
        }

        // Longest first: matching "files/x/logo.svg" is more informative than
        // matching the "x/logo.svg" tail it contains.
        uksort($needles, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return $needles;
    }

    /**
     * Resolves stylesheet imports by NAME, which is the only way to catch an
     * SCSS partial: `_base.scss` is imported as `@import 'base';`, so no
     * literal path search can ever see it.
     */
    private function importsByName(string $file, string $content, string $importName): bool
    {
        if (!isset(self::STYLESHEET_EXTENSIONS[strtolower(pathinfo($file, \PATHINFO_EXTENSION))])) {
            return false;
        }

        if (0 === preg_match_all('/(?:@(?:import|use|forward)\s+([^;{]+)|url\(\s*([^)]+)\))/i', $content, $matches, PREG_SET_ORDER)) {
            return false;
        }

        foreach ($matches as $match) {
            $argument = '' !== ($match[1] ?? '') ? $match[1] : ($match[2] ?? '');

            // One statement can list several: @import 'a', 'b';
            if (0 === preg_match_all('/[\'"]?([^\'",\s]+)[\'"]?/', $argument, $parts)) {
                continue;
            }

            foreach ($parts[1] as $candidate) {
                if (self::normaliseImportName(basename($candidate)) === $importName) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * `_base.scss` → `base`, `logo.svg?v=2` → `logo`. Makes the partial
     * spelling and the full file name comparable.
     */
    private static function normaliseImportName(string $name): string
    {
        $name = (string) preg_replace('/[?#].*$/', '', trim($name, "'\" \t\n\r"));
        $name = pathinfo($name, \PATHINFO_FILENAME);

        return strtolower(ltrim($name, '_'));
    }

    /**
     * Where a reference to an uploaded file can plausibly be written. The
     * upload folder itself (stylesheets referencing each other) and the
     * template folder; vendor/ and var/ are generated or third-party.
     *
     * @return list<string>
     */
    private function roots(): array
    {
        $roots = [];

        // No `assets/` — that is published vendor output: thousands of files
        // that would eat the scan budget and never contain a hand-written
        // reference worth reporting.
        foreach (['files', 'templates', 'contao'] as $candidate) {
            $path = realpath($this->projectDir.'/'.$candidate);

            if (false !== $path && is_dir($path)) {
                $roots[] = $path;
            }
        }

        return $roots;
    }

    /**
     * @return \Generator<string>
     */
    private function walk(string $root): \Generator
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );
        } catch (\Throwable) {
            return;
        }

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                continue;
            }

            if (!isset(self::EXTENSIONS[strtolower($item->getExtension())])) {
                continue;
            }

            yield $item->getPathname();
        }
    }

    private function read(string $file): ?string
    {
        $size = @filesize($file);

        if (false === $size || $size > self::MAX_FILE_BYTES) {
            return null;
        }

        $content = @file_get_contents($file);

        return \is_string($content) && '' !== $content ? $content : null;
    }

    /**
     * Paths reported back must be project-relative — an absolute one leaks the
     * server layout into the MCP response and is useless to the caller.
     *
     * The comparison goes through realpath on BOTH sides: the scanned paths
     * come from the iterator over a realpath'd root, so a project dir that is
     * a symlink — or a Windows 8.3 short name like `C:\Users\JAN-PH~1\…` —
     * would otherwise not match its own expanded form, and every path would
     * come back absolute.
     */
    private function relative(string $file): string
    {
        $normalised = str_replace('\\', '/', $file);

        foreach ([realpath($this->projectDir), $this->projectDir] as $candidate) {
            if (!\is_string($candidate) || '' === $candidate) {
                continue;
            }

            $base = rtrim(str_replace('\\', '/', $candidate), '/').'/';

            if (str_starts_with($normalised, $base)) {
                return substr($normalised, \strlen($base));
            }
        }

        return $normalised;
    }

    private static function line(string $content, int $position): string
    {
        $position = max(0, $position);
        $before = strrpos(substr($content, 0, $position), "\n");
        $start = false === $before ? 0 : $before + 1;
        $end = strpos($content, "\n", $position);

        $line = trim(substr($content, $start, false === $end ? 200 : $end - $start));

        if (!mb_check_encoding($line, 'UTF-8')) {
            $line = (string) mb_convert_encoding($line, 'UTF-8', 'UTF-8');
        }

        return mb_strimwidth($line, 0, 160, '…');
    }
}
