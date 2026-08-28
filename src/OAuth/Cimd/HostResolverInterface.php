<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Cimd;

/**
 * Looks up the addresses a hostname currently resolves to.
 *
 * This exists as a seam so the SSRF decision can be tested against crafted
 * answer sets — "one public and one private address", "only IPv6", "an
 * IPv4-mapped loopback" — instead of against whatever the test machine's
 * resolver happens to say today. Those are the cases that matter, and none of
 * them are reachable through real DNS in a test.
 *
 * The security decision itself does NOT live here. This returns addresses;
 * {@see SafeUrlFetcher} decides whether they may be connected to. A resolver
 * implementation cannot weaken the guard by returning something unexpected.
 */
interface HostResolverInterface
{
    /**
     * @return list<string> every A/AAAA answer, empty when the name does not resolve
     */
    public function resolve(string $host): array;
}
