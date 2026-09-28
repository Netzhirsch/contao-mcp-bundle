<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\File;

/**
 * Holds a partially transferred upload between calls.
 *
 * `file_upload` can take bytes inline, but only small ones: the MCP transport
 * truncates a large base64 string, and the tool says so in its own description.
 * The way out so far was `source_url` — the server pulls the file itself. That
 * works when the file is already on a public host and not at all when it is
 * not, which is the normal case for something a client just produced.
 *
 * So the bytes arrive in pieces and wait here until they are whole.
 *
 * Everything lives under `var/mcp/uploads/<id>/`, next to the other things this
 * bundle keeps to itself: the directory is 0700, both files 0600, and neither is
 * reachable from the web root. A session carries the id of the user who opened
 * it, and only that user may add to it or finish it — two connectors uploading
 * at the same time must not be able to finish each other's file.
 *
 * Nothing here validates the upload. Extension, size, magic bytes and the
 * overwrite decision are the {@see UploadValidator}'s business and run on the
 * ASSEMBLED file in {@see Tool::uploadFinish()} — checking a chunk would prove
 * nothing about the file it ends up in.
 */
final class UploadSessions
{
    /** An id is used to build a path, so it may only ever be hex. */
    private const ID_PATTERN = '/^[0-9a-f]{32}$/';

    /** Long enough for a slow client, short enough that litter does not pile up. */
    public const TTL_SECONDS = 3600;

    /**
     * What we suggest a client sends per call. Inline base64 becomes unreliable
     * well before this in one-shot uploads, but a chunk travels in its own
     * request, so the ceiling is the transport's per-message limit rather than
     * the whole file.
     */
    public const CHUNK_SIZE_RECOMMENDED = 262144;

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return string the new session id
     */
    public function create(array $manifest): string
    {
        $id = bin2hex(random_bytes(16));
        $dir = $this->dir($id);

        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create upload directory %s', $dir));
        }

        @chmod($dir, 0o700);

        $manifest['id'] = $id;
        $manifest['received_bytes'] = 0;
        $manifest['next_sequence'] = 0;
        $manifest['created_at'] = time();
        $manifest['expires_at'] = time() + self::TTL_SECONDS;

        // Create the data file now, so a chunk never has to decide whether it
        // is the first one.
        $this->writeFile($this->dataPath($id), '');
        $this->save($id, $manifest);

        return $id;
    }

    /**
     * @return array<string, mixed>|null null when unknown, malformed or expired
     */
    public function load(string $id): ?array
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return null;
        }

        $raw = @file_get_contents($this->manifestPath($id));

        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        $manifest = json_decode($raw, true);

        if (!\is_array($manifest)) {
            return null;
        }

        if ((int) ($manifest['expires_at'] ?? 0) < time()) {
            $this->discard($id);

            return null;
        }

        /** @var array<string, mixed> $manifest */
        return $manifest;
    }

    /**
     * Appends one chunk and returns the updated manifest.
     *
     * @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>
     */
    public function append(array $manifest, string $bytes): array
    {
        $id = (string) $manifest['id'];

        if (false === @file_put_contents($this->dataPath($id), $bytes, FILE_APPEND)) {
            throw new \RuntimeException('Could not append to the upload buffer.');
        }

        $manifest['received_bytes'] = (int) $manifest['received_bytes'] + \strlen($bytes);
        ++$manifest['next_sequence'];

        $this->save($id, $manifest);

        return $manifest;
    }

    public function bytes(string $id): string
    {
        $raw = @file_get_contents($this->dataPath($id));

        return \is_string($raw) ? $raw : '';
    }

    public function discard(string $id): void
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return;
        }

        @unlink($this->manifestPath($id));
        @unlink($this->dataPath($id));
        @rmdir($this->dir($id));
    }

    /**
     * Removes sessions nobody finished. Called when a new one starts, so an
     * abandoned transfer cannot sit in `var/` forever and there is no cron to
     * forget to set up.
     *
     * @return int how many were removed
     */
    public function sweep(): int
    {
        $root = $this->root();

        if (!is_dir($root)) {
            return 0;
        }

        $removed = 0;

        foreach ((array) @scandir($root) as $entry) {
            if (!\is_string($entry) || preg_match(self::ID_PATTERN, $entry) !== 1) {
                continue;
            }

            $raw = @file_get_contents($this->manifestPath($entry));
            $manifest = \is_string($raw) ? json_decode($raw, true) : null;
            $expires = \is_array($manifest) ? (int) ($manifest['expires_at'] ?? 0) : 0;

            // A directory without a readable manifest is litter too — it can
            // only come from a crash between mkdir and the first save.
            if ($expires < time()) {
                $this->discard($entry);
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function save(string $id, array $manifest): void
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->writeFile($this->manifestPath($id), \is_string($json) ? $json : '{}');
    }

    private function writeFile(string $path, string $contents): void
    {
        if (false === @file_put_contents($path, $contents)) {
            throw new \RuntimeException(sprintf('Could not write %s', $path));
        }

        @chmod($path, 0o600);
    }

    private function root(): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'mcp'.\DIRECTORY_SEPARATOR.'uploads';
    }

    private function dir(string $id): string
    {
        return $this->root().\DIRECTORY_SEPARATOR.$id;
    }

    private function manifestPath(string $id): string
    {
        return $this->dir($id).\DIRECTORY_SEPARATOR.'manifest.json';
    }

    private function dataPath(string $id): string
    {
        return $this->dir($id).\DIRECTORY_SEPARATOR.'data';
    }
}
