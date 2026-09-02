<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

/**
 * Splits an identifier into the words a human would search for.
 *
 * `contao_search_tools` matches a query word against tool names and
 * descriptions, and a caller who has just been refused a write reaches for the
 * name they were refused: `netzhirschPageState`, or `tl_netzhirsch_page_state`.
 * Neither contains a space, so as one token neither matched `pagestate_assign`
 * — the group that owned the field stayed invisible, and the field was written
 * the wrong way round instead.
 *
 * So: split on separators AND on camelCase humps, drop the `tl_` prefix, and
 * search the words. `tl_netzhirsch_page_state` → netzhirsch, page, state, and
 * `pagestate_assign` contains two of them.
 */
final class NameTokens
{
    /**
     * @return list<string>
     */
    public static function split(string $name): array
    {
        $name = preg_replace('/^tl_/', '', trim($name)) ?? $name;

        // camelCase / PascalCase humps, and acronym-to-word boundaries
        // (HTMLParser → HTML, Parser).
        $spaced = preg_replace(
            ['/([a-z0-9])([A-Z])/u', '/([A-Z]+)([A-Z][a-z])/u'],
            ['$1 $2', '$1 $2'],
            $name,
        ) ?? $name;

        $parts = preg_split('/[\s,;_\-.\/]+/u', $spaced) ?: [];

        $out = [];
        foreach ($parts as $part) {
            $part = mb_strtolower($part);
            if (mb_strlen($part) > 1 && !\in_array($part, $out, true)) {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * The words as one search phrase, ready to hand back in an error message.
     */
    public static function phrase(string $name): string
    {
        return implode(' ', self::split($name));
    }
}
