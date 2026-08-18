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
use Netzhirsch\ContaoMcpBundle\License\LicenseStore;
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
     * How long the client-pairing window stays open. Ten minutes turned out
     * to be tight: the admin opens it in the Backend, switches to the client,
     * and a first attempt that fails for an unrelated reason already eats the
     * window. Fifteen is still short enough that an unattended Backend does
     * not leave registration open by accident.
     */
    private const PAIRING_WINDOW_SECONDS = 900;

    /**
     * @var string
     */
    protected $strTemplate = 'be_mcp_status';

    /**
     * How often the Stripe return polls /renew before falling back to "will be
     * activated automatically" (one attempt per second).
     */
    private const BILLING_RETURN_ATTEMPTS = 3;

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
        // Internal licenses renew indefinitely — showing "35 days left" (the
        // token lifetime) reads like an expiry date and confuses operators.
        $this->Template->licensePlan = $container->get(LicenseStore::class)->getPlan();

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
                // No Stripe customer exists for an internal licence, so the
                // portal call could only fail. The button is hidden for that
                // case — this guards a hand-crafted link.
                if ('internal' === $container->get(LicenseStore::class)->getPlan()) {
                    Message::addInfo($this->translate('license_internal_no_billing', 'This is an internal license issued by Netzhirsch — there is no subscription to manage. It renews automatically.'));
                    $this->redirectSelf();
                }
                $this->handleBillingRedirect($container->get(RenewalClient::class), 'portal');
                break;
            default:
        }
    }

    /**
     * Pull an entitlement the server already holds for this domain (an internal
     * license issued by Netzhirsch, or a subscription that is already paid).
     *
     * This is what makes both buttons "just work": no token copying, no waiting
     * for the hourly cron, and no pointless Stripe checkout for an instance that
     * is licensed already.
     *
     * @param bool $paidOnly only treat a PAID/internal entitlement as "handled".
     *                       The subscribe button passes true: while merely a
     *                       trial is running, the customer wants to buy, so the
     *                       Stripe checkout must still open.
     *
     * @return bool true when a token was fetched and stored
     */
    private function claimExistingLicense(RenewalClient $client, bool $paidOnly = false): bool
    {
        $result = $client->renew(true, RenewalClient::INTERACTIVE_TIMEOUT_SECONDS);
        if ($result['ok'] ?? false) {
            // A trial is not an entitlement one would "already have" when
            // clicking Subscribe — fall through to the checkout in that case.
            if ($paidOnly && 'trial' === ($result['type'] ?? '')) {
                return false;
            }

            Message::addConfirmation($this->translate('license_claimed', 'License activated — the MCP tools are unlocked.'));

            return true;
        }

        // A revoked license must not silently lead into a new checkout.
        if ('revoked' === ($result['error'] ?? '')) {
            Message::addError($this->translate('license_revoked_notice', 'This license has been revoked. Please contact Netzhirsch.'));

            return true; // handled — caller must not continue
        }

        // The license for this domain is already bound to a different
        // installation. Starting a trial or a second checkout here would be
        // wrong (double charge / burnt trial) — tell the operator instead.
        if ('instance_mismatch' === ($result['error'] ?? '')) {
            Message::addError($this->translate('license_instance_mismatch', 'A license for this domain is already bound to another installation. Restore var/mcp/license.json from that installation, or contact Netzhirsch to release it.'));

            return true; // handled — caller must not continue
        }

        return false;
    }

    /**
     * Request a trial from the license server. No payment — just unlocks the
     * tools for the trial window. The server rejects a second trial per
     * domain/account (that is the non-restart guarantee).
     */
    private function handleStartTrial(RenewalClient $client): void
    {
        // Already entitled (e.g. an internal license)? Then don't burn a trial.
        if ($this->claimExistingLicense($client)) {
            $this->redirectSelf();
        }

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
        // Subscribing while the server already holds an entitlement for this
        // domain (internal license, or a subscription that is already paid)
        // would charge twice — claim the existing license instead.
        if ('checkout' === $kind && $this->claimExistingLicense($client, true)) {
            $this->redirectSelf();
        }

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
        if ('success' !== $billing) {
            Message::addInfo($this->translate('billing_cancel', 'Checkout cancelled — nothing was charged.'));
            $this->redirectSelf();

            return;
        }

        // Racing Stripe's webhook: the browser is back before the payment event
        // has necessarily been processed. Retry briefly so the common (card)
        // case activates immediately instead of waiting for the hourly cron.
        $client = $container->get(RenewalClient::class);
        for ($attempt = 0; $attempt < self::BILLING_RETURN_ATTEMPTS; ++$attempt) {
            $result = $client->renew(true, RenewalClient::INTERACTIVE_TIMEOUT_SECONDS);
            if ($result['ok'] ?? false) {
                Message::addConfirmation($this->translate('billing_activated', 'Payment received — your subscription is active and the MCP tools are unlocked.'));
                $this->redirectSelf();

                return;
            }

            // Definitive answers — retrying cannot change them, and reporting
            // "is being activated" afterwards would be plainly wrong.
            $error = (string) ($result['error'] ?? '');
            if ('revoked' === $error) {
                Message::addError($this->translate('license_revoked_notice', 'This license has been revoked. Please contact Netzhirsch.'));
                $this->redirectSelf();

                return;
            }
            if ('instance_mismatch' === $error) {
                Message::addError($this->translate('license_instance_mismatch', 'A license for this domain is already bound to another installation. Restore var/mcp/license.json from that installation, or contact Netzhirsch to release it.'));
                $this->redirectSelf();

                return;
            }

            if ($attempt < self::BILLING_RETURN_ATTEMPTS - 1) {
                sleep(1);
            }
        }

        // Not activated yet — normal for SEPA (settles asynchronously). The
        // hourly cron picks it up as soon as the payment is confirmed.
        Message::addInfo($this->translate('billing_success', 'Payment received — your subscription is being activated. This can take a moment with SEPA; the licence unlocks automatically.'));
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
     * Opens/closes the client-pairing window: 15 minutes during which the
     * registration endpoint admits anonymous registrations despite
     * restricted mode (standard MCP clients cannot send an IAT header, so
     * this window — not the IAT — is how a normal client gets paired).
     *
     * It stays open for the full duration: closing after the first
     * successful registration meant a retrying client hit a locked door
     * mid-flow and the admin had to reopen it for every attempt.
     */
    private function handlePairingWindow(McpServerConfigStorage $configStorage, bool $open): void
    {
        $until = $open ? time() + self::PAIRING_WINDOW_SECONDS : 0;
        $result = $configStorage->save([...$configStorage->load(), 'registration_open_until' => $until]);

        if (!$result['saved']) {
            Message::addError($this->translate('config_save_failed'));
        } elseif ($open) {
            Message::addConfirmation(\sprintf(
                $this->translate('pairing_opened', 'Client registration is open until %s — connect your MCP client now. This is the button Claude and other standard clients need; an Initial Access Token only works for scripts that can send an Authorization header.'),
                date('H:i', $until),
            ));
        } else {
            Message::addConfirmation($this->translate('pairing_closed', 'Pairing window closed.'));
        }

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
