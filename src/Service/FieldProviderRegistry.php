<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Netzhirsch\ContaoMcpBundle\Tool\Contract\FieldProvider;

/**
 * Collects every FieldProvider tagged `netzhirsch.field_provider` and exposes
 * convenient filters per table. Availability is *not* pre-filtered here so that
 * downstream code can still emit "extension required" messages for fields claimed by
 * an installed-but-disabled provider.
 */
final class FieldProviderRegistry
{
    /**
     * @var list<FieldProvider>
     */
    private array $providers;

    /**
     * @param iterable<FieldProvider> $providers
     */
    public function __construct(iterable $providers)
    {
        $this->providers = [];
        foreach ($providers as $p) {
            $this->providers[] = $p;
        }
    }

    /**
     * @return list<FieldProvider>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * @return list<FieldProvider>
     */
    public function forTable(string $table): array
    {
        $list = [];
        foreach ($this->providers as $p) {
            if ($p->getTable() === $table) {
                $list[] = $p;
            }
        }

        return $list;
    }

    /**
     * @return list<FieldProvider>
     */
    public function availableForTable(string $table): array
    {
        $list = [];
        foreach ($this->forTable($table) as $p) {
            if ($p->isAvailable()) {
                $list[] = $p;
            }
        }

        return $list;
    }
}
