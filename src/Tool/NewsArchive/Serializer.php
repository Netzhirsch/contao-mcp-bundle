<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\NewsArchive;

use Contao\NewsArchiveModel;

final class Serializer
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(NewsArchiveModel $a): array
    {
        $groupIds = [];
        if ($a->groups) {
            $unserialized = @unserialize((string) $a->groups, ['allowed_classes' => false]);
            if (\is_array($unserialized)) {
                $groupIds = array_values(array_map('intval', $unserialized));
            }
        }

        return [
            'id' => (int) $a->id,
            'title' => (string) $a->title,
            'jumpTo' => (int) $a->jumpTo,
            'protected' => (bool) $a->protected,
            'groups' => $groupIds,
        ];
    }
}
