<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth;

use League\OAuth2\Server\CryptKey;

/**
 * Auto-provisions an RSA-2048 keypair on first use, stored as PEM files
 * under `var/mcp/oauth/`. Private key signs the JWT access tokens, public
 * key verifies them on the daemon side. Mode 0600 on the private key
 * (read by the same OS user that runs PHP-FPM + the daemon).
 *
 * The encryption key (used for refresh-token encryption inside the auth
 * server) is a 32-byte base64-encoded blob in `var/mcp/oauth/encryption.key`.
 *
 * Rotation (since v0.4.0):
 *   - `rotate()` moves the current keypair to `previous_*.pem` and
 *     generates a fresh pair as `private.pem` / `public.pem`.
 *   - During the rollover window (refresh-token TTL = 30 days), the
 *     resource server tries `public.pem` first and falls back to
 *     `previous_public.pem` so tokens signed with the old key still
 *     validate.
 *   - `pruneOldKeys()` deletes `previous_*.pem` once they exceed the
 *     grace period. Operator runs this from the rotate-keys command.
 *
 * The encryption key is NOT rotated alongside RSA keys — it protects
 * refresh-token payloads at rest in tl_mcp_oauth_refresh_token. Rotating
 * it would invalidate every outstanding refresh token, which is much
 * more disruptive than RSA rotation (where access tokens expire in 1h
 * anyway). A separate `encryption_key_rotate` is a future ticket.
 */
final class KeyManager
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function privateKeyPath(): string
    {
        return $this->dir().\DIRECTORY_SEPARATOR.'private.pem';
    }

    public function publicKeyPath(): string
    {
        return $this->dir().\DIRECTORY_SEPARATOR.'public.pem';
    }

    public function previousPrivateKeyPath(): string
    {
        return $this->dir().\DIRECTORY_SEPARATOR.'previous_private.pem';
    }

    public function previousPublicKeyPath(): string
    {
        return $this->dir().\DIRECTORY_SEPARATOR.'previous_public.pem';
    }

    public function encryptionKeyPath(): string
    {
        return $this->dir().\DIRECTORY_SEPARATOR.'encryption.key';
    }

    /**
     * Generates the keypair + encryption key on first call. Idempotent —
     * existing files are reused.
     */
    public function ensureKeys(): void
    {
        // OpenSSL extension is mandatory — fail loud if a minimalist PHP
        // build ships without it (some distroless / Alpine images do).
        if (!\function_exists('openssl_pkey_new')) {
            throw new \RuntimeException(
                'PHP openssl extension is required for OAuth. Install with: '
                .'`apt install php-openssl` (Debian/Ubuntu) or enable it in your php.ini.',
            );
        }

        if (!is_dir($this->dir()) && !mkdir($this->dir(), 0o700, true) && !is_dir($this->dir())) {
            throw new \RuntimeException(sprintf(
                'Could not create OAuth key directory: %s. Check that the web-server user has write permission on var/mcp/.',
                $this->dir(),
            ));
        }
        if (!is_writable($this->dir())) {
            throw new \RuntimeException(sprintf(
                'OAuth key directory %s is not writable. On Linux: chown -R www-data %s && chmod 700 %s.',
                $this->dir(), $this->dir(), $this->dir(),
            ));
        }

        if (!is_file($this->privateKeyPath()) || !is_file($this->publicKeyPath())) {
            // Windows: openssl_pkey_new() fails with "No such process"
            // unless OpenSSL knows where its config file lives. We try a
            // few well-known locations before giving up. On Linux the
            // system default openssl.cnf is picked up automatically.
            $config = [
                'private_key_bits' => 2048,
                'private_key_type' => \OPENSSL_KEYTYPE_RSA,
            ];
            if (\PHP_OS_FAMILY === 'Windows') {
                $candidates = [
                    getenv('OPENSSL_CONF') ?: null,
                    \dirname(\PHP_BINARY).'/extras/ssl/openssl.cnf',
                    'C:/laragon/bin/php/'.basename(\dirname(\PHP_BINARY)).'/extras/ssl/openssl.cnf',
                ];
                foreach (array_filter($candidates) as $cnf) {
                    if (is_file($cnf)) {
                        $config['config'] = $cnf;
                        break;
                    }
                }
            }
            $res = openssl_pkey_new($config);
            if ($res === false) {
                $errors = [];
                while ($e = openssl_error_string()) { $errors[] = $e; }
                $hint = \PHP_OS_FAMILY === 'Windows'
                    ? ' On Windows: set OPENSSL_CONF=<php-dir>\\extras\\ssl\\openssl.cnf.'
                    : ' On Linux: check that /etc/ssl/openssl.cnf exists or set OPENSSL_CONF.';
                throw new \RuntimeException('Failed to generate RSA keypair: '.implode(' | ', $errors).$hint);
            }
            $privatePem = '';
            openssl_pkey_export($res, $privatePem, null, $config);
            $details = openssl_pkey_get_details($res);
            $publicPem = (string) ($details['key'] ?? '');

            if (@file_put_contents($this->privateKeyPath(), $privatePem, \LOCK_EX) === false) {
                throw new \RuntimeException(sprintf(
                    'Could not write private key to %s — check permissions.',
                    $this->privateKeyPath(),
                ));
            }
            if (@file_put_contents($this->publicKeyPath(), $publicPem, \LOCK_EX) === false) {
                throw new \RuntimeException(sprintf(
                    'Could not write public key to %s — check permissions.',
                    $this->publicKeyPath(),
                ));
            }

            @chmod($this->privateKeyPath(), 0o600);
            @chmod($this->publicKeyPath(), 0o644);
        }

        if (!is_file($this->encryptionKeyPath())) {
            if (@file_put_contents(
                $this->encryptionKeyPath(),
                base64_encode(random_bytes(32)),
                \LOCK_EX,
            ) === false) {
                throw new \RuntimeException(sprintf(
                    'Could not write encryption key to %s — check permissions.',
                    $this->encryptionKeyPath(),
                ));
            }
            @chmod($this->encryptionKeyPath(), 0o600);
        }
    }

    public function privateCryptKey(): CryptKey
    {
        $this->ensureKeys();

        return new CryptKey($this->privateKeyPath(), null, false);
    }

    public function publicCryptKey(): CryptKey
    {
        $this->ensureKeys();

        return new CryptKey($this->publicKeyPath(), null, false);
    }

    /**
     * The previous public key — only present after a rotation, only
     * during the grace-period window before `pruneOldKeys()` cleans it
     * up. Used by the resource server to validate tokens that were
     * signed under the old private key during the rollover.
     */
    public function previousPublicCryptKey(): ?CryptKey
    {
        if (!is_file($this->previousPublicKeyPath())) {
            return null;
        }
        return new CryptKey($this->previousPublicKeyPath(), null, false);
    }

    public function hasPreviousKey(): bool
    {
        return is_file($this->previousPublicKeyPath()) && is_file($this->previousPrivateKeyPath());
    }

    public function encryptionKey(): string
    {
        $this->ensureKeys();

        return (string) file_get_contents($this->encryptionKeyPath());
    }

    /**
     * Age of the current private key in seconds, or null if the key
     * does not yet exist (pre-OAuth-bootstrap).
     */
    public function currentKeyAgeSeconds(): ?int
    {
        if (!is_file($this->privateKeyPath())) {
            return null;
        }
        $mtime = @filemtime($this->privateKeyPath());
        if ($mtime === false) {
            return null;
        }
        return max(0, time() - $mtime);
    }

    /**
     * Age of the previous private key in seconds, or null if there is
     * no previous key (never rotated, or already pruned).
     */
    public function previousKeyAgeSeconds(): ?int
    {
        if (!is_file($this->previousPrivateKeyPath())) {
            return null;
        }
        $mtime = @filemtime($this->previousPrivateKeyPath());
        if ($mtime === false) {
            return null;
        }
        return max(0, time() - $mtime);
    }

    /**
     * Rotate the keypair. Atomic from the operator's POV:
     *   1. Generate fresh keypair into temp file
     *   2. Move current private/public → previous_*
     *   3. Move temp → private/public
     *
     * Any existing previous_* files are overwritten — only one
     * generation of previous keys is kept (an operator who rotates
     * twice in quick succession loses tokens issued under the
     * generation before the most recent).
     *
     * @return array{rotated: bool, previous_age_seconds: ?int, new_path: string}
     */
    public function rotate(): array
    {
        $this->ensureKeys();

        $previousAge = $this->currentKeyAgeSeconds();

        // Generate fresh keypair into separate temp paths so we don't
        // half-write over the live files if openssl fails mid-flight.
        $tmpPrivate = $this->dir().\DIRECTORY_SEPARATOR.'rotate_new_private.pem.tmp';
        $tmpPublic = $this->dir().\DIRECTORY_SEPARATOR.'rotate_new_public.pem.tmp';

        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => \OPENSSL_KEYTYPE_RSA,
        ];
        if (\PHP_OS_FAMILY === 'Windows') {
            $candidates = [
                getenv('OPENSSL_CONF') ?: null,
                \dirname(\PHP_BINARY).'/extras/ssl/openssl.cnf',
                'C:/laragon/bin/php/'.basename(\dirname(\PHP_BINARY)).'/extras/ssl/openssl.cnf',
            ];
            foreach (array_filter($candidates) as $cnf) {
                if (is_file($cnf)) {
                    $config['config'] = $cnf;
                    break;
                }
            }
        }
        $res = openssl_pkey_new($config);
        if ($res === false) {
            $errors = [];
            while ($e = openssl_error_string()) { $errors[] = $e; }
            throw new \RuntimeException('Failed to generate rotation keypair: '.implode(' | ', $errors));
        }
        $privatePem = '';
        openssl_pkey_export($res, $privatePem, null, $config);
        $details = openssl_pkey_get_details($res);
        $publicPem = (string) ($details['key'] ?? '');

        if (@file_put_contents($tmpPrivate, $privatePem, \LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Could not write temp private key %s', $tmpPrivate));
        }
        if (@file_put_contents($tmpPublic, $publicPem, \LOCK_EX) === false) {
            @unlink($tmpPrivate);
            throw new \RuntimeException(sprintf('Could not write temp public key %s', $tmpPublic));
        }
        @chmod($tmpPrivate, 0o600);
        @chmod($tmpPublic, 0o644);

        // Demote current → previous. rename() is atomic on POSIX; on
        // Windows it's also a single syscall. If either rename fails
        // we're left in a half-rotated state, but the live keys are
        // still where they were — better than partial corruption.
        if (is_file($this->privateKeyPath())) {
            if (!@rename($this->privateKeyPath(), $this->previousPrivateKeyPath())) {
                @unlink($tmpPrivate);
                @unlink($tmpPublic);
                throw new \RuntimeException('Could not move current private key to previous_private.pem');
            }
        }
        if (is_file($this->publicKeyPath())) {
            if (!@rename($this->publicKeyPath(), $this->previousPublicKeyPath())) {
                // Partial rollback: put the private one back if we can.
                @rename($this->previousPrivateKeyPath(), $this->privateKeyPath());
                @unlink($tmpPrivate);
                @unlink($tmpPublic);
                throw new \RuntimeException('Could not move current public key to previous_public.pem');
            }
        }

        // Reset mtime on the demoted files to "now". Without this the
        // age tracking would report the content-creation time, not the
        // rotation time — making prune thresholds meaningless (a key
        // that was rotated 1 day ago but originally generated 100 days
        // ago would be flagged for prune even though the rollover
        // window has only just started).
        $now = time();
        @touch($this->previousPrivateKeyPath(), $now);
        @touch($this->previousPublicKeyPath(), $now);

        // Promote temp → current.
        if (!@rename($tmpPrivate, $this->privateKeyPath())) {
            throw new \RuntimeException('Could not move new private key into place');
        }
        if (!@rename($tmpPublic, $this->publicKeyPath())) {
            throw new \RuntimeException('Could not move new public key into place');
        }

        return [
            'rotated' => true,
            'previous_age_seconds' => $previousAge,
            'new_path' => $this->privateKeyPath(),
        ];
    }

    /**
     * Delete the previous keypair if it is older than the given
     * threshold. After deletion, tokens that were signed under that
     * key can no longer be validated — caller must ensure all refresh
     * tokens issued under the old key have either been used (and
     * rotated to the new key) or aged out (refresh-token TTL = 30d).
     *
     * @return array{pruned: bool, age_seconds: ?int, reason?: string}
     */
    public function pruneOldKeys(int $maxAgeSeconds): array
    {
        if (!$this->hasPreviousKey()) {
            return ['pruned' => false, 'age_seconds' => null, 'reason' => 'no previous keys present'];
        }
        $age = $this->previousKeyAgeSeconds();
        if ($age === null || $age < $maxAgeSeconds) {
            return ['pruned' => false, 'age_seconds' => $age, 'reason' => 'previous keys younger than threshold'];
        }
        @unlink($this->previousPrivateKeyPath());
        @unlink($this->previousPublicKeyPath());
        return ['pruned' => true, 'age_seconds' => $age];
    }

    private function dir(): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'mcp'.\DIRECTORY_SEPARATOR.'oauth';
    }
}
