<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Cimd;

/**
 * A metadata document could not be obtained, or could not be trusted.
 *
 * Carries a short machine-readable `reason` for the log. That reason is
 * deliberately NOT handed back to the caller: "connection refused after 4.9s"
 * versus "blocked private address" tells whoever probed us what lives behind
 * the server, which is the very thing the SSRF guard exists to hide. The OAuth
 * error the client sees stays generic; the detail goes to the Contao log where
 * the operator can read it.
 */
final class CimdException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $detail = '',
    ) {
        parent::__construct($detail !== '' ? $reason.': '.$detail : $reason);
    }
}
