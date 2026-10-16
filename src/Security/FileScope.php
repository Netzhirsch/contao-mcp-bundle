<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Security;

/**
 * The part of the upload tree a restricted backend user may see.
 *
 * {@see McpPermissionGuard::ensureFileAccess()} answers "may this call happen",
 * which only works for a path the call names. The two reading tools name no
 * path at all in their common form — `files_list` defaults to the root and
 * `files_search` walks the whole tree — so for those the question is not
 * whether the call runs but WHAT it is allowed to return. That is this class.
 *
 * Two different questions, and conflating them is how the gap arose:
 *
 *   {@see allows()}     — the entry itself is inside a mount. This decides what
 *                         ends up in a result.
 *   {@see mayDescend()} — the folder is inside a mount OR on the way down to
 *                         one. A user mounted on `files/kunde/a` has to be able
 *                         to open `files/kunde` to get there; the backend file
 *                         tree shows exactly that path and nothing beside it.
 *                         This decides what is listed as a folder and which
 *                         directories a search walks into.
 *
 * Comparison is case-insensitive, matching Contao's own filemount voter
 * ({@see \Contao\CoreBundle\Security\Voter\BackendAccessVoter}), which uses an
 * `/i` pattern — on Windows and macOS the filesystem agrees with the voter and
 * not with a byte comparison.
 */
final class FileScope
{
    /** @var list<string> lower-cased mount paths, DBAFS-shaped (`files/…`) */
    private readonly array $mounts;

    /**
     * @param list<string> $mounts DBAFS paths as Contao resolved them onto the
     *                             BackendUser, e.g. `['files/kundeA']`. An
     *                             empty list means the user has no mount and
     *                             therefore sees nothing — NOT everything.
     */
    public function __construct(array $mounts)
    {
        $normalised = [];
        foreach ($mounts as $mount) {
            $mount = trim(str_replace('\\', '/', $mount), '/');
            if ($mount !== '') {
                $normalised[] = mb_strtolower($mount);
            }
        }

        $this->mounts = array_values(array_unique($normalised));
    }

    /** The caller may see this entry itself: it is a mount, or lies inside one. */
    public function allows(string $dbafsPath): bool
    {
        $path = $this->normalise($dbafsPath);
        if ($path === '') {
            return false; // the upload root is only ever reachable via a mount
        }

        foreach ($this->mounts as $mount) {
            if ($path === $mount || str_starts_with($path, $mount.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The caller may open this folder — it is allowed, or an ancestor of a
     * mount and therefore the way to it.
     */
    public function mayDescend(string $dbafsPath): bool
    {
        if ($this->allows($dbafsPath)) {
            return true;
        }

        $path = $this->normalise($dbafsPath);
        if ($path === '') {
            return $this->mounts !== []; // the root leads somewhere iff anything is mounted
        }

        foreach ($this->mounts as $mount) {
            if (str_starts_with($mount, $path.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the mounts, lower-cased — for messages and tests */
    public function mounts(): array
    {
        return $this->mounts;
    }

    private function normalise(string $dbafsPath): string
    {
        return mb_strtolower(trim(str_replace('\\', '/', $dbafsPath), '/'));
    }
}
