<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\File;

/**
 * Holds a partially transferred upload between calls.
 *
 * `file_upload` can take bytes inline, but only small ones: a long base64
 * string in a tool argument is where uploads break, and the tool says so in its
 * own description. The way out so far was `source_url` — the server pulls the
 * file itself. That works when the file is already on a public host and not at
 * all when it is not, which is the normal case for something a client just
 * produced.
 *
 * So the bytes arrive in pieces and wait here until they are whole — either as
 * base64 slices through `file_upload_chunk`, or as raw bytes PUT straight to
 * the session ({@see \Netzhirsch\ContaoMcpBundle\Controller\McpUploadController}),
 * which keeps them out of the model's context altogether.
 *
 * Everything lives under `var/mcp/uploads/<id>/`, next to the other things this
 * bundle keeps to itself: the directory is 0700, both files 0600, and neither is
 * reachable from the web root. A session carries the id of the user who opened
 * it, and only that user may finish it — two connectors uploading at the same
 * time must not be able to finish each other's file.
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

    /**
     * Idle time after which an unfinished upload is thrown away. Measured from
     * the LAST chunk, not from the start: a file sent in many small slices by a
     * model that needs half a minute per call would otherwise expire while it
     * is still arriving.
     */
    public const TTL_SECONDS = 3600;

    /**
     * What we suggest a client sends per `file_upload_chunk` call, in RAW
     * bytes (the base64 is a third larger).
     *
     * This used to be 256 KB, which contradicted the tool's own warning: inline
     * base64 breaks somewhere above 50 KB, and a chunk IS an inline base64
     * string — sending it in a call of its own does not change how long the
     * argument is. 32 KB is 43 KB of base64, comfortably below the point where
     * uploads were seen to break.
     */
    public const CHUNK_SIZE_RECOMMENDED = 32768;

    /**
     * Open uploads one user may have at a time. Each one can hold up to
     * maxFileSize in var/, so without a ceiling a loop that keeps calling
     * `begin` fills the disk before the first one expires.
     */
    public const MAX_OPEN_PER_USER = 10;

    /** One-shot buffers; never matches {@see ID_PATTERN}, so never taken for a session. */
    private const TEMP_PREFIX = 'tmp-';

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
     * Mints the secret that lets raw bytes be PUT to this session directly.
     *
     * Only its hash is kept, so the manifest on disk is not a key to the
     * session. The token is handed to the caller exactly once, in the answer to
     * `file_upload_begin`, which only an authenticated MCP caller receives.
     */
    public function issueDirectToken(string $id): string
    {
        $token = bin2hex(random_bytes(32));

        $this->locked($id, function (array $manifest) use ($id, $token): array {
            $manifest['direct_token_sha256'] = hash('sha256', $token);
            $this->save($id, $manifest);

            return $manifest;
        });

        return $token;
    }

    /**
     * Whether `$token` is the one minted for this session. Constant-time, and
     * false for a session that never had one.
     *
     * @param array<string, mixed> $manifest
     */
    public static function tokenMatches(array $manifest, string $token): bool
    {
        $expected = $manifest['direct_token_sha256'] ?? null;

        return \is_string($expected) && $token !== '' && hash_equals($expected, hash('sha256', $token));
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
     * Runs `$change` on the current manifest while holding the session's lock,
     * and returns what it returned.
     *
     * Without the lock, two calls for the same session — a model issuing tool
     * calls in parallel, or a retry racing the original — both read the same
     * `received_bytes`, both pass the sequence check, and both append: the file
     * gets the slice twice and nothing notices until the sizes stop adding up.
     *
     * @template T
     *
     * @param callable(array<string, mixed>): T $change receives the manifest as
     *                                                 it is under the lock
     *
     * @return T|null null when the session is unknown or expired
     */
    public function locked(string $id, callable $change): mixed
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1 || !is_dir($this->dir($id))) {
            return null;
        }

        $handle = @fopen($this->dir($id).\DIRECTORY_SEPARATOR.'lock', 'c');

        if ($handle === false) {
            throw new \RuntimeException('Could not open the upload lock.');
        }

        try {
            flock($handle, LOCK_EX);

            $manifest = $this->load($id);

            return $manifest === null ? null : $change($manifest);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Appends one chunk and returns the updated manifest. Call it inside
     * {@see locked()}.
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

        return $this->advance($manifest, \strlen($bytes));
    }

    /**
     * Copies a stream onto the end of the buffer, never past `$limit` bytes in
     * total. Call it inside {@see locked()}.
     *
     * The stream is read in blocks, so the size of the upload never decides how
     * much memory the request needs. Anything beyond `$limit` is not written:
     * the buffer is cut back to where it was and null comes back, so a body
     * that is too long leaves the session exactly as it found it.
     *
     * @param resource              $stream
     * @param array<string, mixed>  $manifest
     *
     * @return array<string, mixed>|null the updated manifest, or null when the stream held more than `$limit`
     */
    public function appendStream(array $manifest, $stream, int $limit): ?array
    {
        $id = (string) $manifest['id'];
        $start = (int) $manifest['received_bytes'];
        $out = @fopen($this->dataPath($id), 'ab');

        if ($out === false) {
            throw new \RuntimeException('Could not open the upload buffer.');
        }

        $written = 0;

        try {
            while (!feof($stream)) {
                $block = fread($stream, 65536);

                if ($block === false) {
                    throw new \RuntimeException('Could not read the request body.');
                }

                if ($block === '') {
                    continue;
                }

                if ($start + $written + \strlen($block) > $limit) {
                    ftruncate($out, $start);

                    return null;
                }

                if (fwrite($out, $block) !== \strlen($block)) {
                    ftruncate($out, $start);

                    throw new \RuntimeException('Could not write to the upload buffer.');
                }

                $written += \strlen($block);
            }
        } finally {
            fclose($out);
        }

        return $this->advance($manifest, $written);
    }

    /**
     * A fresh, empty file to buffer a one-shot upload in — inline bytes or a
     * URL pull — on its way to {@see Tool::storeFile()}. The caller deletes it;
     * {@see sweep()} removes any a crash left behind.
     */
    public function tempFile(): string
    {
        $root = $this->root();

        if (!is_dir($root) && !mkdir($root, 0o700, true) && !is_dir($root)) {
            throw new \RuntimeException(sprintf('Could not create upload directory %s', $root));
        }

        $path = $root.\DIRECTORY_SEPARATOR.self::TEMP_PREFIX.bin2hex(random_bytes(16));
        $this->writeFile($path, '');

        return $path;
    }

    /**
     * Where the buffered bytes are. The finishing step reads the file from
     * here instead of pulling it into memory.
     */
    public function dataPath(string $id): string
    {
        return $this->dir($id).\DIRECTORY_SEPARATOR.'data';
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
        @unlink($this->dir($id).\DIRECTORY_SEPARATOR.'lock');
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
        $removed = 0;

        foreach ((array) @glob($this->root().\DIRECTORY_SEPARATOR.self::TEMP_PREFIX.'*') as $temp) {
            if (\is_string($temp) && (int) @filemtime($temp) < time() - self::TTL_SECONDS && @unlink($temp)) {
                ++$removed;
            }
        }

        foreach ($this->ids() as $entry) {
            $manifest = $this->readManifest($entry);
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
     * How many live uploads this user has open. Trusted mode (no user) counts
     * the sessions that have no owner either.
     */
    public function openFor(?int $userId): int
    {
        $open = 0;

        foreach ($this->ids() as $entry) {
            $manifest = $this->readManifest($entry);

            if (!\is_array($manifest) || (int) ($manifest['expires_at'] ?? 0) < time()) {
                continue;
            }

            $owner = $manifest['user_id'] ?? null;

            if (($owner === null ? null : (int) $owner) === $userId) {
                ++$open;
            }
        }

        return $open;
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>
     */
    private function advance(array $manifest, int $bytes): array
    {
        $manifest['received_bytes'] = (int) $manifest['received_bytes'] + $bytes;
        ++$manifest['next_sequence'];
        $manifest['expires_at'] = time() + self::TTL_SECONDS;

        $this->save((string) $manifest['id'], $manifest);

        return $manifest;
    }

    /**
     * @return list<string>
     */
    private function ids(): array
    {
        $root = $this->root();

        if (!is_dir($root)) {
            return [];
        }

        $ids = [];

        foreach ((array) @scandir($root) as $entry) {
            if (\is_string($entry) && preg_match(self::ID_PATTERN, $entry) === 1) {
                $ids[] = $entry;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readManifest(string $id): ?array
    {
        $raw = @file_get_contents($this->manifestPath($id));
        $manifest = \is_string($raw) ? json_decode($raw, true) : null;

        return \is_array($manifest) ? $manifest : null;
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
}
