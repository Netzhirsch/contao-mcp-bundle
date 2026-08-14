<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\Leads;

/**
 * Shapes raw tl_lead / tl_lead_data rows into the flat, JSON-friendly API the
 * MCP tools expose.
 *
 * post_data (the raw serialized POST blob) is intentionally NOT decoded: the
 * normalised field/value/label rows in tl_lead_data are the canonical, clean
 * representation of a submission. We only flag whether a raw blob is present.
 */
final class Serializer
{
    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function summary(array $row): array
    {
        $post = $row['post_data'] ?? null;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'tstamp' => (int) ($row['tstamp'] ?? 0),
            'created' => (int) ($row['created'] ?? 0),
            'form_id' => (int) ($row['form_id'] ?? 0),
            'form_title' => self::nullableString($row['form_title'] ?? null),
            // The "master" form that owns the lead store. Equals form_id unless
            // the submitted form funnels its leads into another form's store.
            'main_id' => (int) ($row['main_id'] ?? 0),
            'language' => (string) ($row['language'] ?? ''),
            'member_id' => (int) ($row['member_id'] ?? 0),
            'has_post_data' => \is_string($post) && $post !== '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{field_id: int, name: string, label: string, value: string|null}
     */
    public static function dataRow(array $row): array
    {
        $value = $row['value'] ?? null;

        return [
            'field_id' => (int) ($row['field_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'value' => $value === null ? null : (string) $value,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = (string) $value;

        return $s === '' ? null : $s;
    }
}
