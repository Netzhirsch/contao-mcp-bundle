<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Backend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The backend must not tell an operator that pairing a client needs an
 * Initial Access Token. It does not, and no standard MCP client can send one
 * during RFC 7591 registration — the pairing window is the path.
 *
 * This is a wording test because the wording was the bug. The claim lived in
 * four places at once (a select option, its help text, a status line and a
 * template fallback), so fixing the ones you happen to look at leaves the
 * others contradicting them. Operators dutifully generated IATs that could
 * never pair anything.
 */
final class PairingWordingTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /**
     * Everything an operator reads, plus the templates that put it on screen.
     *
     * The templates used to carry hardcoded fallbacks that rendered whenever
     * a translation was missing, so checking only the XLF missed half of it.
     * Since the move to Twig they print catalogue keys and nothing else —
     * which is why they are not in filesWithOperatorProse() — but they stay in
     * this net for the day someone types a sentence straight into one.
     *
     * @return iterable<string, array{string}>
     */
    public static function operatorFacingFiles(): iterable
    {
        yield from self::filesWithOperatorProse();
        yield 'status template' => ['contao/templates/be_mcp_status.html.twig'];
        yield 'config template' => ['contao/templates/be_mcp_config.html.twig'];
    }

    /**
     * The files that carry the wording itself.
     *
     * @return iterable<string, array{string}>
     */
    public static function filesWithOperatorProse(): iterable
    {
        yield 'de catalogue' => ['contao/languages/de/mcp_server.xlf'];
        yield 'en catalogue' => ['contao/languages/en/mcp_server.xlf'];
        // The refusal message is operator-facing too: it is what lands in
        // tl_log when a client is turned away, and what the client itself
        // gets back. Scoping this test to contao/ let it keep leading with
        // "requires an Initial Access Token" while every other string had
        // already been corrected.
        yield 'registration endpoint' => ['src/Controller/OAuth/RegisterController.php'];
        // Sixth place the claim turned up: the config table called restricted
        // mode "IAT-Pflicht". Operators read the README before they ever open
        // the backend, so it belongs in the same net.
        yield 'readme (de)' => ['README.md'];
        yield 'readme (en)' => ['README.en.md'];
    }

    /**
     * @return list<string>
     */
    private static function forbiddenClaims(): array
    {
        return [
            'Initial Access Token required',
            'Initial Access Token erforderlich',
            'must supply a valid Initial Access Token',
            'requires an Initial Access Token',
            'IAT-Pflicht',
            '(IAT required)',
            'müssen beim Registrieren ein gültiges Initial Access Token',
            // The button itself is gone (1.6.x). Documentation that still
            // points at it sends operators looking for a control that does not
            // exist — the same failure mode as the claims above, one step
            // removed.
            'IAT-Button',
            'IAT button',
            // "Gate" reads as "restricted means you need an IAT". Restricted
            // means the pairing window; the token path is for scripts.
            'Initial-Access-Token-Gate',
            'Initial-Access-Token gate',
        ];
    }

    #[DataProvider('operatorFacingFiles')]
    public function testNothingClaimsAnAccessTokenIsRequiredForPairing(string $relativePath): void
    {
        $contents = (string) file_get_contents(self::ROOT.'/'.$relativePath);

        foreach (self::forbiddenClaims() as $claim) {
            self::assertStringNotContainsString(
                $claim,
                $contents,
                "$relativePath claims an Initial Access Token is required. Restricted mode is also "
                .'satisfied by the pairing window, which is the only path a standard MCP client can take.',
            );
        }
    }

    /**
     * The backend no longer offers a way to generate an Initial Access Token.
     *
     * It only ever automated the registration step — never the authorization,
     * which still needs a backend login and consent because the only grants
     * are authorization_code and refresh_token. So the button saved exactly
     * one click (opening the pairing window) for callers able to set an HTTP
     * header, while costing every other operator a plausible-looking wrong
     * turn. Existing tokens stay listed until they expire; nothing issues new
     * ones.
     */
    public function testTheBackendOffersNoWayToGenerateAnAccessToken(): void
    {
        foreach (['contao/templates/be_mcp_status.html.twig', 'src/Backend/Module/ModuleMcpStatus.php'] as $path) {
            self::assertStringNotContainsString(
                'generate_iat',
                (string) file_get_contents(self::ROOT.'/'.$path),
                "$path still exposes IAT generation.",
            );
        }
    }

    /**
     * The window stays open for its full 15 minutes. Up to 1.4.0 it closed on
     * the first successful registration, and that sentence outlived the
     * behaviour by almost forty releases: it was the very message an operator got
     * after clicking the button, in both catalogues, in the client guide and
     * in the code comments beside the logic that contradicts it. Historical
     * notes ("bis 1.4.0 schloss es …") stay allowed; they say "closed".
     *
     * @return iterable<string, array{string}>
     */
    public static function filesDescribingThePairingWindow(): iterable
    {
        yield from self::filesWithOperatorProse();
        yield 'status module' => ['src/Backend/Module/ModuleMcpStatus.php'];
        yield 'config storage' => ['src/Backend/McpServerConfigStorage.php'];
        yield 'client guide' => ['docs/mcp-client-lokal-einrichten.md'];
        yield 'installation guide' => ['docs/installation.md'];
        yield 'documentation' => ['docs/dokumentation.md'];
    }

    #[DataProvider('filesDescribingThePairingWindow')]
    public function testNothingClaimsTheWindowClosesAfterTheFirstRegistration(string $relativePath): void
    {
        $contents = (string) file_get_contents(self::ROOT.'/'.$relativePath);

        foreach ([
            'closes automatically after the first',
            'auto-closes after the first',
            'one successful registration',
            'ONE anonymous registration',
            'first success closes',
            'schließt sich nach der ersten',
            'genau EINE erfolgreiche Registrierung',
            'registration for 10 minutes',
            'Registrierung für 10 Minuten',
        ] as $claim) {
            self::assertStringNotContainsString(
                $claim,
                $contents,
                "$relativePath says the pairing window closes early. It stays open for its full 15 minutes, retries and further clients included.",
            );
        }
    }

    /**
     * Saying what is NOT true is only half the job — the operator still has
     * to be told where to click.
     */
    #[DataProvider('filesWithOperatorProse')]
    public function testTheOperatorIsPointedAtThePairingWindow(string $relativePath): void
    {
        $contents = (string) file_get_contents(self::ROOT.'/'.$relativePath);

        self::assertMatchesRegularExpression(
            '/[Pp]airing[- ][Ff]enster|pairing window/',
            $contents,
            "$relativePath never mentions the pairing window.",
        );
    }
}
