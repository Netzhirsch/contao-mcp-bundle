<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Module;

use Contao\ModuleModel;
use Contao\StringUtil;
use Contao\Validator;

/**
 * Renders ModuleModel rows. Headline tuple + serialised int/string lists are
 * decoded so the LLM gets clean JSON.
 */
final class Serializer
{
    /**
     * Short row for list views.
     *
     * @return array<string, mixed>
     */
    public static function summary(ModuleModel $m): array
    {
        return [
            'id' => (int) $m->id,
            'theme_id' => (int) $m->pid,
            'name' => (string) $m->name,
            'type' => (string) $m->type,
            'tstamp' => (int) $m->tstamp,
        ];
    }

    /**
     * Full payload: every column on the row, with serialised blobs decoded.
     * Walks the model's `row()` so we cover bundle-contributed columns too.
     *
     * @return array<string, mixed>
     */
    public static function full(ModuleModel $m): array
    {
        $out = [];
        foreach ($m->row() as $field => $value) {
            $out[$field] = self::decode($field, $value);
        }

        return $out;
    }

    private static function decode(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if ($field === 'headline') {
            $arr = StringUtil::deserialize($value, true);
            if (isset($arr['value'])) {
                return ['value' => (string) $arr['value'], 'unit' => (string) ($arr['unit'] ?? 'h2')];
            }

            return $value;
        }

        // Binary UUIDs (fileTree columns) are not valid UTF-8 and would break the
        // JSON response — hand back the readable string UUID instead.
        if (\is_string($value) && Validator::isBinaryUuid($value)) {
            return StringUtil::binToUuid($value);
        }

        // Anything that looks like a PHP-serialized blob → decode.
        if (\is_string($value) && (str_starts_with($value, 'a:') || str_starts_with($value, 's:'))) {
            $decoded = StringUtil::deserialize($value, true);

            return self::readableUuids($decoded);
        }

        return $value;
    }

    /**
     * Maps binary UUIDs inside a decoded blob (multiple fileTree) to string UUIDs.
     */
    private static function readableUuids(mixed $decoded): mixed
    {
        if (!\is_array($decoded)) {
            return $decoded;
        }

        foreach ($decoded as $key => $item) {
            if (\is_string($item) && Validator::isBinaryUuid($item)) {
                $decoded[$key] = StringUtil::binToUuid($item);
            }
        }

        return $decoded;
    }
}
