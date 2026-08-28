<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\OAuth\Cimd;

/**
 * The production {@see HostResolverInterface}: the system resolver.
 *
 * `dns_get_record()` is asked for A and AAAA together, because a name that
 * answers both must have BOTH answers checked — a host that resolves to a
 * public IPv4 and a loopback IPv6 is not half-safe, and asking for one family
 * only is how that gets missed.
 *
 * `gethostbynamel()` is the fallback for hosts where `dns_get_record()` is
 * unavailable (some hardened PHP builds disable it) or returns nothing for a
 * CNAME chain. It is IPv4-only, which is enough to decide: no answers at all
 * means the fetch is refused anyway.
 */
final class DnsHostResolver implements HostResolverInterface
{
    public function resolve(string $host): array
    {
        $addresses = [];

        $records = @dns_get_record($host, \DNS_A | \DNS_AAAA);

        if (\is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && \is_string($record['ip'])) {
                    $addresses[] = $record['ip'];
                }

                if (isset($record['ipv6']) && \is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        if ($addresses === []) {
            $fallback = @gethostbynamel($host);

            if (\is_array($fallback)) {
                $addresses = array_values(array_filter($fallback, static fn ($v) => \is_string($v)));
            }
        }

        return array_values(array_unique($addresses));
    }
}
