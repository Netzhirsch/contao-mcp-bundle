<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\License;

use Netzhirsch\ContaoMcpBundle\Backend\McpServerConfigStorage;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Decides whether the MCP tool layer is licensed right now.
 *
 * Model: trial → paid subscription, all-or-nothing (no free tier). A valid
 * vendor-signed token (trial or full) unlocks every tool; without one, tool
 * calls are refused (core Contao is never touched — the site keeps running).
 *
 * Enforcement is UNCONDITIONAL (single edition): every install runs the gate.
 * Netzhirsch's own hosted sites are not a separate build — they receive a
 * long-lived "internal" token from the license server, so the difference
 * between a paying customer and an internal instance lives entirely in the
 * token, not in the code. Enforcement is deliberately NOT a config.json field
 * or an env var (both customer-editable → a one-line bypass); the only bypass
 * left is patching the source, which breaks on every `composer update`. The
 * real protection is the signature (no valid token without the vendor secret
 * key) plus update-gating via the paid Composer channel.
 */
final class LicenseGate
{
    /** Extra tolerance past a token's expiry, to absorb renewal outages (seconds). */
    private const GRACE_SECONDS = 3 * 86400;

    /** Tools reachable even without a license (health probe stays green). */
    private const ALWAYS_ALLOWED = ['ping'];

    public function __construct(
        private readonly LicenseToken $token,
        private readonly LicenseStore $store,
        private readonly McpServerConfigStorage $config,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Current license state, independent of the enforcement switch (so the
     * Backend can always show it).
     *
     * @return array{active: bool, type: string, reason: string, expires_at: int, days_left: int, in_grace: bool}
     */
    public function state(): array
    {
        $storedToken = $this->store->getToken();
        $host = LicenseToken::resolveDomain(
            (string) ($this->config->load()['backend_url'] ?? ''),
            $this->requestStack->getCurrentRequest()?->getHost(),
        );

        // CLI/cron have no request host. Without a configured backend_url the
        // host would be '' and every status/renew would read as `wrong_domain`.
        // Fall back to the token's own domain THERE ONLY — the enforcement path
        // (/mcp) always has a real request host, so the domain binding that
        // matters is untouched.
        if ('' === $host) {
            $host = LicenseToken::peekDomain($storedToken);
        }

        $result = $this->token->verify($storedToken, $host, $this->store->getHwm());
        $this->store->bumpHwm($result['now_ref']);

        $now = $result['now_ref'];
        $exp = $result['expires_at'];
        $inGrace = false;
        $active = $result['valid'];

        // Grace: a just-expired token still counts as active for GRACE_SECONDS,
        // so a short renewal outage doesn't lock a paying customer out.
        if (!$active && 'expired' === $result['reason'] && $now <= $exp + self::GRACE_SECONDS) {
            $active = true;
            $inGrace = true;
        }

        return [
            'active' => $active,
            'type' => $result['type'],
            'reason' => $result['reason'],
            'expires_at' => $exp,
            // Never negative: past expiry (i.e. inside the grace window) "…-1 days
            // left" would be nonsense in the backend and the status command.
            'days_left' => $exp > 0 ? max(0, (int) ceil(($exp - $now) / 86400)) : 0,
            'in_grace' => $inGrace,
        ];
    }

    /**
     * Permission-style denial for a single `tools/call`, or null when the call
     * may proceed.
     *
     * @return array{error: string, message: string, state: string, expires_at: int}|null
     */
    public function denialForTool(string $toolName): ?array
    {
        if (\in_array($toolName, self::ALWAYS_ALLOWED, true)) {
            return null;
        }

        $state = $this->state();
        if ($state['active']) {
            return null;
        }

        return [
            'error' => 'license_inactive',
            'message' => $this->messageFor($state['reason']),
            'state' => $state['reason'],
            'expires_at' => $state['expires_at'],
        ];
    }

    private function messageFor(string $reason): string
    {
        return match ($reason) {
            'no_token' => 'No license installed. Start a trial or activate a license to use the Contao MCP server.',
            'expired' => 'The license/trial has expired. Renew your subscription to continue using the MCP tools.',
            'wrong_domain' => 'This license is bound to a different domain. Request a license for this host.',
            'bad_signature', 'malformed' => 'The installed license is invalid. Re-activate a valid license.',
            'clock_tampered' => 'The system clock predates the license issue date — cannot validate the license.',
            'sodium_unavailable' => 'PHP ext-sodium is required to validate the license but is not available.',
            default => 'No active license for the Contao MCP server.',
        };
    }
}
