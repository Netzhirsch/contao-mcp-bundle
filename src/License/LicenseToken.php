<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\License;

/**
 * Verifies (and, vendor-side, signs) a license token.
 *
 * Format: `base64url(payloadJson).base64url(signature)` — a detached Ed25519
 * signature over the exact payload JSON bytes. The payload carries:
 *   product     string  must equal {@see PRODUCT}
 *   domain      string  the licensed host (normalised, no scheme/www/port)
 *   type        string  'trial' | 'full'
 *   license_id  string  server-side id (audit/revocation)
 *   issued_at   int      unix ts
 *   expires_at  int      unix ts
 *
 * Verification is fully OFFLINE — no network, no clock authority beyond the
 * caller-supplied high-water mark (blunts clock rollback). The matching SECRET
 * key exists ONLY on the vendor license server; the PUBLIC key below can only
 * verify, never mint, so it is safe to ship inside the bundle.
 *
 * The public key lives in code — NOT in var/mcp/config.json — on purpose: a
 * customer-editable key could be swapped for a self-generated one to sign
 * unlimited tokens. Patching the source is the accepted (unpreventable) bar;
 * a config swap must not be.
 *
 * 8.1-clean by design (the bundle supports Contao 5.3 / PHP ^8.1): no typed
 * class constants, no 8.2/8.3-only syntax.
 */
final class LicenseToken
{
    /**
     * Vendor's Ed25519 PUBLIC key, base64 of the raw 32 bytes. It can only
     * VERIFY tokens, never mint them (the matching SECRET key lives on the
     * license server only), so shipping it in the bundle is safe. It lives in
     * code — NOT in var/mcp/config.json — on purpose: a customer-editable key
     * could be swapped for a self-generated one to sign unlimited tokens.
     * Rotate it with `contao:mcp:license keygen` (keep the secret server-side).
     */
    public const VENDOR_PUBLIC_KEY_B64 = 'Sn5jLXcTjI92Pt8XCotwccWGzj8TmAcMTzNyk15edB0=';

    public const PRODUCT = 'netzhirsch/contao-mcp-bundle';

    /** Tolerated clock lag behind the issuing server, in seconds. */
    public const CLOCK_SKEW_TOLERANCE = 300;

    /**
     * @param string $publicKeyB64 defaults to the shipped vendor key; tests and
     *                             the keygen self-check pass an ephemeral key
     */
    public function __construct(
        private readonly string $publicKeyB64 = self::VENDOR_PUBLIC_KEY_B64,
    ) {
    }

    /**
     * @return array{valid: bool, reason: string, type: string, expires_at: int, issued_at: int, now_ref: int, license_id: string}
     */
    public function verify(string $token, string $host, int $seenHighWater = 0): array
    {
        $now = time();
        $ref = max($now, $seenHighWater);
        $fail = static fn (string $reason): array => [
            'valid' => false, 'reason' => $reason, 'type' => '',
            'expires_at' => 0, 'issued_at' => 0, 'now_ref' => $ref, 'license_id' => '',
        ];

        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            return $fail('sodium_unavailable');
        }

        $token = trim($token);
        if ($token === '') {
            return $fail('no_token');
        }

        $parts = explode('.', $token);
        if (\count($parts) !== 2) {
            return $fail('malformed');
        }

        $payloadJson = self::b64urlDecode($parts[0]);
        $sig = self::b64urlDecode($parts[1]);
        $pub = base64_decode($this->publicKeyB64, true);
        if ($payloadJson === false || $sig === false || $pub === false
            || \strlen($pub) !== \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $fail('malformed');
        }

        // The forged/tampered case — the customer does not have the secret key.
        if (!sodium_crypto_sign_verify_detached($sig, $payloadJson, $pub)) {
            return $fail('bad_signature');
        }

        $p = json_decode($payloadJson, true);
        if (!\is_array($p)) {
            return $fail('malformed');
        }
        if (self::PRODUCT !== ($p['product'] ?? null)) {
            return $fail('wrong_product');
        }
        if (!hash_equals(self::normalizeHost((string) ($p['domain'] ?? '')), self::normalizeHost($host))) {
            return $fail('wrong_domain');
        }

        $iat = (int) ($p['issued_at'] ?? 0);
        $exp = (int) ($p['expires_at'] ?? 0);
        $type = (string) ($p['type'] ?? 'full');
        $licenseId = (string) ($p['license_id'] ?? '');
        $base = [
            'type' => $type, 'expires_at' => $exp, 'issued_at' => $iat,
            'now_ref' => $ref, 'license_id' => $licenseId,
        ];

        // Small negative tolerance: the server stamps issued_at from ITS clock,
        // so a client running a few seconds/minutes behind (unsynced VPS, drifting
        // container) would otherwise hard-fail right after a successful
        // activation — and `clock_tampered` gets no grace. Rollback protection is
        // unaffected: the high-water mark still pins $ref forward.
        if ($ref < $iat - self::CLOCK_SKEW_TOLERANCE) {
            return ['valid' => false, 'reason' => 'clock_tampered'] + $base;
        }
        if ($ref > $exp) {
            return ['valid' => false, 'reason' => 'expired'] + $base;
        }

        return ['valid' => true, 'reason' => 'ok'] + $base;
    }

    /**
     * VENDOR-SIDE: sign a payload with the Ed25519 secret key. Used by the
     * license server, the keygen self-check and the smoke test. Requires the
     * SECRET key — never call this in customer code paths.
     *
     * @param array<string, mixed> $payload
     */
    public static function sign(array $payload, string $secretKeyB64): string
    {
        $secret = base64_decode($secretKeyB64, true);
        if ($secret === false || \strlen($secret) !== \SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('Invalid Ed25519 secret key.');
        }
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig = sodium_crypto_sign_detached($json, $secret);

        return self::b64url($json).'.'.self::b64url($sig);
    }

    /**
     * VENDOR-SIDE bootstrap: generate a fresh Ed25519 keypair.
     *
     * @return array{public: string, secret: string} base64-encoded raw keys
     */
    public static function keypair(): array
    {
        $kp = sodium_crypto_sign_keypair();

        return [
            'public' => base64_encode(sodium_crypto_sign_publickey($kp)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($kp)),
        ];
    }

    /**
     * Canonical licensed host: prefer the configured public backend_url (works
     * in CLI/cron where there is no request), fall back to the live request
     * host. Both the gate and the renewal client MUST resolve the domain the
     * same way, or a token bound to backend_url would fail against the request
     * host (www vs non-www, etc.).
     */
    public static function resolveDomain(string $backendUrl, ?string $requestHost): string
    {
        $backendUrl = trim($backendUrl);
        if ($backendUrl !== '') {
            // parse_url('kunde.de') has no host (no scheme → parsed as path), so
            // accept a bare host too instead of silently falling through.
            $host = parse_url($backendUrl, PHP_URL_HOST);
            if (!\is_string($host) || $host === '') {
                $host = parse_url('https://'.ltrim($backendUrl, '/'), PHP_URL_HOST);
            }
            if (\is_string($host) && $host !== '') {
                return self::normalizeHost($host);
            }
        }

        return self::normalizeHost((string) $requestHost);
    }

    /**
     * The `domain` claim of a stored token, WITHOUT verifying the signature.
     *
     * Used only where no request host exists (CLI/cron) and `backend_url` is
     * unset: the renewal client must still tell the server WHICH license to
     * renew, and the status command must show something meaningful. Safe,
     * because it grants nothing — the server re-validates, and the signature
     * check on the HTTP tool path (where a real request host exists) is
     * untouched. Never use this as an authorisation input.
     */
    public static function peekDomain(string $token): string
    {
        $parts = explode('.', trim($token));
        if (\count($parts) !== 2) {
            return '';
        }
        $payloadJson = self::b64urlDecode($parts[0]);
        if ($payloadJson === false) {
            return '';
        }
        $payload = json_decode($payloadJson, true);

        return \is_array($payload) ? self::normalizeHost((string) ($payload['domain'] ?? '')) : '';
    }

    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('/:\d+$/', '', $host);   // strip port

        return (string) preg_replace('/^www\./', '', $host);   // strip leading www.
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}
