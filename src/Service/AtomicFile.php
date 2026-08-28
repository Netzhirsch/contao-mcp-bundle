<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

/**
 * Writes a file so that readers never see a half-written one — and without
 * asking the kernel for a lock.
 *
 * The bundle used to write its small state files with
 * `file_put_contents($path, $data, LOCK_EX)`. That looks harmless and is the
 * usual advice, but `LOCK_EX` makes PHP call `flock()`, and `flock()` is only
 * as reliable as the filesystem underneath it. On an NFSv3 mount without a
 * working lock manager — `local_lock=none`, no `lockd`/`statd` — the call does
 * not fail and does not succeed: it blocks forever. PHP-FPM then sits on the
 * request until the reverse proxy gives up, and the client sees a 504.
 *
 * That is not hypothetical. An akquinet installation kept `var/mcp/` on such a
 * mount (symlinked, so licence data survives deployments) and the very first
 * call to "MCP-Server → Status" after installing hung until the WAF timeout.
 * The giveaway it leaves behind is a zero-byte `license.json` whose ctime is
 * newer than its mtime: a write that started and never finished.
 *
 * None of these files need a lock. They are bookkeeping, one writer at a time,
 * and "last write wins" was always the accepted semantics. What they do need is
 * that a reader never catches a partial file — and `rename()` gives exactly
 * that, atomically, on POSIX and on NFS alike, with no blocking syscall left
 * that could hang. Write to a sibling temp file, then rename over the target.
 *
 * The temp file is created in the SAME directory on purpose. `rename()` is only
 * atomic within one filesystem; going through the system temp directory would
 * silently degrade to copy-then-delete and reintroduce the torn-read window.
 */
final class AtomicFile
{
    /**
     * @param int|null $mode chmod to apply, e.g. 0o600 for a private key;
     *                       null leaves it to the umask
     *
     * @return bool false if the directory, the temp write or the rename failed
     */
    public static function write(string $path, string $contents, ?int $mode = null): bool
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return false;
        }

        $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));

        if (false === @file_put_contents($tmp, $contents)) {
            return false;
        }

        // Set the mode BEFORE the rename. The destination inherits the temp
        // file's permissions, so chmod-ing afterwards would leave a window in
        // which a freshly written private key is world-readable.
        if ($mode !== null) {
            @chmod($tmp, $mode);
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }
}
