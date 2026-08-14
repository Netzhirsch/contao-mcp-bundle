<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Page;

use Contao\PageModel;
use Netzhirsch\ContaoMcpBundle\Service\FieldProviderRegistry;

/**
 * Flattens a PageModel into a JSON-friendly array. Output is symmetric with what
 * Page\Tool's create/update accept: every field a user can write, they can also read.
 *
 * Serialised blobs (groups, newsArchives, eventCalendars) → list<int>.
 * chmod → list<string> (permission flags: u1..u6, g1..g6, w1..w6).
 * favicon binary → 32-char hex UUID.
 * start/stop varchar timestamps → ISO 8601 datetime strings.
 */
final class Serializer
{
    public function __construct(
        private readonly FieldProviderRegistry $providers,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(PageModel $p): array
    {
        $core = [
            'id' => (int) $p->id,
            'pid' => (int) $p->pid,
            'sorting' => (int) $p->sorting,

            // Title / type
            'title' => (string) $p->title,
            'type' => (string) $p->type,
            'alias' => (string) $p->alias,

            // Routing
            'requireItem' => (bool) $p->requireItem,
            'routePriority' => (int) $p->routePriority,

            // SEO / meta
            'pageTitle' => (string) $p->pageTitle,
            'language' => (string) $p->language,
            'robots' => (string) $p->robots,
            'description' => (string) $p->description,
            'canonicalLink' => (string) $p->canonicalLink,
            'canonicalKeepParams' => (string) $p->canonicalKeepParams,

            // Redirect / forward
            'redirect' => (string) $p->redirect,
            'jumpTo' => (int) $p->jumpTo,
            'redirectBack' => (bool) $p->redirectBack,
            'alwaysForward' => (bool) $p->alwaysForward,
            'autoforward' => (bool) $p->autoforward,
            'url' => (string) $p->url,
            'target' => (bool) $p->target,

            // Root / DNS
            'dns' => (string) $p->dns,
            'useSSL' => (bool) $p->useSSL,
            'urlPrefix' => (string) $p->urlPrefix,
            'urlSuffix' => (string) $p->urlSuffix,
            'validAliasCharacters' => (string) $p->validAliasCharacters,
            'useFolderUrl' => (bool) $p->useFolderUrl,
            'fallback' => (bool) $p->fallback,
            'disableLanguageRedirect' => (bool) $p->disableLanguageRedirect,
            'staticFiles' => (string) $p->staticFiles,
            'staticPlugins' => (string) $p->staticPlugins,
            'favicon' => $p->favicon ? bin2hex($p->favicon) : null,
            'robotsTxt' => $p->robotsTxt !== null ? (string) $p->robotsTxt : null,
            'maintenanceMode' => (bool) $p->maintenanceMode,
            'mailerTransport' => (string) $p->mailerTransport,
            'enableCanonical' => (bool) $p->enableCanonical,
            'adminEmail' => (string) $p->adminEmail,
            'dateFormat' => (string) $p->dateFormat,
            'timeFormat' => (string) $p->timeFormat,
            'datimFormat' => (string) $p->datimFormat,

            // Protected / groups
            'protected' => (bool) $p->protected,
            'groups' => self::unserializeIntList($p->groups),

            // Layout
            'includeLayout' => (bool) $p->includeLayout,
            'layout' => (int) $p->layout,
            'subpageLayout' => (int) $p->subpageLayout,

            // Cache
            'includeCache' => (bool) $p->includeCache,
            'cache' => (int) $p->cache,
            'clientCache' => (int) $p->clientCache,
            'alwaysLoadFromCache' => (bool) $p->alwaysLoadFromCache,

            // Chmod
            'includeChmod' => (bool) $p->includeChmod,
            'cuser' => (int) $p->cuser,
            'cgroup' => (int) $p->cgroup,
            'chmod' => self::unserializeStringList($p->chmod),

            // Expert
            'cssClass' => (string) $p->cssClass,
            'sitemap' => (string) $p->sitemap,
            'searchIndexer' => (string) $p->searchIndexer,
            'hide' => (bool) $p->hide,
            'guests' => (bool) $p->guests,
            'accesskey' => (string) $p->accesskey,

            // 2-Factor
            'enforceTwoFactor' => (bool) $p->enforceTwoFactor,
            'twoFactorJumpTo' => (int) $p->twoFactorJumpTo,

            // CSP
            'enableCsp' => (bool) $p->enableCsp,
            'csp' => $p->csp !== null ? (string) $p->csp : null,
            'cspReportOnly' => (bool) $p->cspReportOnly,
            'cspReportLog' => (bool) $p->cspReportLog,

            // Publish window
            'published' => (bool) $p->published,
            'start' => $p->start ? date(\DATE_ATOM, (int) $p->start) : null,
            'stop' => $p->stop ? date(\DATE_ATOM, (int) $p->stop) : null,

            // News-feed extension fields (news-bundle)
            'newsArchives' => self::unserializeIntList($p->newsArchives),

            // Calendar-feed extension fields (calendar-bundle)
            'eventCalendars' => self::unserializeIntList($p->eventCalendars),

            // Feed common
            'feedFormat' => (string) $p->feedFormat,
            'feedSource' => (string) $p->feedSource,
            'maxFeedItems' => (int) $p->maxFeedItems,
            'feedFeatured' => (string) $p->feedFeatured,
            'feedDescription' => $p->feedDescription !== null ? (string) $p->feedDescription : null,
            'feedRecurrenceLimit' => (int) $p->feedRecurrenceLimit,
            'imgSize' => (string) $p->imgSize,
        ];

        // Merge available extension providers' output (e.g. terminal42/contao-changelanguage
        // contributes languageMain / languageRoot / languageQuery for tl_page).
        foreach ($this->providers->availableForTable('tl_page') as $provider) {
            $core = array_merge($core, $provider->serialize($p));
        }

        return $core;
    }

    /**
     * @return list<int>
     */
    private static function unserializeIntList(mixed $value): array
    {
        if (!$value) {
            return [];
        }
        $decoded = @unserialize((string) $value, ['allowed_classes' => false]);
        if (!\is_array($decoded)) {
            return [];
        }

        return array_values(array_map('intval', $decoded));
    }

    /**
     * @return list<string>
     */
    private static function unserializeStringList(mixed $value): array
    {
        if (!$value) {
            return [];
        }
        $decoded = @unserialize((string) $value, ['allowed_classes' => false]);
        if (!\is_array($decoded)) {
            return [];
        }

        return array_values(array_map('strval', $decoded));
    }
}
