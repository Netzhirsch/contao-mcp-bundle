<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\License;

/**
 * Version strings as they travel to and from the license server — one rule,
 * applied in both directions.
 *
 * Outgoing (`bundle_version`, `contao_version`, `php_version` on /trial and
 * /renew) and incoming (`latest_version` in the response) are the same kind of
 * value, so they get the same treatment: trimmed, at most 32 characters, only
 * `[A-Za-z0-9._+-]`. The server discards anything else; a second, slightly
 * different copy of that rule here would eventually drift from it.
 */
final class VersionString
{
    /** The server's own limit. Longer values are dropped there, so drop them here. */
    private const MAX_LENGTH = 32;

    /**
     * Returns the value if it is a version string the server would accept, and
     * '' otherwise. Never throws, never partially "repairs" a value beyond
     * trimming — a half-fixed version number is worse than none.
     */
    public static function sanitise(string $value): string
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH) {
            return '';
        }

        return preg_match('/^[A-Za-z0-9._+-]+$/', $value) === 1 ? $value : '';
    }

    /**
     * Whether this is a development installation rather than a released
     * version: `dev-master`, `dev-feature/x`, `5.7.x-dev`, plain `dev`, or
     * Composer's `1.0.0+no-version-set` for a checkout with no tag.
     *
     * version_compare() answers for these too, but the answer is meaningless —
     * it would tell a developer running the branch that they are behind the
     * release they are working on. No comparison is better than a wrong one.
     */
    public static function isDev(string $value): bool
    {
        $value = strtolower(trim($value));

        return $value === 'dev'
            || str_starts_with($value, 'dev-')
            || str_ends_with($value, '-dev')
            || str_contains($value, 'no-version-set');
    }

    /**
     * True when $candidate is a later release than $installed.
     *
     * version_compare() rather than a string comparison, because `1.0.9` sorts
     * ABOVE `1.0.10` as a string — the one bug this function exists to avoid.
     * The leading `v` goes because Composer reports a tag as `1.0.10` or
     * `v1.0.10` depending on how it was tagged, and the two sides can disagree.
     */
    public static function isNewer(string $candidate, string $installed): bool
    {
        return version_compare(self::normalise($candidate), self::normalise($installed), '>');
    }

    private static function normalise(string $value): string
    {
        return ltrim(trim($value), 'vV');
    }
}
