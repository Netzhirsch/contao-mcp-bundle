<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\License;

/**
 * "A newer version is available" — the decision, separated from where it is
 * displayed so it can be tested without a backend.
 *
 * The license server announces a release in its /trial and /renew response
 * (`latest_version`, `release_notes_url`, `security_release`); the values are
 * maintained by hand at Netzhirsch, so a tagged version is NOT automatically an
 * announced one. {@see RenewalClient} stores whatever came back, this class
 * decides whether it is worth showing.
 *
 * Deliberately inert: it informs and nothing else. It never triggers an update,
 * never blocks anything, and has no say in whether the license is valid — a
 * missing, empty or nonsensical announcement simply produces no notice.
 */
final class UpdateNotice
{
    /**
     * Long enough for a release URL, short enough that a runaway value cannot
     * be pasted into the backend markup.
     */
    private const MAX_URL_LENGTH = 300;

    private function __construct(
        public readonly string $latestVersion,
        public readonly bool $securityRelease,
        public readonly string $releaseNotesUrl,
    ) {
    }

    /**
     * Returns a notice, or null when there is nothing to say — which is the
     * normal state.
     *
     * @param string $latest    announced version ('' when nothing is announced)
     * @param string $installed the version actually running here
     */
    public static function evaluate(string $latest, string $installed, bool $securityRelease, string $releaseNotesUrl): ?self
    {
        $latest = VersionString::sanitise($latest);

        // Nothing announced, or something that is not a version number. Also
        // covers an announced dev branch, which nobody should be told to
        // "update" to.
        if ($latest === '' || VersionString::isDev($latest)) {
            return null;
        }

        // On a development installation the comparison has no meaning — see
        // VersionString::isDev(). Say nothing rather than something wrong.
        if ($installed === '' || VersionString::isDev($installed)) {
            return null;
        }

        if (!VersionString::isNewer($latest, $installed)) {
            return null;
        }

        return new self($latest, $securityRelease, self::safeUrl($releaseNotesUrl));
    }

    /**
     * Returns the URL if it is a plain https:// URL, '' otherwise — the notice
     * is then shown without a link.
     *
     * It comes from our own server, so this is not a defence against an
     * adversary; it is the habit of not putting an unchecked URL into markup.
     * The host is deliberately NOT pinned to github.com: release notes may
     * reasonably move to a documentation domain, and pinning would silently
     * drop the link the day that happens.
     */
    public static function safeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '' || \strlen($url) > self::MAX_URL_LENGTH || !str_starts_with($url, 'https://')) {
            return '';
        }

        return filter_var($url, FILTER_VALIDATE_URL) === false ? '' : $url;
    }
}
