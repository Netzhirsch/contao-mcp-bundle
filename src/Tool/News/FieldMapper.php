<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\News;

use Contao\NewsModel;
use Netzhirsch\ContaoMcpBundle\Service\FieldProviderRegistry;

/**
 * Central mapping layer between MCP tool input (already JSON-validated by php-mcp's auto schema)
 * and a NewsModel instance. Keeps the News\Tool facade thin and ensures consistent type
 * handling for all DCA-editable fields of tl_news.
 *
 * Field grouping follows the Contao 5.7 tl_news DCA palettes:
 *   - title:     headline, featured, alias, author (via author_id)
 *   - date:      date, time             (stored as combined unix timestamp in both columns,
 *                                        matching the DCA onsubmit_callback tl_news::adjustTime)
 *   - source:    source, jumpTo, articleId, url, target, linkText, canonicalLink
 *   - SEO:       pageTitle, robots, description
 *   - teaser:    subheadline, teaser
 *   - image:     addImage, overwriteMeta, singleSRC, alt, imageTitle, imageUrl, fullsize,
 *                caption, floating
 *   - enclosure: addEnclosure  (the enclosure BLOB itself is intentionally omitted —
 *                multi-file pickers need Contao backend semantics we don't model yet)
 *   - expert:    cssClass, searchIndexer
 *   - publish:   published, start, stop
 *
 * The `size` imageSize widget is also omitted (serialized array with format expectations).
 */
final class FieldMapper
{
    public function __construct(
        private readonly FieldProviderRegistry $providers,
    ) {
    }

    /**
     * Plain string fields with direct passthrough.
     *
     * @var list<string>
     */
    private const STRING_FIELDS = [
        'headline', 'alias', 'pageTitle', 'robots', 'description', 'subheadline',
        'teaser', 'alt', 'imageTitle', 'imageUrl', 'caption', 'floating', 'source',
        'linkText', 'url', 'canonicalLink', 'cssClass', 'searchIndexer',
    ];

    /**
     * Boolean fields. Stored as tinyint 0/1 — MySQL strict mode rejects empty strings.
     *
     * @var list<string>
     */
    private const BOOL_FIELDS = [
        'featured', 'addImage', 'overwriteMeta', 'fullsize', 'addEnclosure',
        'target', 'published',
    ];

    /**
     * Integer fields whose MCP name matches the DCA name 1:1.
     *
     * @var list<string>
     */
    private const INT_FIELDS_DIRECT = [
        'jumpTo', 'articleId',
    ];

    /**
     * @param array<string, mixed> $input
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException on malformed date/datetime/UUID input
     */
    public function apply(NewsModel $news, array $input, bool $detectChanges = true): array
    {
        $changed = [];

        // ─── Validate every input key against known fields + provider claims ───
        $known = self::knownFields();
        $providers = $this->providers->forTable('tl_news');

        foreach (array_keys($input) as $field) {
            if (\in_array($field, $known, true)) {
                continue;
            }

            $claimedBy = null;
            foreach ($providers as $provider) {
                if (\in_array($field, $provider->getDeclaredFields(), true)) {
                    $claimedBy = $provider;
                    break;
                }
            }

            if ($claimedBy === null) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" is not a known tl_news field. Known: %s.',
                    $field,
                    implode(', ', $known),
                ));
            }

            if (!$claimedBy->isAvailable()) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" requires the %s extension, which is not installed in this Contao project.',
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

        $setString = static function (string $field, string $new) use ($news, $detectChanges, $touch): void {
            if (!$detectChanges || (string) $news->$field !== $new) {
                $news->$field = $new;
                $touch($field);
            }
        };

        $setInt = static function (string $field, int $new) use ($news, $detectChanges, $touch): void {
            if (!$detectChanges || (int) $news->$field !== $new) {
                $news->$field = $new;
                $touch($field);
            }
        };

        foreach (self::STRING_FIELDS as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $setString($field, (string) $input[$field]);
        }

        foreach (self::BOOL_FIELDS as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $setInt($field, $input[$field] ? 1 : 0);
        }

        foreach (self::INT_FIELDS_DIRECT as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $setInt($field, (int) $input[$field]);
        }

        // author_id → author column (FK tl_user.id)
        if (\array_key_exists('author_id', $input) && $input['author_id'] !== null) {
            $setInt('author', (int) $input['author_id']);
        }

        // archive_id → pid column (FK tl_news_archive.id)
        if (\array_key_exists('archive_id', $input) && $input['archive_id'] !== null) {
            $setInt('pid', (int) $input['archive_id']);
        }

        // date + time are stored as the SAME combined unix timestamp in both columns
        // (see tl_news::adjustTime onsubmit_callback). Recombine using whichever side
        // the user provided, falling back to the model's existing value for the other.
        $dateProvided = \array_key_exists('date', $input) && $input['date'] !== null && $input['date'] !== '';
        $timeProvided = \array_key_exists('time', $input) && $input['time'] !== null && $input['time'] !== '';

        if ($dateProvided || $timeProvided) {
            $existingDate = (int) $news->date > 0 ? date('Y-m-d', (int) $news->date) : date('Y-m-d');
            $existingTime = (int) $news->time > 0 ? date('H:i:s', (int) $news->time) : date('H:i:s');

            $newDate = $dateProvided ? (string) $input['date'] : $existingDate;
            $newTime = $timeProvided ? (string) $input['time'] : $existingTime;

            $combined = self::parseDateTime($newDate.' '.$newTime);

            $oldCombined = (int) $news->date;
            if (!$detectChanges || $oldCombined !== $combined) {
                $news->date = $combined;
                $news->time = $combined;
                if ($dateProvided) {
                    $touch('date');
                }
                if ($timeProvided) {
                    $touch('time');
                }
            }
        }

        // start/stop are varchar(10), holding either '' (clear) or a unix timestamp string
        foreach (['start', 'stop'] as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $raw = (string) $input[$field];
            $new = $raw === '' ? '' : (string) self::parseDateTime($raw);
            $setString($field, $new);
        }

        // singleSRC: binary(16). Accept hex-32 or hyphenated UUID, normalise to binary.
        if (\array_key_exists('singleSRC', $input) && $input['singleSRC'] !== null) {
            $raw = (string) $input['singleSRC'];
            if ($raw === '') {
                $bin = null;
            } else {
                $hex = str_replace('-', '', $raw);
                if (\strlen($hex) !== 32 || preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1) {
                    throw new \InvalidArgumentException(
                        sprintf('singleSRC must be a 32-character hex UUID or a UUID with dashes (got "%s").', $raw),
                    );
                }
                $bin = hex2bin($hex);
            }
            if (!$detectChanges || $news->singleSRC !== $bin) {
                $news->singleSRC = $bin;
                $touch('singleSRC');
            }
        }

        // ─── Provider apply (only available ones — others already failed above) ───
        foreach ($providers as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }
            foreach ($provider->apply($news, $input, $detectChanges) as $field) {
                $touch($field);
            }
        }

        return $changed;
    }

    /**
     * Every input key the core mapper recognises (aliases like archive_id/author_id
     * included). Extension fields are handled separately via the Registry.
     *
     * @return list<string>
     */
    private static function knownFields(): array
    {
        return array_values(array_unique(array_merge(
            self::STRING_FIELDS,
            self::BOOL_FIELDS,
            self::INT_FIELDS_DIRECT,
            ['archive_id', 'author_id', 'date', 'time', 'start', 'stop', 'singleSRC'],
        )));
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function parseDateTime(string $iso): int
    {
        $ts = strtotime($iso);
        if ($ts === false) {
            throw new \InvalidArgumentException(sprintf('Invalid date/datetime "%s". Use ISO 8601 (YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS).', $iso));
        }

        return $ts;
    }
}
