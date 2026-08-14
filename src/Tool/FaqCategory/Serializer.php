<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\FaqCategory;

use Contao\FaqCategoryModel;

final class Serializer
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(FaqCategoryModel $c): array
    {
        return [
            'id' => (int) $c->id,
            'title' => (string) $c->title,
            'headline' => (string) $c->headline,
            'jumpTo' => (int) $c->jumpTo,
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
