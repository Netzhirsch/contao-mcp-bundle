<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\UrlRewrite;

use Contao\StringUtil;

/**
 * Converts a raw tl_url_rewrite row into the flat API shape the MCP tool exposes.
 *
 * Blob fields stored by Contao's listWizard / keyValueWizard are decoded into
 * plain JSON-friendly shapes (list<string> / dict<string,string>).
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
        return [
            'id' => (int) ($row['id'] ?? 0),
            'tstamp' => (int) ($row['tstamp'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'priority' => (int) ($row['priority'] ?? 0),
            // DCA stores inverted "inactive"; expose the positive "active" + raw inactive for clarity.
            'inactive' => (bool) ($row['inactive'] ?? false),
            'active' => !(bool) ($row['inactive'] ?? false),
            'comment' => (string) ($row['comment'] ?? ''),
            'requestHosts' => self::decodeList($row['requestHosts'] ?? null),
            'requestPath' => (string) ($row['requestPath'] ?? ''),
            'requestRequirements' => self::decodeKeyValue($row['requestRequirements'] ?? null),
            'requestCondition' => self::nullableString($row['requestCondition'] ?? null),
            'responseCode' => (int) ($row['responseCode'] ?? 301),
            'conditionalResponseUri' => self::decodeKeyValue($row['conditionalResponseUri'] ?? null),
            'responseUri' => self::nullableString($row['responseUri'] ?? null),
            'keepQueryParams' => (bool) ($row['keepQueryParams'] ?? false),
        ];
    }

    /**
     * @return list<string>
     */
    private static function decodeList(mixed $value): array
    {
        $arr = StringUtil::deserialize($value, true);
        if (!\is_array($arr)) {
            return [];
        }

        $out = [];
        foreach ($arr as $row) {
            if (!\is_string($row) || $row === '') {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * keyValueWizard stores [['key' => 'foo', 'value' => 'bar'], ...] — flatten to {foo: bar}.
     *
     * Returns a non-empty assoc array, or a `stdClass` for empty results so
     * `json_encode` produces `{}` instead of `[]`. Otherwise the LLM may
     * interpret an empty value as a JSON list and round-trip it back as a list,
     * which we then have to reject in the FieldMapper.
     *
     * @return array<string, string>|\stdClass
     */
    private static function decodeKeyValue(mixed $value): array|\stdClass
    {
        $arr = StringUtil::deserialize($value, true);
        if (!\is_array($arr)) {
            return new \stdClass();
        }

        $out = [];
        foreach ($arr as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? '');
            $val = (string) ($row['value'] ?? '');
            if ($key === '') {
                continue;
            }
            $out[$key] = $val;
        }

        return $out === [] ? new \stdClass() : $out;
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
