<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

/**
 * Which submitted keys a FieldMapper could not place.
 *
 * The mapper reports the keys it actually consumed, so this needs no second
 * list of valid columns — one that would drift away from the mapper the first
 * time a field is added, and then either reject something valid or wave
 * through something that is not.
 *
 * Reporting matters because the alternative is silence: a tool that answers
 * "created: true" for a field it never wrote leaves the caller building on a
 * record that did not take the value.
 */
final class SubmittedKeys
{
    /**
     * @param array<string, mixed> $input
     * @param list<string>         $appliedKeys
     *
     * @return list<string>
     */
    public static function ignored(array $input, array $appliedKeys): array
    {
        return array_values(array_diff(array_keys($input), $appliedKeys));
    }

    /**
     * The reportable form: an empty array when everything landed, so it can be
     * merged into a success response without a conditional at every call site.
     *
     * @param list<string> $ignored
     *
     * @return array{ignored_keys?: list<string>}
     */
    public static function report(array $ignored): array
    {
        return $ignored === [] ? [] : ['ignored_keys' => $ignored];
    }

    /**
     * True when NOTHING submitted could be placed — a caller mistake worth an
     * outright error rather than a warning.
     *
     * @param array<string, mixed> $input
     * @param list<string>         $appliedKeys
     */
    public static function noneApplied(array $input, array $appliedKeys): bool
    {
        return $input !== [] && self::ignored($input, $appliedKeys) === array_keys($input);
    }
}