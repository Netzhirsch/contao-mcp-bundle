<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\DeepL;

/**
 * Which columns of a Contao table carry text a human reads, and in what shape
 * that text is stored.
 *
 * This registry is deliberately curated rather than derived from the DCA.
 * A DCA `inputType` of `text` says nothing about whether the value is prose
 * (`tl_page.pageTitle`) or a machine token (`tl_page.alias`, `tl_content.cssID`,
 * `tl_module.name`). Handing the second kind to a translator silently breaks
 * URLs, CSS hooks and backend labels, and nothing in the DCA distinguishes the
 * two. So every field below was picked by hand, and a field that is not listed
 * is never translated — the failure mode of a missing field is "you translate
 * it yourself", which is recoverable; the failure mode of a wrongly included
 * one is a broken site.
 *
 * `alias` is the instructive case and is deliberately ABSENT. A translated tree
 * does usually want translated URLs, but a translated alias is not a slug:
 * DeepL returns prose, and the update tools write a non-empty alias verbatim,
 * so "Our Services" would land in a URL segment. Contao already has the right
 * mechanism — passing an EMPTY alias to `page_update` regenerates it from the
 * (by then translated) title through the Slug service. Translate first, then
 * clear the alias; that path cannot produce a broken URL.
 *
 * Formats describe how to get the string out of the stored value and back in:
 *
 *   text     plain string, translated as text
 *   html     rich text — sent with DeepL's tag_handling=html so markup survives
 *   headline Contao's headline tuple, serialise(['value' => …, 'unit' => 'h2']);
 *            only `value` is translated, the unit is structure
 *   list     serialised list<string> (listWizard) — every item translated
 *   matrix   serialised list<list<string>> (tableWizard) — every cell translated
 */
final class TranslatableFields
{
    public const FORMAT_TEXT = 'text';
    public const FORMAT_HTML = 'html';
    public const FORMAT_HEADLINE = 'headline';
    public const FORMAT_LIST = 'list';
    public const FORMAT_MATRIX = 'matrix';

    /**
     * table => field => [format, default].
     *
     * `default: false` means the field is only translated when explicitly asked
     * for by name.
     *
     * @var array<string, array<string, array{format: string, default: bool}>>
     */
    private const REGISTRY = [
        'tl_page' => [
            'title' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'pageTitle' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'description' => ['format' => self::FORMAT_TEXT, 'default' => true],
        ],
        'tl_article' => [
            'title' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'teaser' => ['format' => self::FORMAT_HTML, 'default' => true],
        ],
        'tl_content' => [
            'headline' => ['format' => self::FORMAT_HEADLINE, 'default' => true],
            'text' => ['format' => self::FORMAT_HTML, 'default' => true],
            'html' => ['format' => self::FORMAT_HTML, 'default' => false],
            'caption' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'alt' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'imageTitle' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'linkTitle' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'titleText' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'listitems' => ['format' => self::FORMAT_LIST, 'default' => true],
            'tableitems' => ['format' => self::FORMAT_MATRIX, 'default' => true],
        ],
        'tl_news' => [
            'headline' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'subheadline' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'teaser' => ['format' => self::FORMAT_HTML, 'default' => true],
            'pageTitle' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'description' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'linkText' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'caption' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'alt' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'imageTitle' => ['format' => self::FORMAT_TEXT, 'default' => true],
        ],
        'tl_calendar_events' => [
            'title' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'teaser' => ['format' => self::FORMAT_HTML, 'default' => true],
            'pageTitle' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'description' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'location' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'address' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'linkText' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'caption' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'alt' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'imageTitle' => ['format' => self::FORMAT_TEXT, 'default' => true],
        ],
        'tl_faq' => [
            'question' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'answer' => ['format' => self::FORMAT_HTML, 'default' => true],
            'pageTitle' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'description' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'caption' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'alt' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'imageTitle' => ['format' => self::FORMAT_TEXT, 'default' => true],
        ],
        'tl_news_archive' => [
            'title' => ['format' => self::FORMAT_TEXT, 'default' => true],
        ],
        'tl_calendar' => [
            'title' => ['format' => self::FORMAT_TEXT, 'default' => true],
        ],
        'tl_faq_category' => [
            'title' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'headline' => ['format' => self::FORMAT_TEXT, 'default' => true],
        ],
        'tl_form' => [
            'confirmation' => ['format' => self::FORMAT_HTML, 'default' => true],
        ],
        'tl_form_field' => [
            'label' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'placeholder' => ['format' => self::FORMAT_TEXT, 'default' => true],
            'text' => ['format' => self::FORMAT_HTML, 'default' => true],
            'html' => ['format' => self::FORMAT_HTML, 'default' => false],
            'slabel' => ['format' => self::FORMAT_TEXT, 'default' => true],
        ],
        'tl_module' => [
            'headline' => ['format' => self::FORMAT_HEADLINE, 'default' => true],
            'customLabel' => ['format' => self::FORMAT_TEXT, 'default' => true],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function tables(): array
    {
        return array_keys(self::REGISTRY);
    }

    public static function knows(string $table): bool
    {
        return isset(self::REGISTRY[$table]);
    }

    /**
     * Every field the table can translate, default ones first.
     *
     * @return list<string>
     */
    public static function all(string $table): array
    {
        return array_keys(self::REGISTRY[$table] ?? []);
    }

    /**
     * Fields translated when the caller names none.
     *
     * @return list<string>
     */
    public static function defaults(string $table): array
    {
        $out = [];
        foreach (self::REGISTRY[$table] ?? [] as $field => $spec) {
            if ($spec['default']) {
                $out[] = $field;
            }
        }

        return $out;
    }

    public static function formatOf(string $table, string $field): ?string
    {
        return self::REGISTRY[$table][$field]['format'] ?? null;
    }

    /**
     * Resolves the caller's `fields` argument against the registry.
     *
     * Unknown names are NOT an error — they are reported back as `ignored`, the
     * same contract every other write tool in this bundle uses. An empty
     * selection falls back to the table's defaults.
     *
     * @param list<string>|null $requested
     *
     * @return array{fields: list<string>, ignored: list<string>}
     */
    public static function resolve(string $table, ?array $requested): array
    {
        if ($requested === null || $requested === []) {
            return ['fields' => self::defaults($table), 'ignored' => []];
        }

        $known = self::REGISTRY[$table] ?? [];
        $fields = [];
        $ignored = [];

        foreach ($requested as $name) {
            $name = (string) $name;
            if (isset($known[$name])) {
                if (!\in_array($name, $fields, true)) {
                    $fields[] = $name;
                }
                continue;
            }
            $ignored[] = $name;
        }

        return ['fields' => $fields, 'ignored' => $ignored];
    }
}
