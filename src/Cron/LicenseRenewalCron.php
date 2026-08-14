<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Netzhirsch\ContaoMcpBundle\License\LicenseStore;
use Netzhirsch\ContaoMcpBundle\License\RenewalClient;
use Psr\Log\LoggerInterface;

/**
 * Keeps the license token fresh automatically — and is the revocation channel.
 *
 * Why a cron: verification is offline, so a token that is renewed regularly
 * almost always has (nearly) its full lifetime left. That means the license
 * server may be unreachable for a long stretch before the 3-day grace even
 * comes into play — the customer never notices a short outage.
 *
 * The same call is how revocation reaches the install: {@see RenewalClient::renew()}
 * clears the stored token when the server answers `revoked`, so even a
 * long-lived internal license stops working within one throttle window. A mere
 * connectivity failure is absorbed by the grace window instead.
 *
 * Cadence: fires hourly, but the client throttles real server calls to at most
 * one per {@see RenewalClient} window — so an hourly cron stays gentle while
 * keeping the revocation latency short. For low-traffic sites configure a real
 * system cron (`contao:cron`); busy sites also get Contao's web-triggered cron.
 */
#[AsCronJob('hourly')]
final class LicenseRenewalCron
{
    public function __construct(
        private readonly LicenseStore $store,
        private readonly RenewalClient $renewalClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        // Nothing to renew until a trial/subscription token exists. A missing
        // token is the gate's own concern (no_token → denied); the cron must
        // not hit the server for installs that never activated a license.
        if ('' === $this->store->getToken()) {
            return;
        }

        try {
            // Throttled server-side + client-side; renew() clears the token on
            // an explicit 'revoked' and leaves it untouched on any other
            // (non-fatal) outcome, so grace still absorbs real outages.
            $this->renewalClient->renew(false);
        } catch (\Throwable $e) {
            // A cron job must never bubble — a broken renewal must not stall the
            // rest of Contao's cron run. The grace window covers the miss.
            $this->logger->warning('MCP license auto-renew failed.', ['exception' => $e]);
        }
    }
}
