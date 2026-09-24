<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce;

/**
 * rsce_data on RSCE content elements — see {@see AbstractDataProvider}.
 */
final class ContentProvider extends AbstractDataProvider
{
    public function getTable(): string
    {
        return 'tl_content';
    }
}
