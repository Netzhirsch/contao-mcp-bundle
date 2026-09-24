<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce;

/**
 * rsce_data on RSCE frontend modules — see {@see AbstractDataProvider}.
 */
final class ModuleProvider extends AbstractDataProvider
{
    public function getTable(): string
    {
        return 'tl_module';
    }
}
