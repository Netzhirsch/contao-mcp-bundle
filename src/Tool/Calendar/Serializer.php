<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Calendar;

use Contao\CalendarModel;

final class Serializer
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(CalendarModel $c): array
    {
        $groupIds = [];
        if ($c->groups) {
            $unserialized = @unserialize((string) $c->groups, ['allowed_classes' => false]);
            if (\is_array($unserialized)) {
                $groupIds = array_values(array_map('intval', $unserialized));
            }
        }

        return [
            'id' => (int) $c->id,
            'title' => (string) $c->title,
            'jumpTo' => (int) $c->jumpTo,
            'protected' => (bool) $c->protected,
            'groups' => $groupIds,
            // comments
            'allowComments' => (bool) $c->allowComments,
            'notify' => (string) $c->notify,
            'sortOrder' => (string) $c->sortOrder,
            'perPage' => (int) $c->perPage,
            'moderate' => (bool) $c->moderate,
            'bbcode' => (bool) $c->bbcode,
            'requireLogin' => (bool) $c->requireLogin,
            'disableCaptcha' => (bool) $c->disableCaptcha,
        ];
    }
}
