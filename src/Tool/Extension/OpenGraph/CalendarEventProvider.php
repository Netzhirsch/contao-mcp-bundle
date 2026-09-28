<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\OpenGraph;

/**
 * OpenGraph fields on tl_calendar_events. All behaviour is shared — see
 * {@see AbstractFieldProvider}; only the table differs, and with it the
 * og:types the extension allows here.
 */
final class CalendarEventProvider extends AbstractFieldProvider
{
    public function getTable(): string
    {
        return 'tl_calendar_events';
    }
}
