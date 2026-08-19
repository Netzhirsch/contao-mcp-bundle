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
     * Both the XLF catalogues and the templates — the templates carry
     * hardcoded fallbacks that render whenever a translation is missing, so
     * checking only the XLF would miss half of it.
     *
     * @return iterable<string, array{string}>
     */
    public static function operatorFacingFiles(): iterable
    {
        yield 'de catalogue' => ['contao/languages/de/mcp_server.xlf'];
        yield 'en catalogue' => ['contao/languages/en/mcp_server.xlf'];
        yield 'status template' => ['contao/templates/backend/be_mcp_status.html5'];
        yield 'config template' => ['contao/templates/backend/be_mcp_config.html5'];
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
        foreach (['contao/templates/backend/be_mcp_status.html5', 'src/Backend/Module/ModuleMcpStatus.php'] as $path) {
            self::assertStringNotContainsString(
                'generate_iat',
                (string) file_get_contents(self::ROOT.'/'.$path),
                "$path still exposes IAT generation.",
            );
        }
    }

    /**
     * Saying what is NOT true is only half the job — the operator still has
     * to be told where to click.
     */
    #[DataProvider('operatorFacingFiles')]
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
