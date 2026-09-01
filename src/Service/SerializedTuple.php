<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

/**
 * Merges a partial update into one of Contao's small serialised tuples instead
 * of rebuilding it from defaults.
 *
 * Contao stores a headline as `serialize(['value' => …, 'unit' => 'h2'])` and
 * `cssID` / `space` as `serialize([$a, $b])`. Both are single columns holding
 * two things, and both were previously rebuilt from whatever the caller sent:
 *
 *     content_update(id: 6969, fields: {headline: {unit: "h1"}})
 *     → value: ""     ← the headline text, gone
 *     → updated: true, applied: 1, no error
 *
 * The report from the grass-merkur rollout caught that direction. The other one
 * is just as bad and was not noticed, because the test element happened to be
 * an h2 already: sending `{value: "…"}` alone reset the unit to `h2`, silently
 * demoting an h1. Passing a bare string did the same.
 *
 * Which is the real lesson here — a field that holds two things cannot be
 * written from one of them. The caller said what to change; everything they did
 * not mention has to survive.
 *
 * A tuple that is absent or unreadable falls back to the defaults, so creating
 * a record still works exactly as before.
 */
final class SerializedTuple
{
    /**
     * @param mixed  $input       what the caller sent: a string, or {value?, unit?}
     * @param mixed  $current     the stored column value (serialised, or already an array)
     * @param string $defaultUnit unit for a record that has none yet
     *
     * @return array{value: string, unit: string}
     */
    public static function headline(mixed $input, mixed $current, string $defaultUnit = 'h2'): array
    {
        $stored = self::decode($current);

        $currentValue = \is_string($stored['value'] ?? null) ? $stored['value'] : '';
        $currentUnit = \is_string($stored['unit'] ?? null) && $stored['unit'] !== '' ? $stored['unit'] : $defaultUnit;

        // Shorthand: just the text. The heading LEVEL is not something the
        // caller declined to set — it is something they did not mention.
        if (\is_string($input)) {
            return ['value' => $input, 'unit' => $currentUnit];
        }

        if (\is_object($input)) {
            $input = (array) $input;
        }

        if (!\is_array($input)) {
            throw new \InvalidArgumentException('"headline" must be a string or an object {value, unit}.');
        }

        return [
            'value' => \array_key_exists('value', $input) ? (string) $input['value'] : $currentValue,
            'unit' => \array_key_exists('unit', $input) && (string) $input['unit'] !== ''
                ? (string) $input['unit']
                : $currentUnit,
        ];
    }

    /**
     * Positional two-string tuples (`cssID` → [id, class], `space` → [top, bottom]).
     *
     * @param mixed $input   a raw string (stored verbatim, back-compat), or {keyA?, keyB?}
     * @param mixed $current the stored column value
     *
     * @return array{0: string, 1: string}
     */
    public static function pair(mixed $input, mixed $current, string $keyA, string $keyB): array
    {
        $stored = self::decode($current);

        $currentA = (string) ($stored[$keyA] ?? $stored[0] ?? '');
        $currentB = (string) ($stored[$keyB] ?? $stored[1] ?? '');

        if (\is_object($input)) {
            $input = (array) $input;
        }

        if (!\is_array($input)) {
            throw new \InvalidArgumentException(sprintf('"%s"/"%s" must be given as an object.', $keyA, $keyB));
        }

        $a = \array_key_exists($keyA, $input) ? (string) $input[$keyA]
            : (\array_key_exists(0, $input) ? (string) $input[0] : $currentA);
        $b = \array_key_exists($keyB, $input) ? (string) $input[$keyB]
            : (\array_key_exists(1, $input) ? (string) $input[1] : $currentB);

        return [$a, $b];
    }

    /**
     * Reads a stored column into an array. Contao writes these with
     * `serialize()`, but a value that has already been unserialised elsewhere
     * (or was never set) must not blow up here.
     *
     * @return array<string|int, mixed>
     */
    private static function decode(mixed $current): array
    {
        if (\is_array($current)) {
            return $current;
        }

        if (!\is_string($current) || $current === '') {
            return [];
        }

        // Untrusted only in the sense that it is whatever is in the column;
        // allowed_classes:false keeps a crafted payload from instantiating
        // anything on the way in.
        $decoded = @unserialize($current, ['allowed_classes' => false]);

        return \is_array($decoded) ? $decoded : [];
    }
}
