<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Backend\Module;

use Contao\Input;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\Routing\EndpointPath;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * MCP-Server → Konfiguration: the edit form for var/mcp/config.json
 * (path / pagination / auth / lazy mode). Standard Contao edit-form layout
 * with a sticky submit bar.
 */
class ModuleMcpConfig extends AbstractMcpModule
{
    /**
     * @var string
     */
    protected $strTemplate = 'be_mcp_config';

    protected function compileModule(ContainerInterface $container, McpServerConfigStorage $configStorage, array $config): void
    {
        $this->Template->oauthIsHttp = self::isHttpInsecure($config);
    }

    protected function handleAction(string $action, ContainerInterface $container, McpServerConfigStorage $configStorage): void
    {
        if ($action !== 'save_config') {
            return;
        }

        // The endpoint is matched before Contao's own routing, so a page with
        // this alias would vanish behind it. Checked only when the path
        // changes: a site whose page already shares the default "mcp" keeps
        // being able to save everything else.
        $path = EndpointPath::normalise((string) Input::post('path'));
        if ($path !== '' && $path !== $configStorage->load()['path'] && self::isPageAlias($container, $path)) {
            Message::addError($this->translate('config_save_failed').' '.$this->translate('error_path_page_alias', 'path_page_alias'));
            $this->redirectSelf();
        }

        $input = [
            'path' => (string) Input::post('path'),
            'pagination_limit' => (int) Input::post('pagination_limit'),
            'auth_mode' => (string) Input::post('auth_mode'),
            'backend_url' => (string) Input::post('backend_url'),
            'oauth_registration_mode' => (string) Input::post('oauth_registration_mode'),
            'cimd_mode' => (string) Input::post('cimd_mode'),
            // The allowlist has no form field yet — carry the stored value
            // through, or saving any other setting would empty it and turn
            // 'trusted' into "trust nobody" behind the operator's back.
            'cimd_trusted_hosts' => $configStorage->load()['cimd_trusted_hosts'],
            // Checkbox: hidden field carries '0', checked override sends '1'.
            'lazy_mode' => (bool) Input::post('lazy_mode'),
            // No longer a form field (the production URL is baked into the
            // bundle). Carry the stored value through so a deliberate dev
            // override survives a config save instead of being wiped.
            'license_server_url' => (string) ($configStorage->load()['license_server_url'] ?? ''),
            // Managed by the Tools module / the OAuth pairing button — pass
            // the persisted values through, otherwise every config save would
            // silently reset them behind the operator's back.
            'extension_tools_enabled' => $configStorage->load()['extension_tools_enabled'],
            'disabled_tools' => $configStorage->load()['disabled_tools'],
            'registration_open_until' => $configStorage->load()['registration_open_until'],
        ];
        $result = $configStorage->save($input);

        if ($result['saved']) {
            Message::addConfirmation($this->translate('config_saved'));
        } else {
            $details = [];
            foreach ($result['errors'] as $code) {
                $details[] = $this->translate('error_'.$code, $code);
            }
            Message::addError($this->translate('config_save_failed').' '.implode(', ', $details));
        }

        $this->redirectSelf();
    }

    private static function isPageAlias(ContainerInterface $container, string $alias): bool
    {
        $connection = $container->get('database_connection');

        return $connection instanceof Connection
            && false !== $connection->fetchOne('SELECT id FROM tl_page WHERE alias = ? LIMIT 1', [$alias]);
    }
}
