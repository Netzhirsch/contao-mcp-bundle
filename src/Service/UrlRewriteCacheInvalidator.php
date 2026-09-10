<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Rebuilds the compiled Symfony route tables after a write to
 * `tl_url_rewrite`, by calling terminal42/contao-url-rewrite's own routine.
 *
 * Why this is needed. That bundle contributes its rules through a route
 * LOADER, so they end up compiled into `url_matching_routes.php` /
 * `url_generating_routes.php` in the cache directory. It keeps those in step
 * via DCA callbacks:
 *
 *     #[AsCallback('tl_url_rewrite', 'config.onsubmit')]
 *     #[AsCallback('tl_url_rewrite', 'config.ondelete')]   → clearRouterCache()
 *
 * Callbacks fire for a DataContainer save — the backend form. Our tools write
 * the row with DBAL, which is faster and side-effect-free and, precisely
 * because of that, never reaches them. The consequence was reported from a
 * live site: `url_rewrite_create` answered `created: true`, `url_rewrite_get`
 * confirmed the row, and the redirect kept 404ing because the router was still
 * serving a route table compiled before the row existed. Nothing in the answer
 * hinted at it.
 *
 * Why call their listener instead of clearing the files ourselves: their
 * routine also warms the routers back up and resets OPcache, and it is the one
 * that has to stay correct as their bundle changes. A second copy of it here
 * would drift — the same failure this bundle has now removed in three other
 * places. The service is declared `public: true` in their `listener.yml`, so
 * fetching it by id is a supported route, not a trick.
 *
 * The extension is optional. Without it there is no `tl_url_rewrite` and the
 * tools refuse earlier, so the absent-service branch is a safety net rather
 * than a normal path.
 */
final class UrlRewriteCacheInvalidator
{
    /** Public service id from terminal42/contao-url-rewrite's listener.yml. */
    private const LISTENER_SERVICE = 'terminal42_url_rewrite.listener.rewrite_container';

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->container->has(self::LISTENER_SERVICE);
    }

    /**
     * @return array{rebuilt: bool, reason?: string, hint?: string}
     */
    public function rebuild(): array
    {
        if (!$this->isAvailable()) {
            return [
                'rebuilt' => false,
                'reason' => 'listener_unavailable',
                'hint' => sprintf(
                    'The service %s is not registered. The rule is written, but the router still serves the '
                    .'route table it compiled earlier — run `vendor/bin/contao-console cache:clear` to pick it up.',
                    self::LISTENER_SERVICE,
                ),
            ];
        }

        try {
            $listener = $this->container->get(self::LISTENER_SERVICE);

            if (!method_exists($listener, 'onRecordsModified')) {
                return [
                    'rebuilt' => false,
                    'reason' => 'listener_signature_changed',
                    'hint' => 'The extension no longer exposes onRecordsModified(). The rule is written; '
                        .'rebuild the router cache with `vendor/bin/contao-console cache:clear`.',
                ];
            }

            $listener->onRecordsModified();

            return ['rebuilt' => true];
        } catch (\Throwable $e) {
            // A rule that is saved but not routed is worse than a loud failure,
            // so this is reported rather than swallowed.
            $this->logger->warning('Rebuilding the URL-rewrite router cache failed: '.$e->getMessage());

            return [
                'rebuilt' => false,
                'reason' => 'rebuild_failed',
                'hint' => 'The rule is written, but the router cache could not be rebuilt ('.$e->getMessage()
                    .'). Run `vendor/bin/contao-console cache:clear` before relying on the redirect.',
            ];
        }
    }
}
