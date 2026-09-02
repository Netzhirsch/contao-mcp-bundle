<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

/**
 * Turns "this tool cannot write that field" into "this one can".
 *
 * Twice now a refusal has been read as an absence: `entity_field_patch` answered
 * `field_not_writable` for `tl_news_archive.master`, and the conclusion drawn —
 * and passed on to a customer — was that the column could not be set over MCP at
 * all. It could; a different tool owned it. The same mistake had already been
 * made with `tl_page.netzhirschPageState` and `pagestate_assign`.
 *
 * A refusal is the one moment when the caller is definitely listening, so it is
 * the right place to say where to go instead. Fields whose owner is known by
 * name get the call written out; everything else gets the words to search for,
 * because {@see NameTokens} showed that pasting the identifier finds nothing.
 */
final class FieldOwner
{
    /**
     * table.field → the call that owns it.
     *
     * @var array<string, string>
     */
    private const OWNED = [
        'tl_news_archive.master' => 'entity_language_link(table: "tl_news_archive", default_id: <master archive id>, translations: {"<lang>": <this archive id>})',
        'tl_calendar.master' => 'entity_language_link(table: "tl_calendar", default_id: <master calendar id>, translations: {"<lang>": <this calendar id>})',
        'tl_faq_category.master' => 'entity_language_link(table: "tl_faq_category", default_id: <master category id>, translations: {"<lang>": <this category id>})',
    ];

    /**
     * @param list<string> $fields
     */
    public static function hintFor(string $table, array $fields): string
    {
        foreach ($fields as $field) {
            $owner = self::OWNED[$table.'.'.$field] ?? null;
            if ($owner !== null) {
                return sprintf('`%s` is owned by another tool — set it with %s.', $field, $owner);
            }
        }

        $first = $fields[0] ?? '';
        $phrase = NameTokens::phrase($first === '' ? $table : $first);

        return $phrase === ''
            ? ''
            : sprintf('If another tool owns this field, contao_search_tools("%s") will find it.', $phrase);
    }
}
