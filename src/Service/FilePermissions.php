<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

/**
 * Whether a file that is supposed to be private actually is.
 *
 * `chmod()` is best-effort and silent about it. It is a no-op on Windows, it
 * does nothing useful on many mounted filesystems (CIFS, some FUSE mounts,
 * containers with a mapped volume), and it fails outright when the web-server
 * user does not own the file. Every one of those cases leaves a key or a
 * licence secret world-readable while the code that wrote it believes the
 * opposite — the mode was requested, never verified.
 *
 * league/oauth2-server used to check this for its own keys and that check was
 * disabled here, for the good reason that it refused to start on hosts where
 * chmod cannot work. This is the other half of that trade: do not refuse, but
 * do not pretend either — look, and say so.
 */
final class FilePermissions
{
    /**
     * Describes how a file is too open, or null when it is fine — or when the
     * question cannot be answered here.
     *
     * Deliberately silent on Windows: NTFS permissions are real, but they are
     * not what `fileperms()` reports, so a check there would warn about every
     * file on every install and teach the operator to ignore the warning.
     *
     * @param int $maxMode the widest acceptable mode, e.g. 0o600 for a key
     */
    public static function tooOpen(string $path, int $maxMode = 0o600): ?string
    {
        if (\DIRECTORY_SEPARATOR === '\\' || !is_file($path)) {
            return null;
        }

        clearstatcache(true, $path);
        $perms = @fileperms($path);
        if ($perms === false) {
            return null;
        }

        $actual = $perms & 0o777;
        $excess = $actual & ~$maxMode & 0o777;
        if ($excess === 0) {
            return null;
        }

        return \sprintf(
            '%s is mode %04o, wider than the intended %04o%s. chmod is best-effort — it is a no-op on Windows and on some mounts, and it fails when the web-server user does not own the file. Fix: chmod %04o %s',
            $path,
            $actual,
            $maxMode,
            ($actual & 0o004) !== 0 ? ' and readable by every account on this host' : '',
            $maxMode,
            $path,
        );
    }
}
