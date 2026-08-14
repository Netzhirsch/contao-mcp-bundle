<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Form;

use Contao\FormModel;
use Contao\StringUtil;

final class Serializer
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(FormModel $f): array
    {
        return [
            'id' => (int) $f->id,
            'title' => (string) $f->title,
            'alias' => (string) $f->alias,
            'method' => (string) $f->method,
            'tstamp' => (int) $f->tstamp,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function full(FormModel $f): array
    {
        return self::summary($f) + [
            'jump_to' => (int) $f->jumpTo,
            'confirmation' => (string) $f->confirmation,
            'send_via_email' => (bool) $f->sendViaEmail,
            'mailer_transport' => (string) $f->mailerTransport,
            'recipient' => (string) $f->recipient,
            'subject' => (string) $f->subject,
            'format' => (string) $f->format,
            'skip_empty' => (bool) $f->skipEmpty,
            'store_values' => (bool) $f->storeValues,
            'target_table' => (string) $f->targetTable,
            'custom_tpl' => (string) $f->customTpl,
            'novalidate' => (bool) $f->novalidate,
            'attributes' => self::stringList($f->attributes),
            'form_id' => (string) $f->formID,
            'ajax' => (bool) $f->ajax,
            'allow_tags' => (string) $f->allowTags,
        ];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $blob): array
    {
        $list = StringUtil::deserialize($blob, true);

        return array_values(array_filter(
            $list,
            static fn ($v): bool => \is_string($v),
        ));
    }
}
