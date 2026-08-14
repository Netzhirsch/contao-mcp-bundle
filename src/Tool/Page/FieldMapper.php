<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Page;

use Contao\PageModel;
use Netzhirsch\ContaoMcpBundle\Service\FieldProviderRegistry;

/**
 * Field mapper for tl_page. Enforces strict per-type whitelisting: every input field
 * must appear in the palette of the resolved page-type, otherwise apply() throws.
 *
 * Resolved type = $input['type'] if provided, else $page->type (model state).
 *
 * Sub-palette fields are kept in the same whitelist as their owning toggle, so e.g.
 * passing `groups` is accepted even when `protected` is not in the input — assuming
 * the model already has protected=1 or the user is also flipping it in the same call.
 * (Contao's Backend hides sub-fields when the toggle is off; we don't enforce that,
 * because the LLM might legitimately edit groups while protected stays true.)
 */
final class FieldMapper
{
    public function __construct(
        private readonly FieldProviderRegistry $providers,
    ) {
    }

    /**
     * Valid page types. Extension-introduced types must be added here to pass strict
     * validation (news_feed, calendar_feed live in vendor news-bundle / calendar-bundle).
     *
     * @var list<string>
     */
    public const ALLOWED_TYPES = [
        'regular', 'forward', 'redirect', 'root', 'logout',
        'error_401', 'error_403', 'error_404', 'error_503',
        'news_feed', 'calendar_feed',
    ];

    /**
     * Fields that every page type accepts (skeleton + publish window + tree position).
     *
     * @var list<string>
     */
    private const COMMON_FIELDS = [
        'title', 'type', 'pid', 'sorting', 'published', 'start', 'stop',
    ];

    /**
     * Per-type whitelists. Each list defines which fields are valid for that page type,
     * INCLUDING fields hidden behind sub-palette toggles (protected/groups, includeLayout/
     * layout, etc.). Common fields above are merged in by allowedFieldsFor().
     *
     * @var array<string, list<string>>
     */
    private const TYPE_PALETTES = [
        'regular' => [
            'alias', 'requireItem', 'routePriority',
            'pageTitle', 'robots', 'description', 'canonicalLink', 'canonicalKeepParams',
            'protected', 'groups',
            'includeLayout', 'layout', 'subpageLayout',
            'includeCache', 'cache', 'clientCache', 'alwaysLoadFromCache',
            'includeChmod', 'cuser', 'cgroup', 'chmod',
            'cssClass', 'sitemap', 'searchIndexer', 'hide', 'guests', 'accesskey',
        ],
        'forward' => [
            'alias', 'routePriority',
            'pageTitle', 'robots',
            'jumpTo', 'redirect', 'alwaysForward',
            'protected', 'groups',
            'includeLayout', 'layout', 'subpageLayout',
            'includeCache', 'cache', 'clientCache', 'alwaysLoadFromCache',
            'includeChmod', 'cuser', 'cgroup', 'chmod',
            'cssClass', 'sitemap', 'hide', 'guests', 'accesskey',
        ],
        'redirect' => [
            'alias', 'routePriority',
            'pageTitle', 'robots',
            'redirect', 'url', 'target',
            'protected', 'groups',
            'includeLayout', 'layout', 'subpageLayout',
            'includeCache', 'cache', 'clientCache', 'alwaysLoadFromCache',
            'includeChmod', 'cuser', 'cgroup', 'chmod',
            'cssClass', 'sitemap', 'hide', 'guests', 'accesskey',
        ],
        // 'root' covers both the regular root palette and Contao's 'rootfallback' view
        // (the latter is activated by fallback=1 and adds favicon + robotsTxt).
        // We accept all root fields regardless of fallback so the LLM can set them in
        // any order.
        'root' => [
            'alias',
            'pageTitle',
            'dns', 'useSSL', 'urlPrefix', 'urlSuffix', 'validAliasCharacters', 'useFolderUrl',
            'language', 'fallback', 'disableLanguageRedirect',
            'favicon', 'robotsTxt', 'maintenanceMode',
            'enableCsp', 'csp', 'cspReportOnly', 'cspReportLog',
            'mailerTransport', 'enableCanonical', 'adminEmail',
            'dateFormat', 'timeFormat', 'datimFormat',
            'staticFiles', 'staticPlugins',
            'protected', 'groups',
            'includeLayout', 'layout', 'subpageLayout',
            'enforceTwoFactor', 'twoFactorJumpTo',
            'includeCache', 'cache', 'clientCache', 'alwaysLoadFromCache',
            'includeChmod', 'cuser', 'cgroup', 'chmod',
        ],
        'logout' => [
            'alias', 'routePriority',
            'jumpTo', 'redirectBack',
            'protected', 'groups',
            'includeChmod', 'cuser', 'cgroup', 'chmod',
            'cssClass', 'sitemap', 'hide', 'accesskey',
        ],
        'error_401' => self::ERROR_FIELDS,
        'error_403' => self::ERROR_FIELDS,
        'error_404' => self::ERROR_FIELDS,
        'error_503' => self::ERROR_FIELDS,
        'news_feed' => [
            'alias', 'routePriority',
            'newsArchives',
            'feedFormat', 'feedSource', 'maxFeedItems', 'feedFeatured', 'feedDescription',
            'imgSize',
            'includeCache', 'cache', 'clientCache', 'alwaysLoadFromCache',
            'cssClass', 'sitemap', 'hide',
        ],
        'calendar_feed' => [
            'alias', 'routePriority',
            'eventCalendars',
            'feedFormat', 'feedSource', 'maxFeedItems', 'feedFeatured', 'feedDescription',
            'feedRecurrenceLimit',
            'imgSize',
            'includeCache', 'cache', 'clientCache', 'alwaysLoadFromCache',
            'cssClass', 'sitemap', 'hide',
        ],
    ];

    /**
     * Common error-page (401/403/404/503) palette.
     *
     * @var list<string>
     */
    private const ERROR_FIELDS = [
        'pageTitle', 'robots', 'description',
        'autoforward', 'jumpTo',
        'protected', 'groups',
        'includeLayout', 'layout', 'subpageLayout',
        'includeCache', 'cache', 'clientCache', 'alwaysLoadFromCache',
        'includeChmod', 'cuser', 'cgroup', 'chmod',
        'cssClass',
    ];

    /**
     * @var list<string>
     */
    private const STRING_FIELDS = [
        'title', 'type', 'alias', 'pageTitle', 'language', 'robots', 'description',
        'redirect', 'url', 'dns', 'staticFiles', 'staticPlugins', 'robotsTxt',
        'mailerTransport', 'canonicalLink', 'canonicalKeepParams', 'adminEmail',
        'dateFormat', 'timeFormat', 'datimFormat', 'validAliasCharacters',
        'urlPrefix', 'urlSuffix', 'csp', 'cssClass', 'sitemap', 'searchIndexer',
        'accesskey', 'imgSize', 'feedFormat', 'feedSource', 'feedFeatured',
        'feedDescription',
    ];

    /**
     * @var list<string>
     */
    private const BOOL_FIELDS = [
        'requireItem', 'redirectBack', 'target', 'fallback', 'disableLanguageRedirect',
        'maintenanceMode', 'enableCanonical', 'useFolderUrl', 'useSSL', 'autoforward',
        'protected', 'includeLayout', 'includeCache', 'alwaysLoadFromCache',
        'includeChmod', 'hide', 'guests', 'published', 'enforceTwoFactor', 'enableCsp',
        'cspReportOnly', 'cspReportLog', 'alwaysForward',
    ];

    /**
     * @var list<string>
     */
    private const INT_FIELDS = [
        'pid', 'sorting', 'routePriority', 'jumpTo', 'layout', 'subpageLayout',
        'cache', 'clientCache', 'cuser', 'cgroup', 'twoFactorJumpTo',
        'maxFeedItems', 'feedRecurrenceLimit',
    ];

    /**
     * @param array<string, mixed> $input
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    public function apply(PageModel $page, array $input, bool $detectChanges = true): array
    {
        $resolvedType = $this->resolveType($page, $input);

        if (!\in_array($resolvedType, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown page type "%s". Allowed: %s.',
                $resolvedType,
                implode(', ', self::ALLOWED_TYPES),
            ));
        }

        $coreAllowed = $this->allowedFieldsFor($resolvedType);
        $providers = $this->providers->forTable('tl_page');

        foreach (array_keys($input) as $field) {
            if (\in_array($field, $coreAllowed, true)) {
                continue;
            }

            // Look for a provider that claims this field
            $claimedBy = null;
            foreach ($providers as $provider) {
                if (\in_array($field, $provider->getDeclaredFields(), true)) {
                    $claimedBy = $provider;
                    break;
                }
            }

            if ($claimedBy === null) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" is not valid for page type "%s". Allowed: %s.',
                    $field,
                    $resolvedType,
                    implode(', ', $coreAllowed),
                ));
            }

            if (!$claimedBy->isAvailable()) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" requires the %s extension, which is not installed in this Contao project.',
                    $field,
                    $claimedBy->getRequiredExtension(),
                ));
            }

            $providerAllowed = $claimedBy->getAllowedFields($resolvedType);
            if (!\in_array($field, $providerAllowed, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" is provided by %s but is not valid for page type "%s".',
                    $field,
                    $claimedBy->getRequiredExtension(),
                    $resolvedType,
                ));
            }
        }

        $changed = [];

        $touch = static function (string $field) use (&$changed): void {
            if (!\in_array($field, $changed, true)) {
                $changed[] = $field;
            }
        };

        foreach (self::STRING_FIELDS as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $new = (string) $input[$field];
            if (!$detectChanges || (string) $page->$field !== $new) {
                $page->$field = $new;
                $touch($field);
            }
        }

        foreach (self::BOOL_FIELDS as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $new = $input[$field] ? 1 : 0;
            if (!$detectChanges || (int) $page->$field !== $new) {
                $page->$field = $new;
                $touch($field);
            }
        }

        foreach (self::INT_FIELDS as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $new = (int) $input[$field];
            if (!$detectChanges || (int) $page->$field !== $new) {
                $page->$field = $new;
                $touch($field);
            }
        }

        // start, stop (varchar(10), empty or unix timestamp string)
        foreach (['start', 'stop'] as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $raw = (string) $input[$field];
            $new = $raw === '' ? '' : (string) self::parseDateTime($raw);
            if (!$detectChanges || (string) $page->$field !== $new) {
                $page->$field = $new;
                $touch($field);
            }
        }

        // favicon: binary(16) UUID — accept hex or hyphenated UUID, clear with ''.
        if (\array_key_exists('favicon', $input) && $input['favicon'] !== null) {
            $raw = (string) $input['favicon'];
            if ($raw === '') {
                $bin = null;
            } else {
                $hex = str_replace('-', '', $raw);
                if (\strlen($hex) !== 32 || preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1) {
                    throw new \InvalidArgumentException(sprintf(
                        'favicon must be a 32-char hex UUID or a UUID with dashes (got "%s").',
                        $raw,
                    ));
                }
                $bin = hex2bin($hex);
            }
            if (!$detectChanges || $page->favicon !== $bin) {
                $page->favicon = $bin;
                $touch('favicon');
            }
        }

        // groups / newsArchives / eventCalendars: serialized list<int>
        foreach (['groups', 'newsArchives', 'eventCalendars'] as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $raw = $input[$field];
            if (!\is_array($raw)) {
                throw new \InvalidArgumentException(sprintf(
                    '"%s" must be an array of integer ids.',
                    $field,
                ));
            }
            $cleaned = array_values(array_map('intval', $raw));
            $new = $cleaned === [] ? '' : serialize($cleaned);
            if (!$detectChanges || (string) $page->$field !== $new) {
                $page->$field = $new;
                $touch($field);
            }
        }

        // chmod: serialized list<string> of permission flags (u1..u6, g1..g6, w1..w6)
        if (\array_key_exists('chmod', $input) && $input['chmod'] !== null) {
            $raw = $input['chmod'];
            if (!\is_array($raw)) {
                throw new \InvalidArgumentException('chmod must be a list of permission flag strings (u1..u6, g1..g6, w1..w6).');
            }
            $cleaned = array_values(array_map('strval', $raw));
            foreach ($cleaned as $flag) {
                if (preg_match('/^[ugw][1-6]$/', $flag) !== 1) {
                    throw new \InvalidArgumentException(sprintf('Invalid chmod flag "%s" (expected u1..u6, g1..g6, w1..w6).', $flag));
                }
            }
            $new = $cleaned === [] ? '' : serialize($cleaned);
            if (!$detectChanges || (string) $page->chmod !== $new) {
                $page->chmod = $new;
                $touch('chmod');
            }
        }

        // Hand over to extension providers (only available ones; non-available would
        // have already failed the input validation above).
        foreach ($providers as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }
            foreach ($provider->apply($page, $input, $detectChanges) as $field) {
                $touch($field);
            }
        }

        return $changed;
    }

    /**
     * Returns the merged whitelist (common + per-type fields) for a given page type.
     *
     * @return list<string>
     */
    public function allowedFieldsFor(string $type): array
    {
        $palette = self::TYPE_PALETTES[$type] ?? [];

        return array_values(array_unique(array_merge(self::COMMON_FIELDS, $palette)));
    }

    /**
     * Picks the type to validate against: input wins (creating or changing type),
     * otherwise the model's current type, fallback 'regular'.
     *
     * @param array<string, mixed> $input
     */
    private function resolveType(PageModel $page, array $input): string
    {
        if (\array_key_exists('type', $input) && $input['type'] !== null && $input['type'] !== '') {
            return (string) $input['type'];
        }
        $current = (string) $page->type;

        return $current !== '' ? $current : 'regular';
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function parseDateTime(string $iso): int
    {
        $ts = strtotime($iso);
        if ($ts === false) {
            throw new \InvalidArgumentException(sprintf('Invalid date/datetime "%s". Use ISO 8601.', $iso));
        }

        return $ts;
    }
}
