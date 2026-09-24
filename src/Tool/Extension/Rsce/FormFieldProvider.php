<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce;

/**
 * rsce_data on RSCE form fields — see {@see AbstractDataProvider}.
 */
final class FormFieldProvider extends AbstractDataProvider
{
    public function getTable(): string
    {
        return 'tl_form_field';
    }
}
