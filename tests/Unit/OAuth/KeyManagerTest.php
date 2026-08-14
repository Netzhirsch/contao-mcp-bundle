<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\OAuth;

use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Netzhirsch\ContaoMcpBundle\OAuth\KeyManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers the OAuth RSA key lifecycle that's the load-bearing crypto for
 * the whole bundle. The smoke test exercises the file-level bookkeeping
 * (rotate creates previous_*, prune removes it) but does NOT prove that
 * tokens signed under the previous key still cryptographically verify
 * after a rotation — and that's the whole point of the grace window.
 *
 * Tests here come in two flavours:
 *
 *   1. File-lifecycle  — pure filesystem + KeyManager API. Cheap, fast.
 *      Mirrors the smoke-test asserts but in isolation, so a CI failure
 *      pinpoints the regression instead of bubbling up through 200 tool
 *      calls.
 *
 *   2. Crypto-roundtrip — uses lcobucci/jwt (same library league/oauth2-
 *      server uses under the hood) to actually sign + verify a JWT. Proves
 *      that after rotate(), the previous public key still validates
 *      tokens signed under the previous private key, and that after
 *      pruneOldKeys(), the previous key is gone. Without this, the
 *      "grace window" claim is purely architectural — untested.
 *
 * Every test creates its own throwaway directory under sys_get_temp_dir()
 * and removes it on tearDown(). No shared state.
 */
#[CoversClass(KeyManager::class)]
final class KeyManagerTest extends TestCase
{
    private string $tmpProjectDir = '';

    protected function setUp(): void
    {
        $this->tmpProjectDir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'mcp_keymanager_test_'.bin2hex(random_bytes(6));
        mkdir($this->tmpProjectDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmpProjectDir !== '' && is_dir($this->tmpProjectDir)) {
            self::rrmdir($this->tmpProjectDir);
        }
    }

    // ── File-lifecycle ───────────────────────────────────────────────

    public function testEnsureKeysCreatesPrivatePublicAndEncryptionKey(): void
    {
        $km = $this->makeKeyManager();
        $km->ensureKeys();

        self::assertFileExists($km->privateKeyPath());
        self::assertFileExists($km->publicKeyPath());
        self::assertFileExists($km->encryptionKeyPath());
        self::assertStringContainsString('BEGIN PRIVATE KEY', (string) file_get_contents($km->privateKeyPath()));
        self::assertStringContainsString('BEGIN PUBLIC KEY', (string) file_get_contents($km->publicKeyPath()));
    }

    public function testEnsureKeysIsIdempotent(): void
    {
        $km = $this->makeKeyManager();
        $km->ensureKeys();
        $firstContent = (string) file_get_contents($km->privateKeyPath());

        // Second call must NOT regenerate — otherwise every PHP-FPM worker
        // boot would invalidate all outstanding tokens.
        $km->ensureKeys();
        $secondContent = (string) file_get_contents($km->privateKeyPath());

        self::assertSame($firstContent, $secondContent);
    }

    public function testRotateProducesFreshKeypairAndMovesOldToPrevious(): void
    {
        $km = $this->makeKeyManager();
        $km->ensureKeys();
        $beforePrivate = (string) file_get_contents($km->privateKeyPath());
        $beforePublic = (string) file_get_contents($km->publicKeyPath());

        $result = $km->rotate();

        self::assertTrue($result['rotated']);
        self::assertSame($km->privateKeyPath(), $result['new_path']);

        // New live key differs from the old one.
        $afterPrivate = (string) file_get_contents($km->privateKeyPath());
        self::assertNotSame($beforePrivate, $afterPrivate);

        // Old key moved to previous_*.
        self::assertSame($beforePrivate, (string) file_get_contents($km->previousPrivateKeyPath()));
        self::assertSame($beforePublic, (string) file_get_contents($km->previousPublicKeyPath()));
    }

    public function testRotateMtimeReflectsRotationTimeNotFileCreationTime(): void
    {
        // Backstory: on Windows, rename() preserves the source file's mtime.
        // Without the explicit @touch() inside KeyManager::rotate(), the age
        // of previous_*.pem would be measured from when the content was
        // originally generated — making prune thresholds nonsensical after
        // the second rotation. This test pins that fix.
        $km = $this->makeKeyManager();
        $km->ensureKeys();

        // Backdate the LIVE key to look like it was created an hour ago.
        $oneHourAgo = time() - 3600;
        touch($km->privateKeyPath(), $oneHourAgo);
        touch($km->publicKeyPath(), $oneHourAgo);

        $km->rotate();

        // After rotation, the previous_* mtime must reflect the rotation
        // moment (now), NOT the 1h-ago backdate of the original file.
        $previousAge = $km->previousKeyAgeSeconds();
        self::assertNotNull($previousAge);
        self::assertLessThan(60, $previousAge, 'Previous key age should be near-zero immediately after rotation, not the original file age.');
    }

    public function testHasPreviousKeyReflectsRotateAndPruneState(): void
    {
        $km = $this->makeKeyManager();
        $km->ensureKeys();

        self::assertFalse($km->hasPreviousKey(), 'No previous key before first rotation.');

        $km->rotate();
        self::assertTrue($km->hasPreviousKey(), 'Previous key present after rotation.');

        $km->pruneOldKeys(0);
        self::assertFalse($km->hasPreviousKey(), 'Previous key gone after prune.');
    }

    public function testPruneWithHugeThresholdLeavesYoungPreviousKeyAlone(): void
    {
        $km = $this->makeKeyManager();
        $km->ensureKeys();
        $km->rotate();

        // Threshold = 1 day, just-rotated previous key is seconds old.
        $result = $km->pruneOldKeys(86400);

        self::assertFalse($result['pruned']);
        self::assertSame('previous keys younger than threshold', $result['reason'] ?? null);
        self::assertTrue($km->hasPreviousKey());
    }

    public function testPruneWithZeroThresholdRemovesPreviousKey(): void
    {
        $km = $this->makeKeyManager();
        $km->ensureKeys();
        $km->rotate();

        $result = $km->pruneOldKeys(0);

        self::assertTrue($result['pruned']);
        self::assertFileDoesNotExist($km->previousPrivateKeyPath());
        self::assertFileDoesNotExist($km->previousPublicKeyPath());
    }

    public function testPruneWithoutPreviousKeyIsNoOp(): void
    {
        $km = $this->makeKeyManager();
        $km->ensureKeys();

        // No rotation has happened. Prune must be a graceful no-op so a
        // monthly cron doesn't spam errors on freshly-installed sites.
        $result = $km->pruneOldKeys(0);

        self::assertFalse($result['pruned']);
        self::assertSame('no previous keys present', $result['reason'] ?? null);
    }

    public function testPreviousPublicCryptKeyReturnsNullWhenNotRotated(): void
    {
        $km = $this->makeKeyManager();
        $km->ensureKeys();

        // The McpOAuthValidator relies on null here to skip the dual-key
        // fallback path entirely — otherwise it would build a ResourceServer
        // wired against a non-existent file.
        self::assertNull($km->previousPublicCryptKey());
    }

    public function testCurrentAndPreviousKeyAgesReturnNullWhenNotPresent(): void
    {
        $km = $this->makeKeyManager();

        // Pre-ensureKeys: nothing on disk yet.
        self::assertNull($km->currentKeyAgeSeconds());
        self::assertNull($km->previousKeyAgeSeconds());

        $km->ensureKeys();
        self::assertNotNull($km->currentKeyAgeSeconds());
        self::assertNull($km->previousKeyAgeSeconds(), 'No previous key without rotation.');
    }

    // ── Crypto-roundtrip ──────────────────────────────────────────────

    public function testTokenSignedWithCurrentKeyVerifiesWithCurrentPublicKey(): void
    {
        $km = $this->makeKeyManager();
        $km->ensureKeys();

        $token = $this->mintToken($km->privateKeyPath());

        $signer = new Sha256();
        $publicKey = InMemory::file($km->publicKeyPath());
        $validator = new Validator();

        self::assertTrue(
            $validator->validate($token, new SignedWith($signer, $publicKey)),
            'Newly-minted token must validate against the current public key.',
        );
    }

    public function testTokenSignedWithPreviousKeyStillVerifiesAfterRotation(): void
    {
        // THE central test for the grace-window claim. Before the dual-key
        // validator existed, rotating keys instantly invalidated every
        // outstanding session. Now: tokens issued under the OLD private
        // key continue to validate against previous_public.pem during the
        // rollover window. This test proves it at the crypto layer —
        // McpOAuthValidator's responsibility to actually try both keys is
        // a separate concern.
        $km = $this->makeKeyManager();
        $km->ensureKeys();

        // Mint token under the CURRENT key, capture it.
        $tokenString = $this->mintToken($km->privateKeyPath())->toString();

        $km->rotate();

        // The current key is now a fresh one — token must NOT verify
        // against it.
        $signer = new Sha256();
        $validator = new Validator();
        $parser = new Parser(new \Lcobucci\JWT\Encoding\JoseEncoder());
        $token = $parser->parse($tokenString);

        self::assertInstanceOf(\Lcobucci\JWT\UnencryptedToken::class, $token);
        $newPublicKey = InMemory::file($km->publicKeyPath());
        self::assertFalse(
            $validator->validate($token, new SignedWith($signer, $newPublicKey)),
            'Token from before rotation must NOT validate against the new public key.',
        );

        // But the previous public key MUST still verify it.
        $previousPath = $km->previousPublicKeyPath();
        self::assertFileExists($previousPath);
        $previousPublicKey = InMemory::file($previousPath);
        self::assertTrue(
            $validator->validate($token, new SignedWith($signer, $previousPublicKey)),
            'Token from before rotation MUST still validate against the previous public key.',
        );
    }

    public function testTokenSignedWithPreviousKeyFailsAfterPrune(): void
    {
        // After the operator runs `--prune-old`, tokens signed under the
        // old key MUST become invalid — that's the entire point of the
        // grace-window-then-prune model. Without this test, a buggy prune
        // could leave the previous files in place and we'd never know
        // until a security audit.
        $km = $this->makeKeyManager();
        $km->ensureKeys();

        $tokenString = $this->mintToken($km->privateKeyPath())->toString();

        $km->rotate();
        $km->pruneOldKeys(0);

        // previousPublicCryptKey() returns null → no fallback possible.
        self::assertNull($km->previousPublicCryptKey());
        self::assertFalse($km->hasPreviousKey());

        // The new public key won't verify the old token (different key);
        // the previous file is gone. Belt and suspenders — there is no
        // way left to validate.
        $signer = new Sha256();
        $validator = new Validator();
        $parser = new Parser(new \Lcobucci\JWT\Encoding\JoseEncoder());
        $token = $parser->parse($tokenString);

        self::assertInstanceOf(\Lcobucci\JWT\UnencryptedToken::class, $token);
        $newPublicKey = InMemory::file($km->publicKeyPath());
        self::assertFalse($validator->validate($token, new SignedWith($signer, $newPublicKey)));
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function makeKeyManager(): KeyManager
    {
        return new KeyManager($this->tmpProjectDir);
    }

    private function mintToken(string $privateKeyPath): \Lcobucci\JWT\UnencryptedToken
    {
        $signer = new Sha256();
        $privateKey = InMemory::file($privateKeyPath);

        $now = new \DateTimeImmutable();
        $builder = new Builder(
            new \Lcobucci\JWT\Encoding\JoseEncoder(),
            \Lcobucci\JWT\Encoding\ChainedFormatter::default(),
        );

        // lcobucci/jwt distinguishes "registered claims" (sub, iss, aud,
        // exp, nbf, iat, jti — RFC 7519 §4.1) from custom claims. The
        // registered ones get type-safe setters; withClaim() throws on
        // them. We only need `sub` here to make the token look realistic;
        // client_id is a custom claim and stays on withClaim().
        return $builder
            ->issuedAt($now)
            ->expiresAt($now->modify('+1 hour'))
            ->relatedTo('test-user-1')
            ->withClaim('client_id', 'test-client')
            ->getToken($signer, $privateKey)
        ;
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.\DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? self::rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
