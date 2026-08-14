<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\License;

/**
 * Persists the license token + runtime bookkeeping in `var/mcp/license.json`
 * (separate from the operator-editable config.json — this file rotates and is
 * managed by the activate/trial/renew commands, not hand-edited).
 *
 *   token         string  the current signed license token ('' = none)
 *   hwm           int     forward-only "highest time ever seen" (clock-rollback guard)
 *   last_renew_at int     unix ts of the last successful renewal (renew throttle)
 */
final class LicenseStore
{
    /** Minimum high-water-mark advance before it is written back (seconds). */
    private const HWM_WRITE_GRANULARITY = 3600;

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function getToken(): string
    {
        return (string) ($this->load()['token'] ?? '');
    }

    public function setToken(string $token): bool
    {
        $data = $this->load();
        $data['token'] = trim($token);

        return $this->write($data);
    }

    public function getHwm(): int
    {
        return (int) ($this->load()['hwm'] ?? 0);
    }

    /**
     * Advance the high-water mark. Only ever moves forward, so rewinding the
     * system clock cannot un-expire a token. Best-effort: the file is local
     * and therefore deletable — this raises the bar, it is not a hard guard.
     */
    public function bumpHwm(int $ts): void
    {
        $data = $this->load();
        $current = (int) ($data['hwm'] ?? 0);
        // Only persist a MEANINGFUL jump. The gate calls this on every tool
        // call, so writing on each passing second would rewrite license.json
        // constantly — pointless I/O, and every read-modify-write is a window
        // in which a concurrently renewed token could be clobbered. An hour of
        // granularity is ample for a clock-rollback guard.
        if ($ts > $current + self::HWM_WRITE_GRANULARITY) {
            $data['hwm'] = $ts;
            $this->write($data);
        }
    }

    public function getLastRenewAt(): int
    {
        return (int) ($this->load()['last_renew_at'] ?? 0);
    }

    public function setLastRenewAt(int $ts): void
    {
        $data = $this->load();
        $data['last_renew_at'] = $ts;
        $this->write($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $path = $this->filePath();
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): bool
    {
        $dir = \dirname($this->filePath());
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return false;
        }

        return false !== @file_put_contents(
            $this->filePath(),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            LOCK_EX,
        );
    }

    private function filePath(): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'mcp'.\DIRECTORY_SEPARATOR.'license.json';
    }
}
