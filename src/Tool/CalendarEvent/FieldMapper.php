<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\CalendarEvent;

use Contao\CalendarEventsModel;
use Netzhirsch\ContaoMcpBundle\Service\FieldProviderRegistry;

/**
 * Maps MCP input onto a CalendarEventsModel (tl_calendar_events). Same
 * approach as News\FieldMapper: a flat whitelist of known fields per cast type,
 * plus delegation to FieldProviders (changelanguage's languageMain).
 *
 * Date/time semantics (matching Contao's DCA storage):
 *   - startDate / endDate / repeatEnd: stored as unix midnight of the date
 *   - startTime / endTime: stored as unix timestamp of date + time
 *   - addTime toggles whether startTime/endTime are honoured in the FE
 */
final class FieldMapper
{
    public function __construct(
        private readonly FieldProviderRegistry $providers,
    ) {
    }

    /**
     * @var list<string>
     */
    private const STRING_FIELDS = [
        'title', 'alias', 'pageTitle', 'robots', 'description', 'location', 'address',
        'teaser', 'alt', 'imageTitle', 'imageUrl', 'size', 'caption', 'floating',
        'source', 'url', 'linkText', 'canonicalLink', 'cssClass', 'searchIndexer',
        'repeatEach',
    ];

    /**
     * @var list<string>
     */
    private const BOOL_FIELDS = [
        'featured', 'addTime', 'recurring', 'addImage', 'overwriteMeta', 'fullsize',
        'addEnclosure', 'target', 'noComments', 'published',
    ];

    /**
     * @var list<string>
     */
    private const INT_FIELDS_DIRECT = ['jumpTo', 'articleId', 'recurrences'];

    /**
     * @param array<string, mixed> $input
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    public function apply(CalendarEventsModel $event, array $input, bool $detectChanges = true): array
    {
        $changed = [];

        // ─── Validate every input key ───
        $known = self::knownFields();
        $providers = $this->providers->forTable('tl_calendar_events');

        foreach (array_keys($input) as $field) {
            if (\in_array($field, $known, true)) {
                continue;
            }
            $claimedBy = null;
            foreach ($providers as $p) {
                if (\in_array($field, $p->getDeclaredFields(), true)) {
                    $claimedBy = $p;
                    break;
                }
            }
            if ($claimedBy === null) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" is not a known tl_calendar_events field. Known: %s.',
                    $field,
                    implode(', ', $known),
                ));
            }
            if (!$claimedBy->isAvailable()) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" requires the %s extension, which is not installed.',
                    $field,
                    $claimedBy->getRequiredExtension(),
                ));
            }
        }

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
            if (!$detectChanges || (string) $event->$field !== $new) {
                $event->$field = $new;
                $touch($field);
            }
        }

        foreach (self::BOOL_FIELDS as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $new = $input[$field] ? 1 : 0;
            if (!$detectChanges || (int) $event->$field !== $new) {
                $event->$field = $new;
                $touch($field);
            }
        }

        foreach (self::INT_FIELDS_DIRECT as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $new = (int) $input[$field];
            if (!$detectChanges || (int) $event->$field !== $new) {
                $event->$field = $new;
                $touch($field);
            }
        }

        if (\array_key_exists('calendar_id', $input) && $input['calendar_id'] !== null) {
            $new = (int) $input['calendar_id'];
            if (!$detectChanges || (int) $event->pid !== $new) {
                $event->pid = $new;
                $touch('pid');
            }
        }

        if (\array_key_exists('author_id', $input) && $input['author_id'] !== null) {
            $new = (int) $input['author_id'];
            if (!$detectChanges || (int) $event->author !== $new) {
                $event->author = $new;
                $touch('author');
            }
        }

        // startDate / endDate / repeatEnd: midnight unix of given date
        foreach (['startDate', 'endDate', 'repeatEnd'] as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $raw = (string) $input[$field];
            $ts = $raw === '' ? 0 : self::parseDate($raw);
            if (!$detectChanges || (int) $event->$field !== $ts) {
                $event->$field = $ts > 0 ? $ts : null;
                $touch($field);
            }
        }

        // startTime / endTime: combine the given HH:MM:SS with startDate (or today)
        $startDateForTime = isset($input['startDate']) && $input['startDate'] !== '' && $input['startDate'] !== null
            ? (string) $input['startDate']
            : ($event->startDate ? date('Y-m-d', (int) $event->startDate) : date('Y-m-d'));
        foreach (['startTime', 'endTime'] as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $raw = (string) $input[$field];
            $ts = $raw === '' ? 0 : self::parseDateTime($startDateForTime.' '.$raw);
            if (!$detectChanges || (int) $event->$field !== $ts) {
                $event->$field = $ts > 0 ? $ts : null;
                $touch($field);
            }
        }

        // start / stop (publish window, varchar(10) unix)
        foreach (['start', 'stop'] as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $raw = (string) $input[$field];
            $new = $raw === '' ? '' : (string) self::parseDateTime($raw);
            if (!$detectChanges || (string) $event->$field !== $new) {
                $event->$field = $new;
                $touch($field);
            }
        }

        // singleSRC binary(16) UUID
        if (\array_key_exists('singleSRC', $input) && $input['singleSRC'] !== null) {
            $raw = (string) $input['singleSRC'];
            $bin = $raw === '' ? null : self::hexToBin($raw, 'singleSRC');
            if (!$detectChanges || $event->singleSRC !== $bin) {
                $event->singleSRC = $bin;
                $touch('singleSRC');
            }
        }

        // ─── Provider apply ───
        foreach ($providers as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }
            foreach ($provider->apply($event, $input, $detectChanges) as $field) {
                $touch($field);
            }
        }

        return $changed;
    }

    /**
     * @return list<string>
     */
    private static function knownFields(): array
    {
        return array_values(array_unique(array_merge(
            self::STRING_FIELDS,
            self::BOOL_FIELDS,
            self::INT_FIELDS_DIRECT,
            [
                'calendar_id', 'author_id',
                'startDate', 'endDate', 'startTime', 'endTime', 'repeatEnd',
                'start', 'stop', 'singleSRC',
            ],
        )));
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function parseDate(string $iso): int
    {
        $ts = strtotime($iso);
        if ($ts === false) {
            throw new \InvalidArgumentException(sprintf('Invalid date "%s". Use ISO 8601 (YYYY-MM-DD).', $iso));
        }
        return strtotime(date('Y-m-d', $ts).' midnight') ?: $ts;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function parseDateTime(string $iso): int
    {
        $ts = strtotime($iso);
        if ($ts === false) {
            throw new \InvalidArgumentException(sprintf('Invalid datetime "%s".', $iso));
        }
        return $ts;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function hexToBin(string $raw, string $field): string
    {
        $hex = str_replace('-', '', $raw);
        if (\strlen($hex) !== 32 || preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" must be a 32-char hex UUID (got "%s").', $field, $raw));
        }
        return hex2bin($hex);
    }
}
