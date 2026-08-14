<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Backend\Module;

use Composer\InstalledVersions;
use Contao\BackendModule;
use Contao\BackendUser;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\Environment;
use Contao\Input;
use Contao\Message;
use Contao\StringUtil;
use Contao\System;
use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * Shared base for the "MCP-Server" backend menu group. Each fold-out panel
 * of the former monolithic do=mcp_server module is its own menu item now
 * (status / config / oauth / activity / tools / docs) — one subclass + one
 * template each, registered in contao/config/config.php under
 * $GLOBALS['BE_MOD']['mcp'].
 *
 * Side benefit of the split: module access is grantable per item via the
 * normal tl_user/tl_user_group module checkboxes (e.g. docs-only access).
 *
 * Mutating actions run before rendering and always end in a redirect.
 * POSTs are CSRF-protected by Contao's kernel (REQUEST_TOKEN); GET actions
 * (the top-bar buttons) are validated here against the `rt` query token —
 * a state-changing GET without a valid token is ignored.
 *
 * Collaborators come from the container because Contao instantiates
 * BackendModule subclasses itself (no constructor injection).
 */
abstract class AbstractMcpModule extends BackendModule
{
    protected function compile()
    {
        System::loadLanguageFile('mcp_server');

        $container = System::getContainer();

        // Admin-only. These panels are server administration, not editing:
        // they flip `auth_mode` (which switches the entire per-user permission
        // layer into trusted mode), hand out OAuth registration (IAT / pairing
        // window), revoke clients, toggle the exposed tool surface and start
        // paid subscriptions. Contao gates its comparable modules (settings,
        // maintenance) the same way. Module rights alone are not enough here:
        // granting one of these to a non-admin would otherwise be a way around
        // the MCP permission parity.
        if ($this->requiresAdmin() && !$container->get('security.helper')->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('The MCP-Server backend modules are restricted to Contao administrators.');
        }

        /** @var McpServerConfigStorage $configStorage */
        $configStorage = $container->get(McpServerConfigStorage::class);

        $action = $this->resolveAction($container);
        if ($action !== '') {
            $this->handleAction($action, $container, $configStorage);
        }

        $config = $configStorage->load();

        $this->Template->config = $config;
        $this->Template->configDefaults = $configStorage->defaults();
        $this->Template->endpointUrl = rtrim((string) ($config['backend_url'] ?? ''), '/').'/'.ltrim((string) $config['path'], '/');
        $this->Template->locale = $this->resolveLocale();
        $this->Template->bundleVersion = (string) (InstalledVersions::isInstalled('netzhirsch/contao-mcp-bundle')
            ? InstalledVersions::getPrettyVersion('netzhirsch/contao-mcp-bundle')
            : 'dev');
        $this->Template->messages = Message::generate();
        $this->Template->referer = $this->getReferer(true);
        $this->Template->backTitle = StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['backBTTitle'] ?? '');
        $this->Template->backLabel = $GLOBALS['TL_LANG']['MSC']['backBT'] ?? 'Back';
        $this->Template->requestToken = $container->get('contao.csrf.token_manager')->getDefaultTokenValue();
        $this->Template->actionUrl = $this->selfUrl();

        $this->compileModule($container, $configStorage, $config);
    }

    /**
     * Module-specific template data. $config is the freshly loaded
     * configuration (post-action).
     *
     * @param array<string, mixed> $config
     */
    abstract protected function compileModule(ContainerInterface $container, McpServerConfigStorage $configStorage, array $config): void;

    /**
     * Module-specific action dispatch. Default: no actions.
     */
    protected function handleAction(string $action, ContainerInterface $container, McpServerConfigStorage $configStorage): void
    {
    }

    /**
     * Whether this panel is administrators-only. Default yes — see compile().
     * A subclass may relax this if it is genuinely read-only and harmless
     * (the module still needs the normal tl_user/tl_user_group module right).
     */
    protected function requiresAdmin(): bool
    {
        return true;
    }

    /**
     * POST actions arrive kernel-CSRF-validated (REQUEST_TOKEN). GET actions
     * (top-bar buttons are plain links) carry the token as `rt` and are
     * validated here exactly the way Contao core validates its own backend
     * operation links (Backend.php / DC_Table.php):
     * `isTokenValid(new CsrfToken(<token-name>, rt))`.
     *
     * Crucial: Contao's CSRF tokens are RANDOMISED per generation — every
     * backend link gets a different `rt` string, all valid for the same
     * token name. A naive `rt === getDefaultTokenValue()` string-compare
     * therefore almost always fails (two different masked values) and the
     * action would be dropped silently. Use isTokenValid(), never ===.
     */
    private function resolveAction(ContainerInterface $container): string
    {
        if ((string) Input::post('FORM_SUBMIT') !== '') {
            return (string) Input::post('action');
        }

        $action = (string) Input::get('action');
        if ($action === '') {
            return '';
        }

        $rt = Input::get('rt');
        $tokenName = (string) $container->getParameter('contao.csrf_token_name');
        if ($rt === null || !$container->get('contao.csrf.token_manager')->isTokenValid(new CsrfToken($tokenName, (string) $rt))) {
            return '';
        }

        return $action;
    }

    protected function redirectSelf(): void
    {
        throw new ResponseException(new RedirectResponse($this->selfUrl()));
    }

    protected function selfUrl(): string
    {
        return Environment::get('path').'?do='.((string) Input::get('do')).'&rt='.System::getContainer()->get('contao.csrf.token_manager')->getDefaultTokenValue();
    }

    protected function resolveLocale(): string
    {
        // The Backend user's stored language wins. Fallback to Request locale.
        $user = System::getContainer()->get('security.helper')->getUser();
        // BackendUser exposes `language` via __get (arrData) — property_exists()
        // would always be false, so check instanceof and read the magic prop.
        $userLang = ($user instanceof BackendUser && \is_string($user->language ?? null) && '' !== $user->language)
            ? $user->language
            : null;

        $locale = $userLang ?? (System::getContainer()->get('request_stack')->getCurrentRequest()?->getLocale() ?? 'en');
        $short = strtolower(substr($locale, 0, 2));

        return \in_array($short, ['de', 'en'], true) ? $short : 'en';
    }

    protected function translate(string $key, string $fallback = ''): string
    {
        $lang = $GLOBALS['TL_LANG']['mcp_server'] ?? [];

        return (string) ($lang[$key] ?? ($fallback !== '' ? $fallback : $key));
    }

    /**
     * Returns true when auth_mode=oauth but the effective base URL is bare
     * http:// outside of localhost — that's a "tokens travel in clear text"
     * situation worth warning about.
     *
     * @param array<string, mixed> $config
     */
    protected static function isHttpInsecure(array $config): bool
    {
        if (($config['auth_mode'] ?? 'none') !== 'oauth') {
            return false;
        }
        $backendUrl = (string) ($config['backend_url'] ?? '');
        if ($backendUrl === '') {
            return true;
        }
        $parts = parse_url($backendUrl);
        if ($parts === false) {
            return true;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme === 'https') {
            return false;
        }
        // http on loopback is acceptable for dev setups.
        return !\in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }
}
