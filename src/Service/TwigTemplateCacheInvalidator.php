<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Contao\CoreBundle\Twig\Loader\ContaoFilesystemLoader;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Makes a written Twig template actually take effect.
 *
 * Two caches sit between a file on disk and what a visitor sees, and writing
 * the file bypasses both:
 *
 *  1. **The template hierarchy.** `ContaoFilesystemLoader` builds the
 *     inheritance chains once and persists them in a PSR-6 pool
 *     (`ensureHierarchyIsBuilt()` → `$cachePool->save()`). A template the
 *     hierarchy has never seen does not exist as far as the loader is
 *     concerned — `template_lookup` answers not_found and the base template
 *     keeps rendering.
 *  2. **Twig's compiled classes.** `ContaoFilesystemLoader::getCacheKey()`
 *     returns `'c:'.$path` — the path, not a hash of the contents. So a
 *     CHANGED file keeps its cache key, and Twig only recompiles when it
 *     consults `isFresh()`, which it does only with `auto_reload` on.
 *     `auto_reload` follows debug, so in prod the old compiled class stays.
 *
 * That split is exactly what was reported: a NEW template was invisible (1),
 * and an EDITED one kept rendering its previous markup (2). It is also why the
 * problem never shows in dev — Contao registers
 * `AutoRefreshTemplateHierarchyListener`, which calls `warmUp(true)` on every
 * main request, and auto_reload recompiles. Nothing of that runs in prod.
 *
 * `warmUp(true)` is Contao's own routine and re-persists the hierarchy, so the
 * effect outlives the request. For the compiled classes there is no per-template
 * invalidation in Twig's public API, so the compiled directory is emptied —
 * regenerable by definition, recompiled lazily on next use, the same bargain
 * {@see \Netzhirsch\ContaoMcpBundle\Tool\Maintenance\Tool} makes for Contao's
 * own caches.
 */
final class TwigTemplateCacheInvalidator
{
    public function __construct(
        private readonly ContaoFilesystemLoader $loader,
        private readonly LoggerInterface $logger,
        private readonly string $cacheDir,
    ) {
    }

    /**
     * @return array{rebuilt: bool, hierarchy: bool, compiled_removed: int, hint?: string}
     */
    public function rebuild(): array
    {
        $hierarchy = false;
        $problems = [];

        try {
            // forceRefresh: drop what is held and re-read the filesystem, then
            // persist. Without the flag it would happily confirm the cached
            // hierarchy that is missing the new file.
            $this->loader->warmUp(true);
            $hierarchy = true;
        } catch (\Throwable $e) {
            $problems[] = 'the template hierarchy could not be rebuilt ('.$e->getMessage().')';
            $this->logger->warning('Rebuilding the Twig template hierarchy failed: '.$e->getMessage());
        }

        [$removed, $compiledProblem] = $this->clearCompiled();

        if ($compiledProblem !== null) {
            $problems[] = $compiledProblem;
        }

        $result = [
            'rebuilt' => $problems === [],
            'hierarchy' => $hierarchy,
            'compiled_removed' => $removed,
        ];

        if ($problems !== []) {
            // Reporting a write as done while the site still renders the old
            // template is the expensive failure here — it looks like success
            // from every angle except the browser.
            $result['hint'] = 'The file is written, but '.implode(' and ', $problems)
                .'. Run `vendor/bin/contao-console cache:clear` before relying on the template.';
        }

        return $result;
    }

    /**
     * Empty Twig's compiled-class directory.
     *
     * @return array{0: int, 1: string|null} files removed, problem
     */
    private function clearCompiled(): array
    {
        $dir = realpath($this->cacheDir.\DIRECTORY_SEPARATOR.'twig');

        if ($dir === false) {
            return [0, null]; // nothing compiled yet — nothing stale either
        }

        // Never delete outside the cache directory, whatever a symlink says.
        $base = realpath($this->cacheDir);

        if ($base === false || !str_starts_with($dir, $base)) {
            return [0, 'the Twig cache directory is not inside the cache dir and was left alone'];
        }

        $filesystem = new Filesystem();
        $removed = 0;
        $failed = 0;

        foreach (Finder::create()->files()->in($dir)->name('*.php') as $file) {
            try {
                $filesystem->remove($file->getPathname());
                ++$removed;
            } catch (\Throwable) {
                ++$failed;
            }
        }

        return [
            $removed,
            $failed > 0 ? sprintf('%d compiled template(s) could not be removed', $failed) : null,
        ];
    }
}
