<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Template;

use Contao\CoreBundle\Twig\Finder\FinderFactory;
use Contao\CoreBundle\Twig\Loader\ContaoFilesystemLoader;
use PhpMcp\Server\Attributes\McpTool;
use Symfony\Component\Finder\Finder;
use Twig\Environment as TwigEnvironment;
use Twig\Error\SyntaxError;
use Twig\Source as TwigSource;

/**
 * Template tools. Two worlds:
 *
 *   1. Originals  — `vendor/* /contao/templates/...` (read-only). Live in
 *                   Bundle directories; modifying them would break on the
 *                   next `composer update`. We can READ them (as a starting
 *                   point for new overrides) and LIST them.
 *
 *   2. Overrides  — `templates/` (writable). This is the project's own
 *                   override directory; Contao's template loader prefers
 *                   files here over Bundle defaults. CRUD ops target only
 *                   this tree.
 *
 * Both worlds carry two file formats: `.html5` (legacy PHP) and `.html.twig`
 * (modern Twig). Paths passed to the override-CRUD tools are relative to
 * `templates/`. Subfolders are allowed and follow the Contao 5 convention
 * (e.g. `content_element/text.html.twig`).
 */
final class Tool
{
    /**
     * Allowed file extensions for override-CRUD. The two-part `html.twig`
     * is matched as a suffix because pathinfo() would only see `.twig`.
     *
     * @var list<string>
     */
    private const ALLOWED_EXTENSIONS = ['.html5', '.html.twig'];

    public function __construct(
        private readonly string $projectDir,
        private readonly TwigEnvironment $twig,
        private readonly ContaoFilesystemLoader $contaoLoader,
        private readonly FinderFactory $finderFactory,
    ) {
    }

    /**
     * @return array{prefix: string|null, items: array<string, list<string>>, count: int}
     */
    #[McpTool(
        name: 'templates_list',
        description: <<<'DESC'
            Lists every Contao template available in this project — bundle originals and
            project overrides, deduplicated, without their extension.

            Two naming worlds, and BOTH are listed. Legacy templates are flat names with a
            prefix (`mod_article`, `ce_text`, `news_full`). Modern Contao 5 templates carry
            their group as a path (`frontend_module/navigation`, `content_element/text`) —
            those come from Contao's own Twig inheritance chain, which is what actually
            renders. A module type does NOT necessarily share its template's name.

            Without arguments every template lands in exactly one group: its directory for
            modern ones, its prefix for legacy ones, and `other` for everything else, so
            nothing is invisible. With `prefix` you get one flat list — the prefix matches
            the start of the identifier, so "frontend_module/" and "mod_" both work.

            Use template_lookup to see which layer of an identifier actually wins.
            DESC,
    )]
    public function templatesList(?string $prefix = null): array
    {
        $all = $this->scanAllTemplates();
        sort($all);

        if ($prefix !== null && $prefix !== '') {
            $matching = array_values(array_filter($all, static fn (string $name): bool => str_starts_with($name, $prefix)));

            return [
                'prefix' => $prefix,
                'items' => [$prefix => $matching],
                'count' => \count($matching),
            ];
        }

        // Every template lands in exactly one group. The old version reported
        // only the fourteen legacy prefixes, which made every modern template
        // (frontend_module/…, content_element/…) invisible in every listing —
        // the reason AL-07 resorted to five throwaway modules and a preview to
        // find out what a footer renders.
        $groups = [];
        foreach ($all as $name) {
            $groups[self::groupOf($name)][] = $name;
        }

        ksort($groups);

        return [
            'prefix' => null,
            'items' => $groups,
            'count' => \count($all),
        ];
    }

    /**
     * The one group a template identifier belongs to: its directory for modern
     * Contao 5 templates, its prefix for legacy ones, `other` for the rest.
     */
    private static function groupOf(string $name): string
    {
        if (str_contains($name, '/')) {
            return explode('/', $name)[0];
        }

        foreach (self::COMMON_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return $prefix;
            }
        }

        return 'other';
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'template_overrides_list',
        description: 'Lists every template file currently in the project override directory `templates/` (recursive). Each entry has path, name, extension, size, last_modified, is_component (Twig partial — filename starts with "_"), theme (slug if the file lives under templates/<theme>/, else null). Optional prefix filters by template-name prefix like "news_" or "mod_". Optional theme filters to one theme slug (use "" to get only non-themed overrides).',
    )]
    public function overridesList(?string $prefix = null, ?string $theme = null): array
    {
        $base = $this->overridesBaseDir();
        if (!is_dir($base)) {
            return ['items' => [], 'count' => 0, 'base_dir' => 'templates'];
        }

        $finder = (new Finder())
            ->files()
            ->in($base)
            ->name(['*.html5', '*.html.twig'])
            ->sortByName();

        $items = [];
        foreach ($finder as $file) {
            $rel = str_replace('\\', '/', $file->getRelativePathname());
            $name = self::stripExtension(basename($rel));
            if ($prefix !== null && $prefix !== '' && !str_starts_with($name, $prefix)) {
                continue;
            }
            $themeSlug = self::extractThemeSlug($rel);
            if ($theme !== null && $themeSlug !== $theme && !($theme === '' && $themeSlug === null)) {
                continue;
            }
            $items[] = [
                'path' => $rel,
                'name' => $name,
                'extension' => self::extensionOf($rel),
                'is_component' => str_starts_with(basename($rel), '_'),
                'theme' => $themeSlug,
                'size' => $file->getSize(),
                'last_modified' => $file->getMTime(),
            ];
        }

        return [
            'items' => $items,
            'count' => \count($items),
            'base_dir' => 'templates',
            'prefix' => $prefix,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'template_get',
        description: 'Reads a template file. `path` is the file path including extension (e.g. "news_full.html5" or "content_element/text.html.twig"). `source` controls where to look: "auto" (default) prefers the project override and falls back to the first Bundle source; "override" looks only under templates/; "original" looks only at Bundle-shipped originals.',
    )]
    public function templateGet(string $path, string $source = 'auto'): array
    {
        $err = $this->validatePath($path);
        if ($err !== null) {
            return $err;
        }
        $source = \in_array($source, ['auto', 'override', 'original'], true) ? $source : 'auto';

        $overrideAbs = $this->overridesBaseDir().\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $path);
        $hasOverride = is_file($overrideAbs);

        if ($source === 'override') {
            if (!$hasOverride) {
                return ['error' => 'not_found', 'message' => "No override at templates/{$path}"];
            }
            return $this->readFile($overrideAbs, $path, 'override');
        }

        if ($source === 'original') {
            $orig = $this->findOriginalSource($path);
            if ($orig === null) {
                return ['error' => 'not_found', 'message' => "No Bundle original found for {$path}"];
            }
            return $this->readFile($orig, $path, 'original', $orig);
        }

        // auto
        if ($hasOverride) {
            return $this->readFile($overrideAbs, $path, 'override');
        }
        $orig = $this->findOriginalSource($path);
        if ($orig === null) {
            return ['error' => 'not_found', 'message' => "No override at templates/{$path} and no Bundle original found."];
        }
        return $this->readFile($orig, $path, 'original', $orig);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'template_create',
        description: 'Creates a new override under templates/. Exactly one of `content` (raw template content) or `copy_from` (a Bundle-template path to clone, e.g. "news_full.html5" or "content_element/text.html.twig") must be provided. Refuses to overwrite by default — pass overwrite=true to replace. Parent subfolders are created automatically. Optional `theme` (slug) puts the override under templates/<theme>/<path> for theme-scoped overrides (Contao 5 Template Studio convention). When path starts with `_` the file is treated as a Twig component-template (partial — included via {% embed %} / {% include %}, not rendered directly).',
    )]
    public function templateCreate(
        string $path,
        ?string $content = null,
        ?string $copy_from = null,
        bool $overwrite = false,
        ?string $theme = null,
    ): array {
        $err = $this->validatePath($path);
        if ($err !== null) {
            return $err;
        }

        if (($content === null) === ($copy_from === null)) {
            return [
                'error' => 'invalid_input',
                'message' => 'Provide exactly one of `content` (raw template content) or `copy_from` (Bundle template path to clone).',
            ];
        }

        // Prefix the path with the theme slug if specified.
        $effectivePath = $theme !== null && $theme !== '' ? trim($theme, '/').'/'.$path : $path;
        if ($theme !== null && $theme !== '') {
            // Re-validate the assembled path so we don't sneak path traversal
            // through `theme`.
            $themeErr = $this->validatePath($effectivePath);
            if ($themeErr !== null) {
                return ['error' => 'invalid_theme', 'message' => 'Theme slug + path resolves to an invalid override location: '.$themeErr['message']];
            }
        }

        $target = $this->overridesBaseDir().\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $effectivePath);
        if (file_exists($target) && !$overwrite) {
            return ['error' => 'already_exists', 'message' => "Template override already exists at templates/{$effectivePath}. Pass overwrite=true to replace."];
        }

        if ($copy_from !== null) {
            $err2 = $this->validatePath($copy_from);
            if ($err2 !== null) {
                return ['error' => 'invalid_copy_from', 'message' => $err2['message']];
            }
            $sourceAbs = $this->findOriginalSource($copy_from);
            if ($sourceAbs === null) {
                return ['error' => 'source_not_found', 'message' => "No Bundle original found for {$copy_from}"];
            }
            $bytes = @file_get_contents($sourceAbs);
            if ($bytes === false) {
                return ['error' => 'read_failed', 'message' => "Could not read original from {$sourceAbs}"];
            }
        } else {
            $bytes = (string) $content;
        }

        // Level 1: pre-save Twig lint. Only for .html.twig — .html5 is plain PHP
        // and would need a separate php-lint pass we don't want here.
        if (($lintErr = $this->lintTwigIfApplicable($effectivePath, $bytes)) !== null) {
            return $lintErr;
        }

        $parent = \dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0o775, true) && !is_dir($parent)) {
            return ['error' => 'mkdir_failed', 'message' => "Could not create parent directory {$parent}"];
        }

        if (@file_put_contents($target, $bytes) === false) {
            return ['error' => 'write_failed', 'message' => "Could not write override to {$target}"];
        }

        return $this->readFile($target, $effectivePath, 'override') + [
            'created' => true,
            'copied_from' => $copy_from !== null ? $sourceAbs : null,
            'theme' => $theme ?: null,
            'is_component' => str_starts_with(basename($effectivePath), '_'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'template_update',
        description: 'Overwrites the content of an existing template override. Refuses if the override does not exist (use template_create for new files).',
    )]
    public function templateUpdate(string $path, string $content): array
    {
        $err = $this->validatePath($path);
        if ($err !== null) {
            return $err;
        }

        $target = $this->overridesBaseDir().\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $path);
        if (!is_file($target)) {
            return ['error' => 'not_found', 'message' => "Override does not exist at templates/{$path}. Use template_create to add it."];
        }

        if (($lintErr = $this->lintTwigIfApplicable($path, $content)) !== null) {
            return $lintErr;
        }

        if (@file_put_contents($target, $content) === false) {
            return ['error' => 'write_failed', 'message' => "Could not write override to {$target}"];
        }

        return $this->readFile($target, $path, 'override') + ['updated' => true];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'template_delete',
        description: 'Deletes a template override from templates/. Affects only the override file — the Bundle original keeps existing and Contao will fall back to it. Requires confirm_destructive=true to proceed.',
    )]
    public function templateDelete(string $path, bool $confirm_destructive = false): array
    {
        if (!$confirm_destructive) {
            return [
                'error' => 'destructive_confirmation_required',
                'message' => 'template_delete is irreversible. Pass confirm_destructive=true to proceed.',
            ];
        }

        $err = $this->validatePath($path);
        if ($err !== null) {
            return $err;
        }

        $target = $this->overridesBaseDir().\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $path);
        if (!is_file($target)) {
            return ['error' => 'not_found', 'message' => "Override does not exist at templates/{$path}"];
        }

        if (!@unlink($target)) {
            return ['error' => 'delete_failed', 'message' => "Could not delete {$target}"];
        }

        // Best-effort: also remove now-empty parent directories (but not the templates root).
        $parent = \dirname($target);
        $base = realpath($this->overridesBaseDir()) ?: $this->overridesBaseDir();
        while ($parent !== $base && is_dir($parent) && (@scandir($parent) === ['.', '..'])) {
            @rmdir($parent);
            $parent = \dirname($parent);
        }

        return ['deleted' => true, 'path' => $path];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'template_rename',
        description: 'Renames or moves a template override inside templates/. new_path may include subfolders (which will be created if missing). Refuses if the target already exists.',
    )]
    public function templateRename(string $path, string $new_path): array
    {
        $err = $this->validatePath($path);
        if ($err !== null) {
            return $err;
        }
        $err2 = $this->validatePath($new_path);
        if ($err2 !== null) {
            return ['error' => 'invalid_new_path', 'message' => $err2['message']];
        }
        if ($path === $new_path) {
            return ['error' => 'no_change', 'message' => 'Source and target paths are identical.'];
        }

        $base = $this->overridesBaseDir();
        $oldAbs = $base.\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $path);
        $newAbs = $base.\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $new_path);

        if (!is_file($oldAbs)) {
            return ['error' => 'not_found', 'message' => "Override does not exist at templates/{$path}"];
        }
        if (file_exists($newAbs)) {
            return ['error' => 'target_exists', 'message' => "Target already exists at templates/{$new_path}"];
        }

        $parent = \dirname($newAbs);
        if (!is_dir($parent) && !@mkdir($parent, 0o775, true) && !is_dir($parent)) {
            return ['error' => 'mkdir_failed', 'message' => "Could not create parent directory {$parent}"];
        }

        if (!@rename($oldAbs, $newAbs)) {
            return ['error' => 'rename_failed', 'message' => "Filesystem rename failed: {$oldAbs} → {$newAbs}"];
        }

        return $this->readFile($newAbs, $new_path, 'override') + [
            'renamed' => true,
            'from' => $path,
            'to' => $new_path,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'template_lookup',
        description: <<<'DESC'
            Returns every layer that defines this template — Bundle source, theme overrides,
            project override — with their on-disk paths and which one Contao would actually
            render. Powered by ContaoFilesystemLoader::getInheritanceChains() so it reflects
            the same hierarchy Template Studio shows in the Backend.

            `identifier` is the template short-name without extension, e.g. "content_element/text",
            "news_full", "frontend_module/navigation". Optional `theme` (slug) scopes the lookup
            to one theme's chain.
        DESC,
    )]
    public function templateLookup(string $identifier, ?string $theme = null): array
    {
        if (trim($identifier) === '') {
            return ['error' => 'invalid_input', 'message' => 'identifier must not be empty'];
        }

        try {
            $chains = $this->contaoLoader->getInheritanceChains($theme ?: null);
        } catch (\Throwable $e) {
            return ['error' => 'lookup_failed', 'message' => $e->getMessage()];
        }

        $chain = $chains[$identifier] ?? null;
        if ($chain === null) {
            // Try the short name only — Contao indexes some chains by their
            // basename and some by their "namespaced" id.
            $shortName = basename($identifier);
            $chain = $chains[$shortName] ?? null;
        }
        if ($chain === null) {
            // A bare "not found" is where AL-07 stalled: the template existed,
            // it was called frontend_module/megamenu, and the guess was
            // frontend_module/netzhirsch_megamenu — a module type is not
            // necessarily its template name. Suggestions turn the dead end into
            // a pointer.
            $suggestions = self::suggestIdentifiers($identifier, array_keys($chains));

            return [
                'error' => 'not_found',
                'message' => sprintf('No template with identifier "%s" found in the inheritance chain.', $identifier),
                'suggestions' => $suggestions,
                'hint' => $suggestions === []
                    ? 'Pass the identifier as it appears in templates_list, e.g. "content_element/text" or "news_full" (without extension).'
                    : 'A module or element TYPE is not necessarily its template name — check the suggestions, or run templates_list("frontend_module/").',
            ];
        }

        // The chain is ordered top → bottom. The TOP entry is the active one
        // (whatever overrides the rest); the bottom is the original Bundle
        // source. We tag accordingly.
        $entries = [];
        $first = true;
        foreach ($chain as $absolutePath => $shortName) {
            $rel = $this->relativeFromProject($absolutePath);
            $entries[] = [
                'short_name' => $shortName,
                'path' => $rel,
                'absolute_path' => $absolutePath,
                'layer' => $this->classifyLayer($absolutePath),
                'active' => $first,
            ];
            $first = false;
        }

        return [
            'identifier' => $identifier,
            'theme' => $theme,
            'count' => \count($entries),
            'entries' => $entries,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'template_dependencies',
        description: <<<'DESC'
            Parses a Twig template and returns every other template it depends on:
            `extends` (single string, the parent if any), `includes`, `embeds`, `uses`,
            `imports` (each a list of referenced template names).

            Only valid for `.html.twig` files. Pass the override-relative path
            (e.g. "content_element/text.html.twig"). For Bundle originals call
            template_get(source="original") first and pass the same path here.
        DESC,
    )]
    public function templateDependencies(string $path): array
    {
        $err = $this->validatePath($path);
        if ($err !== null) {
            return $err;
        }
        if (!str_ends_with($path, '.html.twig')) {
            return ['error' => 'unsupported', 'message' => 'template_dependencies only works for .html.twig files (Twig syntax).'];
        }

        // Prefer override; fall back to Bundle original.
        $overrideAbs = $this->overridesBaseDir().\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $path);
        $source = is_file($overrideAbs) ? $overrideAbs : $this->findOriginalSource($path);
        if ($source === null) {
            return ['error' => 'not_found', 'message' => "No override and no Bundle original found for {$path}"];
        }

        $bytes = @file_get_contents($source);
        if ($bytes === false) {
            return ['error' => 'read_failed', 'message' => "Could not read {$source}"];
        }

        // Twig AST analysis — `traverse` walks every node in the parsed tree.
        try {
            $ast = $this->twig->parse($this->twig->tokenize(new TwigSource($bytes, $path)));
        } catch (SyntaxError $e) {
            return [
                'error' => 'twig_syntax_error',
                'message' => $e->getRawMessage(),
                'line' => (int) $e->getTemplateLine(),
            ];
        }

        $extends = null;
        $includes = [];
        $embeds = [];
        $uses = [];
        $imports = [];

        $walker = static function ($node) use (&$walker, &$extends, &$includes, &$embeds, &$uses, &$imports): void {
            $cls = $node::class;
            // ModuleNode holds the parent reference for {% extends %}.
            if ($cls === \Twig\Node\ModuleNode::class && $node->hasNode('parent')) {
                $parent = $node->getNode('parent');
                if ($parent instanceof \Twig\Node\Expression\ConstantExpression) {
                    $extends = (string) $parent->getAttribute('value');
                }
            }
            // {% include %} / {% include from %}
            if ($cls === \Twig\Node\IncludeNode::class && $node->hasNode('expr')) {
                $expr = $node->getNode('expr');
                if ($expr instanceof \Twig\Node\Expression\ConstantExpression) {
                    $includes[] = (string) $expr->getAttribute('value');
                }
            }
            // {% embed %}
            if ($cls === \Twig\Node\EmbedNode::class && $node->hasAttribute('name')) {
                $embeds[] = (string) $node->getAttribute('name');
            }
            // {% use %}
            if (str_ends_with($cls, '\\UseNode') || str_ends_with($cls, '\\ImportNode')) {
                if ($node->hasNode('template')) {
                    $tpl = $node->getNode('template');
                    if ($tpl instanceof \Twig\Node\Expression\ConstantExpression) {
                        $name = (string) $tpl->getAttribute('value');
                        if (str_ends_with($cls, '\\UseNode')) {
                            $uses[] = $name;
                        } else {
                            $imports[] = $name;
                        }
                    }
                }
            }
            foreach ($node as $child) {
                if ($child instanceof \Twig\Node\Node) {
                    $walker($child);
                }
            }
        };
        $walker($ast);

        return [
            'path' => $path,
            'source' => $source,
            'extends' => $extends,
            'includes' => array_values(array_unique($includes)),
            'embeds' => array_values(array_unique($embeds)),
            'uses' => array_values(array_unique($uses)),
            'imports' => array_values(array_unique($imports)),
        ];
    }

    // -----------------------------------------------------------------
    // internals
    // -----------------------------------------------------------------

    /**
     * Tags an absolute template path as "bundle:<name>", "theme:<slug>", or
     * "project" so the LLM can reason about the layer without parsing paths.
     */
    /**
     * Identifiers that plausibly meant the same thing as the one asked for:
     * the same basename in another group, one containing the other, or a close
     * spelling. Ordered best-first and capped, because a long list of
     * near-misses is as useless as none.
     *
     * @param list<string> $known
     *
     * @return list<string>
     */
    private static function suggestIdentifiers(string $identifier, array $known): array
    {
        $needle = strtolower(basename($identifier));
        if ($needle === '') {
            return [];
        }

        // When the caller named a group, a candidate from the SAME group is
        // the better guess even if another one is spelled slightly closer:
        // asking for frontend_module/x means a frontend module.
        $wantedGroup = str_contains($identifier, '/') ? strtolower(\dirname($identifier)) : null;

        $scored = [];

        foreach ($known as $candidate) {
            $candidate = (string) $candidate;
            $base = strtolower(basename($candidate));
            $groupBonus = $wantedGroup !== null && str_contains($candidate, '/')
                && strtolower(\dirname($candidate)) === $wantedGroup ? -50 : 0;

            if ($base === $needle) {
                $scored[$candidate] = 0 + $groupBonus;         // same name, other group
                continue;
            }
            if (str_contains($base, $needle) || str_contains($needle, $base)) {
                $scored[$candidate] = 1 + abs(\strlen($base) - \strlen($needle)) + $groupBonus;
                continue;
            }

            $distance = levenshtein($needle, $base);
            // Only near spellings; beyond a third of the word it is a different
            // template, not a typo.
            if ($distance > 0 && $distance <= (int) max(2, \strlen($needle) / 3)) {
                $scored[$candidate] = 100 + $distance + $groupBonus;
            }
        }

        asort($scored);

        return array_slice(array_keys($scored), 0, 8);
    }

    private function classifyLayer(string $absolutePath): string
    {
        $norm = str_replace('\\', '/', $absolutePath);
        $project = str_replace('\\', '/', $this->projectDir);

        // Project override: lives under <project>/templates/...
        if (str_starts_with($norm, $project.'/templates/')) {
            $rest = substr($norm, \strlen($project.'/templates/'));
            $first = explode('/', $rest)[0] ?? '';
            // If the first segment matches a known theme folder, tag as theme.
            // Otherwise it's a plain project-level override.
            if ($first !== '' && is_dir($this->overridesBaseDir().\DIRECTORY_SEPARATOR.$first)
                && !\in_array($first, ['content_element', 'frontend_module', 'layout', 'backend', 'mail', 'form', 'twig'], true)
            ) {
                return 'theme:'.$first;
            }
            return 'project';
        }

        // Bundle: vendor/<vendor>/<bundle>/...
        if (preg_match('#/vendor/([^/]+)/([^/]+)/#', $norm, $m) === 1) {
            return 'bundle:'.$m[1].'/'.$m[2];
        }

        return 'unknown';
    }

    /**
     * Best-effort absolute → project-relative path conversion (for log output).
     */
    private function relativeFromProject(string $absolutePath): string
    {
        $norm = str_replace('\\', '/', $absolutePath);
        $project = rtrim(str_replace('\\', '/', $this->projectDir), '/').'/';
        if (str_starts_with($norm, $project)) {
            return substr($norm, \strlen($project));
        }
        return $norm;
    }

    private function overridesBaseDir(): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'templates';
    }

    /**
     * Contao 5 puts theme-scoped overrides under `templates/<theme-slug>/...`.
     * The slug is the first directory segment if and only if it matches a
     * registered theme directory the loader knows about. Anything else
     * (e.g. `templates/content_element/text.html.twig`) is project-level,
     * not theme-level.
     */
    private function extractThemeSlug(string $relPath): ?string
    {
        $segments = explode('/', $relPath);
        if (\count($segments) < 2) {
            return null;
        }
        $candidate = $segments[0];

        // Theme slugs are simple identifiers; subdirectories that match
        // Contao's known content-element subfolders (content_element/,
        // frontend_module/, layout/, ...) are NOT themes.
        $knownNonThemeSegments = ['content_element', 'frontend_module', 'layout', 'backend', 'mail', 'form', 'twig'];
        if (\in_array($candidate, $knownNonThemeSegments, true)) {
            return null;
        }

        $absDir = $this->overridesBaseDir().\DIRECTORY_SEPARATOR.$candidate;
        if (!is_dir($absDir)) {
            return null;
        }

        return $candidate;
    }

    /**
     * @return array{error: string, message: string}|null
     */
    private function validatePath(string $path): ?array
    {
        $path = trim($path);
        if ($path === '') {
            return ['error' => 'invalid_path', 'message' => 'Path is empty.'];
        }
        if (str_contains($path, "\0")) {
            return ['error' => 'invalid_path', 'message' => 'Path contains NUL byte.'];
        }
        $path = str_replace('\\', '/', $path);
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return ['error' => 'invalid_path', 'message' => "Path contains invalid segment: '{$segment}'."];
            }
        }
        if (preg_match('#^[A-Za-z]:#', $path) === 1 || str_starts_with($path, '/')) {
            return ['error' => 'invalid_path', 'message' => 'Absolute paths are not allowed.'];
        }

        if (!self::hasAllowedExtension($path)) {
            return [
                'error' => 'invalid_extension',
                'message' => "Path must end with .html5 or .html.twig: {$path}",
            ];
        }

        return null;
    }

    /**
     * Find a Bundle-source template by relative-path or basename match.
     *
     * Strategy:
     *   1. For each template directory (Bundle's contao/templates), check
     *      whether $path resolves directly (e.g. "twig/news_full.html.twig").
     *   2. Otherwise, recursively search for a file matching the basename.
     *   3. Project overrides (templates/ at project root) are NOT searched —
     *      this method is only for *originals*.
     */
    private function findOriginalSource(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        $basename = basename($path);

        foreach ($this->bundleTemplateDirectories() as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $direct = $dir.\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $path);
            if (is_file($direct)) {
                return $direct;
            }
        }

        // Fallback: recursive basename match.
        foreach ($this->bundleTemplateDirectories() as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $finder = (new Finder())->files()->in($dir)->name($basename);
            foreach ($finder as $file) {
                return $file->getPathname();
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readFile(string $absolute, string $path, string $sourceTag, ?string $sourcePath = null): array
    {
        $bytes = @file_get_contents($absolute);
        if ($bytes === false) {
            return ['error' => 'read_failed', 'message' => "Could not read {$absolute}"];
        }
        $stat = @stat($absolute);

        return [
            'path' => $path,
            'name' => self::stripExtension(basename($path)),
            'extension' => self::extensionOf($path),
            'source' => $sourceTag,             // 'override' or 'original'
            'source_path' => $sourcePath,        // bundle path for originals
            'size' => $stat !== false ? (int) $stat['size'] : \strlen($bytes),
            'last_modified' => $stat !== false ? (int) $stat['mtime'] : null,
            'content' => $bytes,
        ];
    }

    // -----------------------------------------------------------------
    // (re-used) scanning helpers
    // -----------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function scanAllTemplates(): array
    {
        $names = [];

        // Modern Contao 5 templates are identified by group + name
        // ("frontend_module/navigation"), and the ONLY authority on that
        // identifier is Contao's own Twig loader: whether a bundle keeps them
        // in contao/templates/ or contao/templates/twig/ depends on a
        // `.twig-root` marker, so deriving the identifier from the file path
        // would be guesswork. Scanning by filename alone loses the group
        // entirely and reports "navigation" for both a frontend module and a
        // content element.
        try {
            foreach (array_keys($this->contaoLoader->getInheritanceChains()) as $identifier) {
                $names[] = (string) $identifier;
            }
        } catch (\Throwable) {
            // A broken chain must not empty the list; the file scan below still
            // finds the legacy names.
        }

        // Legacy .html5 templates are not part of the Twig chain, so they are
        // found the only way they can be — on disk, by filename.
        foreach ($this->allTemplateDirectories() as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $finder = (new Finder())
                ->files()
                ->in($dir)
                ->name(['*.html.twig', '*.html5']);

            foreach ($finder as $file) {
                $name = self::stripExtension($file->getFilename());
                // A modern template already came from the loader with its
                // group; adding the bare filename here would list it twice
                // under two different identifiers.
                if (\in_array($name, $names, true) || $this->isKnownWithGroup($names, $name)) {
                    continue;
                }
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param list<string> $names
     */
    private function isKnownWithGroup(array $names, string $bareName): bool
    {
        $suffix = '/'.$bareName;

        foreach ($names as $known) {
            if (str_ends_with($known, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function allTemplateDirectories(): array
    {
        return array_merge(
            [
                $this->projectDir.'/templates',
                $this->projectDir.'/contao/templates',
            ],
            $this->bundleTemplateDirectories(),
        );
    }

    /**
     * @return list<string>
     */
    private function bundleTemplateDirectories(): array
    {
        $dirs = [];
        $vendorDir = $this->projectDir.'/vendor';
        if (is_dir($vendorDir)) {
            foreach (glob($vendorDir.'/*/*/contao/templates', \GLOB_ONLYDIR) ?: [] as $bundleTemplateDir) {
                $dirs[] = $bundleTemplateDir;
            }
        }

        return $dirs;
    }

    /**
     * Level 1: Twig syntax check before persisting. Only applies to .html.twig
     * files (.html5 is plain PHP and would need a separate php-lint pass that
     * is out of scope here). Tokenize → parse → if Twig\Error\SyntaxError, we
     * surface line + cursor in the response so the LLM can fix and retry.
     *
     * @return array{error: string, message: string, line: int, source_excerpt: string}|null
     */
    private function lintTwigIfApplicable(string $path, string $content): ?array
    {
        if (!str_ends_with($path, '.html.twig')) {
            return null;
        }

        try {
            $source = new TwigSource($content, $path);
            // Tokenize is the cheapest sanity check; parse() catches deeper
            // grammar errors (mismatched blocks, unknown tags). Run both.
            $stream = $this->twig->tokenize($source);
            $this->twig->parse($stream);
        } catch (SyntaxError $e) {
            $line = (int) $e->getTemplateLine();
            $excerpt = $this->excerptAroundLine($content, $line, 2);
            return [
                'error' => 'twig_syntax_error',
                'message' => $e->getRawMessage(),
                'line' => $line,
                'source_excerpt' => $excerpt,
            ];
        } catch (\Throwable $e) {
            // Any other Twig-internal failure — surface but don't block hard.
            return [
                'error' => 'twig_parse_failed',
                'message' => $e->getMessage(),
                'line' => 0,
                'source_excerpt' => '',
            ];
        }

        return null;
    }

    /**
     * Returns ±$context lines around $line as a numbered excerpt — only used
     * to surface helpful context in the twig_syntax_error response.
     */
    private function excerptAroundLine(string $content, int $line, int $context): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $from = max(0, $line - 1 - $context);
        $to = min(\count($lines) - 1, $line - 1 + $context);
        $out = [];
        for ($i = $from; $i <= $to; ++$i) {
            $marker = ($i + 1) === $line ? '→' : ' ';
            $out[] = sprintf('%s %4d | %s', $marker, $i + 1, $lines[$i]);
        }

        return implode("\n", $out);
    }

    private static function hasAllowedExtension(string $path): bool
    {
        foreach (self::ALLOWED_EXTENSIONS as $ext) {
            if (str_ends_with($path, $ext)) {
                return true;
            }
        }
        return false;
    }

    private static function stripExtension(string $filename): string
    {
        if (str_ends_with($filename, '.html.twig')) {
            return substr($filename, 0, -10);
        }
        if (str_ends_with($filename, '.html5')) {
            return substr($filename, 0, -6);
        }
        return $filename;
    }

    private static function extensionOf(string $filename): string
    {
        if (str_ends_with($filename, '.html.twig')) {
            return 'html.twig';
        }
        if (str_ends_with($filename, '.html5')) {
            return 'html5';
        }
        return pathinfo($filename, \PATHINFO_EXTENSION);
    }

    /**
     * @var list<string>
     */
    private const COMMON_PREFIXES = [
        'ce_', 'mod_', 'fe_', 'mail_', 'form_', 'block_',
        'member_', 'news_', 'faq_', 'calendar_', 'event_',
        'password_', 'error_', 'j_',
    ];
}
