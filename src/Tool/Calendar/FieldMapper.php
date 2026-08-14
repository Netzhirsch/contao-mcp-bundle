<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Calendar;

use Contao\CalendarModel;

/**
 * Maps MCP tool input onto a CalendarModel (tl_calendar). Same shape as
 * NewsArchive's mapper but with the additional comment-block fields.
 *
 * DCA-editable fields (Contao 5 calendar-bundle):
 *   - title (required), jumpTo (required), protected, groups
 *   - allowComments + subpalette (notify, sortOrder, perPage, moderate, bbcode,
 *     requireLogin, disableCaptcha)
 */
final class FieldMapper
{
    /**
     * @var list<string>
     */
    private const STRING_FIELDS = ['title', 'notify', 'sortOrder'];

    /**
     * @var list<string>
     */
    private const BOOL_FIELDS = [
        'protected', 'allowComments', 'moderate', 'bbcode',
        'requireLogin', 'disableCaptcha',
    ];

    /**
     * @var list<string>
     */
    private const INT_FIELDS = ['jumpTo', 'perPage'];

    /**
     * @param array<string, mixed> $input
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    public function apply(CalendarModel $calendar, array $input, bool $detectChanges = true): array
    {
        $changed = [];
        $touch = static function (string $field) use (&$changed): void {
            if (!\in_array($field, $changed, true)) {
                $changed[] = $field;
            }
        };

        foreach (self::STRING_FIELDS as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $new = (string) $input[$field];
            if (!$detectChanges || (string) $calendar->$field !== $new) {
                $calendar->$field = $new;
                $touch($field);
            }
        }

        foreach (self::BOOL_FIELDS as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $new = $input[$field] ? 1 : 0;
            if (!$detectChanges || (int) $calendar->$field !== $new) {
                $calendar->$field = $new;
                $touch($field);
            }
        }

        foreach (self::INT_FIELDS as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $new = (int) $input[$field];
            if (!$detectChanges || (int) $calendar->$field !== $new) {
                $calendar->$field = $new;
                $touch($field);
            }
        }

        if (\array_key_exists('groups', $input) && $input['groups'] !== null) {
            $raw = $input['groups'];
            if (!\is_array($raw)) {
                throw new \InvalidArgumentException("'groups' must be an array of tl_member_group.id integers.");
            }
            $cleaned = array_values(array_map('intval', $raw));
            $new = $cleaned === [] ? '' : serialize($cleaned);
            if (!$detectChanges || (string) $calendar->groups !== $new) {
                $calendar->groups = $new;
                $touch('groups');
            }
        }

        return $changed;
    }
}
