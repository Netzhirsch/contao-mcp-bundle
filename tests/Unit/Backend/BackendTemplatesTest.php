<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Backend;

use Netzhirsch\ContaoMcpBundle\Backend\Module\ModuleMcpActivity;
use Netzhirsch\ContaoMcpBundle\Backend\Module\ModuleMcpConfig;
use Netzhirsch\ContaoMcpBundle\Backend\Module\ModuleMcpStatus;
use Netzhirsch\ContaoMcpBundle\Backend\Module\ModuleMcpTools;
use Netzhirsch\ContaoMcpBundle\License\UpdateNotice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The MCP-Server backend modules render through Contao's BackendTemplate, and
 * Contao 6 renders one only as `@Contao/<name>.html.twig` — it dropped .html5
 * templates altogether. Shipping .html5 left every module page a 500
 * ("Template "@Contao/be_mcp_status.html.twig" is not defined", issue #3),
 * while the smoke test, which never opens the backend, stayed green.
 *
 * The templates are rendered here the way Contao 6 renders them: Twig's own
 * html escaping, which double-encodes — 5.x had contao_html, which did not —
 * strict variables as in debug mode, and the strings from our own catalogues.
 * So a value that arrives pre-encoded, or a key missing from a catalogue,
 * shows up in the output, not only on a customer's screen.
 */
final class BackendTemplatesTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    private const TEMPLATES = self::ROOT.'/contao/templates';

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function modules(): iterable
    {
        yield 'status' => [ModuleMcpStatus::class];
        yield 'config' => [ModuleMcpConfig::class];
        yield 'activity' => [ModuleMcpActivity::class];
        yield 'tools' => [ModuleMcpTools::class];
    }

    /**
     * At the root of contao/templates/, not in a subfolder: there the name is
     * the file name whether or not the directory is a Twig namespace root. A
     * .twig-root marker added later — for a content element template, say —
     * would otherwise turn `backend/be_mcp_status` into the name, and the
     * module would be back to "is not defined".
     *
     * @param class-string $module
     */
    #[DataProvider('modules')]
    public function testEveryModuleHasATwigTemplate(string $module): void
    {
        $name = (new \ReflectionProperty($module, 'strTemplate'))->getDefaultValue();

        self::assertIsString($name);
        self::assertFileExists(self::TEMPLATES.'/'.$name.'.html.twig', "$module has no Twig template — Contao 6 cannot render it.");
    }

    public function testNoLegacyPhpTemplatesAreShipped(): void
    {
        $legacy = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/contao/templates', \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.html5')) {
                $legacy[] = $file->getPathname();
            }
        }

        self::assertSame([], $legacy, 'Contao 6 dropped .html5 templates; ship Twig instead.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function templates(): iterable
    {
        foreach (['be_mcp_status', 'be_mcp_config', 'be_mcp_activity', 'be_mcp_tools'] as $name) {
            yield $name => [$name];
        }
    }

    /**
     * There are no hardcoded fallbacks in the templates any more, so a key
     * missing from a catalogue would print as the key itself.
     */
    #[DataProvider('templates')]
    public function testEveryKeyIsInBothCatalogues(string $template): void
    {
        $source = (string) file_get_contents(self::TEMPLATES.'/'.$template.'.html.twig');

        self::assertStringContainsString("{% trans_default_domain 'contao_mcp_server' %}", $source);
        preg_match_all("/'(mcp_server\\.[a-z0-9_]+)'\\|trans/", $source, $matches);
        self::assertNotEmpty($matches[1], "$template translates nothing — has it been rewritten?");

        foreach (['en', 'de'] as $language) {
            $catalogue = self::catalogue($language);

            foreach (array_unique($matches[1]) as $key) {
                self::assertArrayHasKey($key, $catalogue, "$template uses $key, which the $language catalogue lacks.");
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function languages(): iterable
    {
        yield 'en' => ['en'];
        yield 'de' => ['de'];
    }

    #[DataProvider('languages')]
    public function testEveryTemplateRendersWithoutAnUntranslatedKey(string $language): void
    {
        foreach (self::contexts() as $template => $context) {
            $html = self::render($template, $context, $language);

            self::assertStringNotContainsString('mcp_server.', $html, "$template prints an untranslated key in $language.");
            self::assertStringContainsString('id="tl_buttons"', $html);
        }
    }

    /**
     * Contao 6 escapes with Twig's html strategy, which double-encodes. The
     * referer used to arrive ampersand-encoded (getReferer(true)) and would
     * have turned into `&amp;amp;` — a link to a different URL.
     */
    public function testUrlsAreEncodedExactlyOnce(): void
    {
        $html = self::render('be_mcp_status', self::contexts()['be_mcp_status']);

        self::assertStringContainsString('href="/contao?do=netzhirsch_mcp_status&amp;ref=a1b2"', $html);
        self::assertStringContainsString('href="/contao?do=netzhirsch_mcp_status&amp;rt=tok&amp;action=close_pairing"', $html);
        self::assertStringNotContainsString('&amp;amp;', $html);
        self::assertStringContainsString('data-action="contao--scroll-offset#discard"', $html, 'Backend.getScrollOffset() does not exist in Contao 6.');
        self::assertStringNotContainsString('getScrollOffset', $html);
    }

    /**
     * Client names, ids and redirect URIs come from Dynamic Client
     * Registration — i.e. from whoever registered — and land in the backend
     * of an administrator.
     */
    public function testWhatAClientRegisteredIsEscaped(): void
    {
        $html = self::render('be_mcp_status', self::contexts()['be_mcp_status']);

        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; Tom &amp; Jerry', $html);
        self::assertStringContainsString('&lt;b&gt;admin&lt;/b&gt;', $html);
        self::assertStringContainsString('action=revoke_client&amp;client_id=a%20b%26c%3D1%22x"', $html);
        // A JS string inside an HTML attribute: escaped for JS, so a quote in
        // a translation cannot end the string.
        self::assertStringContainsString("return confirm('Revoke\\u0020this\\u0020client", $html);
    }

    public function testStatusShowsWhatTheModuleDecided(): void
    {
        $html = self::render('be_mcp_status', self::contexts()['be_mcp_status'], 'de');

        self::assertStringContainsString('Registrierung offen bis 12:34 (noch 7 Min.)', $html);
        self::assertStringContainsString('action=close_pairing', $html);
        self::assertStringNotContainsString('action=open_pairing', $html);
        self::assertStringContainsString('#7</td>', $html);
        self::assertStringContainsString('2026-10-02 10:00', $html);
        self::assertStringContainsString('(<code>&lt;i&gt;cli&lt;/i&gt;</code>)', $html);
        self::assertStringContainsString('Sicherheitsupdate', $html);
        self::assertStringContainsString('9.9.9', $html);
        self::assertStringContainsString('href="https://example.com/notes"', $html);
    }

    /**
     * Which licence actions the top bar offers, per licence state.
     *
     * @return iterable<string, array{array<string, mixed>, string, list<string>}>
     */
    public static function licenceStates(): iterable
    {
        yield 'trial running' => [['active' => true, 'type' => 'trial'], '', ['subscribe']];
        yield 'subscription running' => [['active' => true, 'type' => 'full'], '', ['manage_billing']];
        yield 'internal licence' => [['active' => true, 'type' => 'full'], 'internal', []];
        yield 'internal licence revoked' => [['active' => false, 'type' => 'full', 'reason' => 'revoked'], 'internal', ['subscribe']];
        yield 'subscription lapsed' => [['active' => false, 'type' => 'full', 'reason' => 'expired'], '', ['subscribe', 'manage_billing']];
        yield 'no licence yet' => [['active' => false, 'type' => '', 'reason' => 'no_token'], '', ['start_trial', 'subscribe']];
    }

    /**
     * @param array<string, mixed> $license
     * @param list<string>         $expected
     */
    #[DataProvider('licenceStates')]
    public function testTheTopBarOffersTheRightLicenceActions(array $license, string $plan, array $expected): void
    {
        $html = self::render('be_mcp_status', [
            ...self::contexts()['be_mcp_status'],
            'license' => [...['reason' => '', 'days_left' => 3, 'in_grace' => false], ...$license],
            'licensePlan' => $plan,
        ]);

        preg_match_all('/action=(start_trial|subscribe|manage_billing)"/', $html, $matches);

        self::assertSame($expected, $matches[1]);
    }

    public function testStatusWithoutOAuthShowsNoClientAdministration(): void
    {
        $context = self::contexts()['be_mcp_status'];
        $html = self::render('be_mcp_status', [...$context, 'config' => [...$context['config'], 'auth_mode' => 'none']]);

        self::assertStringNotContainsString('action=open_pairing', $html);
        self::assertStringNotContainsString('action=close_pairing', $html);
        self::assertStringNotContainsString('action=revoke_client', $html);
        self::assertStringContainsString('<strong class="tl_orange">●</strong>', $html);
    }

    public function testOpenRegistrationIsWarnedAbout(): void
    {
        $context = self::contexts()['be_mcp_status'];
        $html = self::render('be_mcp_status', [
            ...$context,
            'config' => [...$context['config'], 'oauth_registration_mode' => 'open'],
            'pairingActive' => false,
        ]);

        self::assertStringContainsString('Client registration is set to &quot;Open&quot;', $html);
        // No pairing window to open or close: registration is open anyway.
        self::assertStringNotContainsString('action=open_pairing', $html);
        self::assertStringNotContainsString('action=close_pairing', $html);
    }

    public function testActivityEscapesTheLog(): void
    {
        $html = self::render('be_mcp_activity', self::contexts()['be_mcp_activity']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
        self::assertStringContainsString('<span class="tl_green">oauth</span>', $html);
        self::assertStringContainsString('2026-10-02 10:00:00', $html);
    }

    public function testConfigReflectsTheStoredValues(): void
    {
        $html = self::render('be_mcp_config', self::contexts()['be_mcp_config']);

        self::assertStringContainsString('value="m&quot;cp"', $html);
        self::assertStringContainsString('value="321"', $html);
        self::assertStringContainsString('<option value="oauth" selected>', $html);
        self::assertStringContainsString('<option value="restricted" selected>', $html);
        self::assertStringContainsString('<code>claude.ai, claude.com</code>', $html);
        self::assertStringContainsString('value="1" checked>', $html);
        self::assertStringContainsString('name="REQUEST_TOKEN" value="tok"', $html);
    }

    public function testToolsKeepTheirToggleRules(): void
    {
        $html = self::render('be_mcp_tools', self::contexts()['be_mcp_tools']);

        // First group open, the rest collapsed.
        self::assertStringContainsString('<fieldset class="tl_tbox" data-controller="contao--toggle-fieldset"', $html);
        self::assertStringContainsString('<fieldset class="tl_box collapsed" data-controller="contao--toggle-fieldset"', $html);
        // "Select all" only where no protected tool would be flipped with it.
        self::assertStringNotContainsString('id="check_all_discovery"', $html);
        self::assertStringContainsString('id="check_all_page"', $html);
        // Protected tools cannot be unchecked, the others post their name.
        self::assertStringContainsString('id="opt_tool_contao_call" class="tl_checkbox" checked disabled>', $html);
        self::assertStringContainsString('name="tools[]" id="opt_tool_page_get" class="tl_checkbox" value="page_get" checked', $html);
        self::assertMatchesRegularExpression('/value="page_delete" data-action/', $html);
        self::assertStringContainsString('>contao_call <span class="tl_gray"', $html);
        self::assertStringContainsString('>(Extension)</span></label>', $html);
        self::assertStringContainsString('title="Reads &lt;one&gt; page"', $html);
        self::assertStringContainsString('name="tools_rendered_core" value="contao_call,page_get,page_delete"', $html);
    }

    public function testToolsWarnWhenTheRegistryIsMissing(): void
    {
        $html = self::render('be_mcp_tools', [...self::contexts()['be_mcp_tools'], 'toolCatalogue' => null]);

        self::assertStringContainsString('class="tl_error"', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    /**
     * What the modules hand over, one fixture per template.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function contexts(): array
    {
        $base = [
            'config' => [
                'path' => 'm"cp',
                'pagination_limit' => 321,
                'auth_mode' => 'oauth',
                'backend_url' => 'https://example.com',
                'oauth_registration_mode' => 'restricted',
                'cimd_mode' => 'trusted',
                'cimd_trusted_hosts' => ['claude.ai', 'claude.com'],
                'lazy_mode' => true,
                'registration_open_until' => 0,
            ],
            'configDefaults' => [],
            'endpointUrl' => 'https://example.com/mcp',
            'locale' => 'en',
            'bundleVersion' => '1.0.0',
            'messages' => '<p class="tl_confirm">saved</p>',
            'referer' => '/contao?do=netzhirsch_mcp_status&ref=a1b2',
            'requestToken' => 'tok',
            'actionUrl' => '/contao?do=netzhirsch_mcp_status&rt=tok',
        ];

        return [
            'be_mcp_status' => [
                ...$base,
                'oauthIsHttp' => false,
                'pairingActive' => true,
                'pairingUntil' => '12:34',
                'pairingMinutesLeft' => 7,
                'license' => ['active' => true, 'type' => 'trial', 'reason' => '', 'days_left' => 12, 'in_grace' => false],
                'licensePlan' => '',
                'updateNotice' => UpdateNotice::evaluate('9.9.9', '1.0.0', true, 'https://example.com/notes'),
                'oauthClients' => [[
                    'client_id' => 'a b&c=1"x',
                    'name' => '<img src=x onerror=alert(1)> Tom & Jerry',
                    'redirect_uris' => '["https://claude.ai/cb?a=1&b=2"]',
                    'is_confidential' => '1',
                    'authorized_by' => '<b>admin</b>',
                    'created' => '2026-10-02 09:00',
                    'authorized' => '2026-10-02 09:30',
                ]],
                'oauthIats' => [
                    ['id' => 7, 'created' => '2026-10-02 10:00', 'expires' => '2026-10-03 10:00', 'state' => 'used', 'redeemed_by_client_id' => '<i>cli</i>'],
                    ['id' => 8, 'created' => '2026-10-02 10:00', 'expires' => '2026-10-01 10:00', 'state' => 'expired', 'redeemed_by_client_id' => ''],
                    ['id' => 9, 'created' => '2026-10-02 10:00', 'expires' => '2026-10-03 10:00', 'state' => 'active', 'redeemed_by_client_id' => ''],
                ],
            ],
            'be_mcp_config' => [...$base, 'oauthIsHttp' => true],
            'be_mcp_activity' => [
                ...$base,
                'mcpActivity' => [
                    ['tstamp' => 0, 'date' => '2026-10-02 10:00:00', 'source' => 'mcp', 'username' => 'admin', 'text' => '<script>alert("x")</script>'],
                    ['tstamp' => 0, 'date' => '2026-10-02 10:00:01', 'source' => 'mcp_oauth', 'username' => 'bob', 'text' => 'registered'],
                ],
            ],
            'be_mcp_tools' => [
                ...$base,
                'toolsRenderedCore' => 'contao_call,page_get,page_delete',
                'toolsRenderedExt' => 'acme_thing',
                'toolCatalogue' => [
                    [
                        'group' => 'discovery',
                        'label' => 'Discovery',
                        'has_protected' => true,
                        'tools' => [['name' => 'contao_call', 'description' => 'Calls a tool', 'enabled' => true, 'source' => 'core', 'protected' => true]],
                    ],
                    [
                        'group' => 'page',
                        'label' => 'Pages',
                        'has_protected' => false,
                        'tools' => [
                            ['name' => 'page_get', 'description' => 'Reads <one> page', 'enabled' => true, 'source' => 'core', 'protected' => false],
                            ['name' => 'page_delete', 'description' => 'Deletes a page', 'enabled' => false, 'source' => 'core', 'protected' => false],
                            ['name' => 'acme_thing', 'description' => 'From an extension', 'enabled' => false, 'source' => 'extension', 'protected' => false],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function render(string $template, array $context, string $language = 'en'): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(self::TEMPLATES, 'Contao');

        $twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html', 'cache' => false]);
        $twig->addExtension(new TranslationExtension(self::translator($language)));

        return $twig->render('@Contao/'.$template.'.html.twig', $context);
    }

    /**
     * Mirrors Contao's translator for `contao_*` domains: a lookup in the
     * catalogue, vsprintf() when parameters are given, the id when the key
     * is missing.
     */
    private static function translator(string $language): TranslatorInterface
    {
        $catalogue = self::catalogue($language);
        $core = ['MSC.backBT' => 'Back', 'MSC.backBTTitle' => 'Go back', 'MSC.selectAll' => 'Select all'];

        return new class($catalogue, $core, $language) implements TranslatorInterface {
            /**
             * @param array<string, string> $catalogue
             * @param array<string, string> $core
             */
            public function __construct(
                private readonly array $catalogue,
                private readonly array $core,
                private readonly string $language,
            ) {
            }

            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                $translated = match ($domain) {
                    'contao_mcp_server' => $this->catalogue[$id] ?? $id,
                    'contao_default' => $this->core[$id] ?? $id,
                    default => throw new \LogicException("Unexpected translation domain \"$domain\" for $id."),
                };

                return $parameters ? vsprintf($translated, $parameters) : $translated;
            }

            public function getLocale(): string
            {
                return $this->language;
            }
        };
    }

    /**
     * Reads a catalogue the way Contao does: the `id` attribute is the key,
     * the target (or, in the English source file, the source) the value.
     *
     * @return array<string, string>
     */
    private static function catalogue(string $language): array
    {
        $xml = simplexml_load_file(self::ROOT."/contao/languages/$language/mcp_server.xlf");
        self::assertNotFalse($xml);

        $catalogue = [];
        foreach ($xml->xpath('//*[local-name()="trans-unit"]') ?: [] as $unit) {
            $children = $unit->children();
            $catalogue[(string) $unit['id']] = (string) (isset($children->target) ? $children->target : $children->source);
        }

        return $catalogue;
    }
}
