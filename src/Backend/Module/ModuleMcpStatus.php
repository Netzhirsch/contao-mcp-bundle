<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Backend\Module;

use Contao\BackendUser;
use Contao\Input;
use Contao\Message;
use Contao\StringUtil;
use Contao\System;
use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Netzhirsch\ContaoMcpBundle\License\LicenseGate;
use Netzhirsch\ContaoMcpBundle\License\RenewalClient;
use Netzhirsch\ContaoMcpBundle\OAuth\InitialAccessTokenManager;
use Netzhirsch\ContaoMcpBundle\OAuth\OAuthClientAdministration;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * MCP-Server → Status: the group's landing page. Endpoint + auth state PLUS
 * the whole OAuth administration (pairing window, Initial Access Tokens,
 * registered clients) — operating state and the levers to change it belong
 * on one page, a separate OAuth menu item was one click too many.
 *
 * Global actions (open/close pairing, generate IAT) are #tl_buttons top-bar
 * links — GET + `rt` token, validated by the base class. Client revocation
 * is a per-row operation link.
 */
class ModuleMcpStatus extends AbstractMcpModule
{
    /**
     * @var string
     */
    protected $strTemplate = 'be_mcp_status';

    protected function compileModule(ContainerInterface $container, McpServerConfigStorage $configStorage, array $config): void
    {
        // Stripe checkout/portal return (routed here by BillingReturnListener):
        // show a message + refresh the token on success, then drop the param.
        $billing = (string) Input::get('mcp_billing');
        if ('' !== $billing) {
            $this->handleBillingReturn($billing, $container);
        }

        $this->Template->oauthIsHttp = self::isHttpInsecure($config);

        // License state (trial → paid subscription), always shown so the
        // operator can see whether the tools are currently unlocked — see
        // License\LicenseGate.
        $this->Template->license = $container->get(LicenseGate::class)->state();

        // OAuth admin data only when the gate is actually active — under
        // auth_mode=none there are no clients/IATs to manage.
        if (($config['auth_mode'] ?? 'none') === 'oauth') {
            $this->Template->oauthClients = $container->get(OAuthClientAdministration::class)->listClients();
            $this->Template->oauthIats = $container->get(InitialAccessTokenManager::class)->listAll();
        } else {
            $this->Template->oauthClients = [];
            $this->Template->oauthIats = [];
        }
    }

    protected function handleAction(string $action, ContainerInterface $container, McpServerConfigStorage $configStorage): void
    {
        switch ($action) {
            case 'open_pairing':
                $this->handlePairingWindow($configStorage, true);
                break;
            case 'close_pairing':
                $this->handlePairingWindow($configStorage, false);
                break;
            case 'generate_iat':
                $this->handleGenerateIat($container->get(InitialAccessTokenManager::class));
                break;
            case 'revoke_client':
                $this->handleRevokeClient($container->get(OAuthClientAdministration::class));
                break;
            case 'start_trial':
                $this->handleStartTrial($container->get(RenewalClient::class));
                break;
            case 'subscribe':
                $this->handleBillingRedirect($container->get(RenewalClient::class), 'checkout');
                break;
            case 'manage_billing':
                $this->handleBillingRedirect($container->get(RenewalClient::class), 'portal');
                break;
            default:
        }
    }

    /**
     * Request a trial from the license server. No payment — just unlocks the
     * tools for the trial window. The server rejects a second trial per
     * domain/account (that is the non-restart guarantee).
     */
    private function handleStartTrial(RenewalClient $client): void
    {
        $result = $client->startTrial($this->currentUserEmail());
        if ($result['ok'] ?? false) {
            Message::addConfirmation($this->translate('license_trial_started', 'Trial activated — the MCP tools are unlocked for the trial period.'));
        } else {
            Message::addError(\sprintf($this->translate('license_action_failed', 'License action failed: %s'), (string) ($result['message'] ?? $result['error'] ?? '')));
        }

        $this->redirectSelf();
    }

    /**
     * Fetch a Stripe-hosted URL (Checkout to subscribe, or Customer Portal to
     * manage) and redirect the browser there. Card/SEPA data is entered ONLY on
     * Stripe's page — never in Contao. Only https URLs are followed.
     */
    private function handleBillingRedirect(RenewalClient $client, string $kind): void
    {
        $result = 'checkout' === $kind
            ? $client->checkoutSession($this->currentUserEmail())
            : $client->portalSession();

        $url = (string) ($result['url'] ?? '');
        if (($result['ok'] ?? false) && str_starts_with($url, 'https://')) {
            $this->redirect($url);
        }

        Message::addError(\sprintf($this->translate('license_action_failed', 'License action failed: %s'), (string) ($result['message'] ?? $result['error'] ?? '')));
        $this->redirectSelf();
    }

    /**
     * Handle the Stripe return (`?mcp_billing=success|cancel`). On success, pull
     * the freshly issued paid token so the status flips to the paid plan now
     * (best-effort — SEPA settles asynchronously, the hourly cron catches up).
     * Always redirect to a clean module URL so a refresh doesn't repeat it.
     */
    private function handleBillingReturn(string $billing, ContainerInterface $container): void
    {
        if ('success' === $billing) {
            $container->get(RenewalClient::class)->renew(true);
            Message::addConfirmation($this->translate('billing_success', 'Payment received — your subscription is being activated.'));
        } else {
            Message::addInfo($this->translate('billing_cancel', 'Checkout cancelled — nothing was charged.'));
        }

        $this->redirectSelf();
    }

    private function currentUserEmail(): string
    {
        $user = System::getContainer()->get('security.helper')->getUser();

        // Contao's BackendUser exposes `email` via __get (arrData), so
        // property_exists() would always be false — use instanceof + __get.
        return $user instanceof BackendUser ? (string) ($user->email ?? '') : '';
    }

    /**
     * Opens/closes the client-pairing window: 10 minutes during which the
     * registration endpoint admits ONE anonymous registration despite
     * restricted mode (standard MCP clients cannot send an IAT header).
     * The window also closes itself after the first successful registration
     * — see RegisterController.
     */
    private function handlePairingWindow(McpServerConfigStorage $configStorage, bool $open): void
    {
        $until = $open ? time() + 600 : 0;
        $result = $configStorage->save([...$configStorage->load(), 'registration_open_until' => $until]);

        if (!$result['saved']) {
            Message::addError($this->translate('config_save_failed'));
        } elseif ($open) {
            Message::addConfirmation(\sprintf(
                $this->translate('pairing_opened', 'Client registration is open until %s — connect your MCP client now. The window closes automatically after the first successful registration.'),
                date('H:i', $until),
            ));
        } else {
            Message::addConfirmation($this->translate('pairing_closed', 'Pairing window closed.'));
        }

        $this->redirectSelf();
    }

    private function handleGenerateIat(InitialAccessTokenManager $iat): void
    {
        $user = System::getContainer()->get('security.helper')->getUser();
        $userId = $user instanceof BackendUser ? (int) $user->id : 0;

        $result = $iat->generate($userId, 3600);
        Message::addConfirmation(\sprintf(
            '%s: <code>%s</code>',
            $this->translate('iat_generated', 'New Initial Access Token (copy now — won\'t be shown again)'),
            StringUtil::specialchars($result['plain']),
        ));

        $this->redirectSelf();
    }

    private function handleRevokeClient(OAuthClientAdministration $admin): void
    {
        // Row-operation link (GET, rt-validated by the base class).
        $clientId = (string) (Input::get('client_id') ?? '') ?: (string) Input::post('client_id');
        if ($clientId === '') {
            Message::addError($this->translate('client_revoke_missing_id', 'No client_id given.'));
            $this->redirectSelf();

            return;
        }

        $rows = $admin->revokeClient($clientId);

        if ($rows > 0) {
            Message::addConfirmation(\sprintf($this->translate('client_revoked', 'Client %s revoked.'), $clientId));
        } else {
            Message::addError(\sprintf($this->translate('client_not_found', 'Client %s not found.'), $clientId));
        }

        $this->redirectSelf();
    }
}
