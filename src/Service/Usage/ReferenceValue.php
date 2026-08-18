<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service\Usage;

use Contao\StringUtil;

/**
 * Decides whether a stored column value really points at the target.
 *
 * This is the authoritative half of the two-stage match: SQL narrows with a
 * substring search and is allowed to over-fetch, then this says yes or no.
 * Pure functions of (value, encoding, target) — no database, no filesystem —
 * because it is the part that decides whether a deletion gets refused, and
 * that decision has to be testable on its own.
 */
final class ReferenceValue
{
    public static function matches(mixed $value, string $encoding, UsageTarget $target): bool
    {
        if (null === $value || '' === $value) {
            return false;
        }

        return match ($encoding) {
            ReferenceFieldMap::ENC_INT => $target->id > 0 && \is_scalar($value) && (int) $value === $target->id,
            ReferenceFieldMap::ENC_TEMPLATE_NAME => \is_string($value) && \in_array($value, $target->aliases, true),
            ReferenceFieldMap::ENC_UUID => self::matchesUuid($value, $target),
            ReferenceFieldMap::ENC_UUID_LIST => self::matchesUuidList($value, $target),
            ReferenceFieldMap::ENC_INT_LIST => self::matchesIntList($value, $target),
            ReferenceFieldMap::ENC_IMAGE_SIZE => self::matchesImageSize($value, $target),
            ReferenceFieldMap::ENC_MODULE_WIZARD => self::matchesModuleWizard($value, $target),
            default => false,
        };
    }

    private static function matchesUuid(mixed $value, UsageTarget $target): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        foreach ($target->uuids() as $uuid) {
            if ($value === StringUtil::uuidToBin($uuid)) {
                return true;
            }
        }

        return false;
    }

    private static function matchesUuidList(mixed $value, UsageTarget $target): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        $items = self::deserializeList($value);

        foreach ($target->uuids() as $uuid) {
            $binary = StringUtil::uuidToBin($uuid);

            foreach ($items as $item) {
                // Contao writes the raw 16 bytes, but a hand-filled column can
                // hold the readable form — accept both rather than miss one.
                if (\is_string($item) && ($item === $binary || $item === $uuid)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function matchesIntList(mixed $value, UsageTarget $target): bool
    {
        foreach (self::deserializeList($value) as $item) {
            if (\is_scalar($item) && (string) $item === (string) $target->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * `serialize([width, height, sizeIdOrPredefinedName])` — only the third
     * element can be a tl_image_size id. Widths collide with ids constantly,
     * so reading the whole array would block on almost every image.
     */
    private static function matchesImageSize(mixed $value, UsageTarget $target): bool
    {
        $parts = self::deserializeList($value);

        return isset($parts[2]) && \is_scalar($parts[2]) && (string) $parts[2] === (string) $target->id;
    }

    /**
     * `[['mod' => 5, 'col' => 'main', 'enable' => 1], …]`.
     *
     * Reading only `mod` is the whole point: a recursive "is this id in there
     * somewhere" search would see `enable => 1` and report every layout with
     * an enabled module as a user of module id 1.
     */
    private static function matchesModuleWizard(mixed $value, UsageTarget $target): bool
    {
        foreach (self::deserializeList($value) as $entry) {
            if (\is_array($entry) && isset($entry['mod']) && (string) $entry['mod'] === (string) $target->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function deserializeList(mixed $value): array
    {
        if (!\is_string($value)) {
            return [];
        }

        $data = StringUtil::deserialize($value);

        return \is_array($data) ? $data : [];
    }
}
