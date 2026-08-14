<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\FormField;

use Contao\FormFieldModel;
use Contao\StringUtil;

final class Serializer
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(FormFieldModel $f): array
    {
        return [
            'id' => (int) $f->id,
            'form_id' => (int) $f->pid,
            'sorting' => (int) $f->sorting,
            'type' => (string) $f->type,
            'name' => (string) $f->name,
            'label' => (string) $f->label,
            'mandatory' => (bool) $f->mandatory,
            'active' => !(bool) $f->invisible,
            'tstamp' => (int) $f->tstamp,
        ];
    }

    /**
     * Full payload: walk the model row and decode serialized blobs.
     *
     * @return array<string, mixed>
     */
    public static function full(FormFieldModel $f): array
    {
        $out = [];
        foreach ($f->row() as $field => $value) {
            // `invisible` (DB column) → `active` (positive form, exposed in summary).
            if ($field === 'invisible') {
                continue;
            }
            $out[$field] = self::decode($field, $value);
        }
        $out['active'] = !(bool) $f->invisible;

        return $out;
    }

    private static function decode(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // `options` is a serialised list of {value, label, default, group}.
        if ($field === 'options' && \is_string($value)) {
            $decoded = StringUtil::deserialize($value, true);
            return $decoded;
        }

        if (\is_string($value) && (str_starts_with($value, 'a:') || str_starts_with($value, 's:'))) {
            return StringUtil::deserialize($value, true);
        }

        return $value;
    }
}
