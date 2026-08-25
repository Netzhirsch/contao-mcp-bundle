<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Html;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Input;
use Contao\StringUtil;
use PhpMcp\Server\Attributes\McpTool;

/**
 * What survives Contao's HTML filter, and why.
 *
 * The gap these two tools close: **what is stored is not what is rendered.**
 * MCP writes go through the Model, so the database keeps a byte-exact copy of
 * whatever was sent and a read-back looks perfect. Filtering happens later, in
 * the template, through `{{ html|sanitize_html('contao') }}` — which lands in
 * `Input::stripTags()` with the `allowedTags` / `allowedAttributes` settings.
 *
 * The consequence is invisible in every answer the MCP server gives. Storing
 * `<input type="checkbox">` and `<label for="…">` reads back verbatim and
 * renders as `<input>` and `<label>`: the two attributes that made the markup
 * work are gone, and nothing anywhere reported it. Storing an inline `<svg>`
 * reads back verbatim and renders as escaped text.
 *
 * So: `html_filter_info` answers "what is allowed here", and
 * `html_filter_preview` answers "what will this exact markup become" BEFORE it
 * is written. Both are read-only and touch no record.
 *
 * If the markup must survive untouched, the answer is not to widen the filter
 * but to use the element type meant for it: `unfiltered_html` as a content
 * element or module type stores and renders raw markup. `allowedTags` and
 * `allowedAttributes` are site-wide settings — widening them for one element
 * widens them for every editor on the site.
 */
final class Tool
{
    /** A preview is for reviewing a snippet, not for scanning a page dump. */
    private const MAX_PREVIEW_LENGTH = 20000;

    public function __construct(
        private readonly ContaoFramework $framework,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'html_filter_info',
        description: <<<'DESC'
            Lists the HTML tags and attributes that survive Contao's output filter on this
            installation — the `allowedTags` / `allowedAttributes` system settings, applied
            at RENDER time by sanitize_html('contao'), not when the value is written.

            This matters because a stored value and a rendered value are not the same
            thing: MCP writes go through the Model, so a read-back returns exactly what was
            sent even when the frontend will drop half of it.

            Attributes are given per tag; the `*` entry applies to every tag. An entry
            ending in `*` is a prefix rule (data-*, aria-*).

            To keep markup that this list would strip, use the `unfiltered_html` content
            element or module type rather than widening the settings — they are site-wide
            and apply to every editor.
            DESC,
    )]
    public function filterInfo(): array
    {
        $this->framework->initialize();

        return [
            'allowed_tags' => $this->allowedTags(),
            'allowed_attributes' => $this->allowedAttributes(),
            'applied_at' => 'render',
            'filter' => "sanitize_html('contao') → Contao\\Input::stripTags()",
            'raw_markup_alternative' => 'Content element / module type "unfiltered_html" stores and renders markup unfiltered.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'html_filter_preview',
        description: <<<'DESC'
            Runs a piece of markup through Contao's output filter and reports what comes
            out — the answer to "will this survive?" before anything is written.

            Returns the filtered `output`, a `changed` flag, and what caused the change:
            `removed_tags` (not in allowedTags) and `removed_attributes` as
            {tag, attribute} pairs (not in allowedAttributes for that tag, nor in the `*`
            entry).

            Typical finds: `type` and `for` are not allowed attributes, so a
            checkbox-plus-label construction renders as `<input>` and `<label>` and stops
            working; `svg` and `path` are not allowed tags, so an inline icon renders as
            escaped text. Both read back from the database unchanged.

            If the markup has to survive as written, use the `unfiltered_html` content
            element or module type instead of widening the site-wide settings.
            DESC,
    )]
    public function filterPreview(string $text): array
    {
        $this->framework->initialize();

        if ($text === '') {
            return ['error' => 'no_text', 'message' => 'Pass the markup to check as `text`.'];
        }
        if (\strlen($text) > self::MAX_PREVIEW_LENGTH) {
            return [
                'error' => 'text_too_long',
                'message' => sprintf('At most %d bytes per call, got %d.', self::MAX_PREVIEW_LENGTH, \strlen($text)),
            ];
        }

        $input = $this->framework->getAdapter(Input::class);
        $config = $this->framework->getAdapter(Config::class);

        $output = (string) $input->stripTags(
            $text,
            (string) $config->get('allowedTags'),
            $config->get('allowedAttributes'),
        );

        $analysis = $this->analyse($text);

        return [
            'output' => $output,
            'changed' => $output !== $text,
            'removed_tags' => $analysis['tags'],
            'removed_attributes' => $analysis['attributes'],
            'raw_markup_alternative' => $analysis['tags'] === [] && $analysis['attributes'] === []
                ? null
                : 'Use the "unfiltered_html" content element or module type to keep this markup as written.',
        ];
    }

    /**
     * Which tags and attributes in this markup the settings do not allow.
     *
     * Derived from the configuration rather than by diffing input against
     * output: the diff would say THAT something changed, this says WHICH rule
     * changed it, which is the part a caller can act on.
     *
     * @return array{tags: list<string>, attributes: list<array{tag: string, attribute: string}>}
     */
    private function analyse(string $text): array
    {
        $allowedTags = $this->allowedTags();
        $allowedAttributes = $this->allowedAttributes();

        $tags = [];
        $attributes = [];

        // Opening tags with their attribute block. Closing tags carry no
        // attributes and are covered by the same tag-name check.
        preg_match_all('@<\s*(/?)\s*([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>@', $text, $matches, \PREG_SET_ORDER);

        foreach ($matches as $match) {
            $tag = strtolower($match[2]);

            if (!\in_array($tag, $allowedTags, true)) {
                if (!\in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
                // The tag goes, so its attributes are not a separate finding.
                continue;
            }

            if ($match[1] === '/') {
                continue;
            }

            $permitted = array_merge($allowedAttributes['*'] ?? [], $allowedAttributes[$tag] ?? []);

            foreach ($this->attributeNames($match[3]) as $attribute) {
                if (self::isPermitted($attribute, $permitted)) {
                    continue;
                }

                $finding = ['tag' => $tag, 'attribute' => $attribute];
                if (!\in_array($finding, $attributes, true)) {
                    $attributes[] = $finding;
                }
            }
        }

        return ['tags' => $tags, 'attributes' => $attributes];
    }

    /**
     * @return list<string>
     */
    private function attributeNames(string $attributeBlock): array
    {
        preg_match_all('@(?:^|\s)([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=@', $attributeBlock, $found);

        return array_values(array_unique(array_map(strtolower(...), $found[1] ?? [])));
    }

    /**
     * @param list<string> $permitted
     */
    private static function isPermitted(string $attribute, array $permitted): bool
    {
        foreach ($permitted as $rule) {
            if (str_ends_with($rule, '*')) {
                if (str_starts_with($attribute, substr($rule, 0, -1))) {
                    return true;
                }
                continue;
            }
            if ($attribute === $rule) {
                return true;
            }
        }

        return false;
    }

    /**
     * `allowedTags` is stored as "<a><abbr><address>…".
     *
     * @return list<string>
     */
    private function allowedTags(): array
    {
        $config = $this->framework->getAdapter(Config::class);

        preg_match_all('@<([a-zA-Z0-9-]+)>@', (string) $config->get('allowedTags'), $found);

        return array_values(array_unique(array_map(strtolower(...), $found[1] ?? [])));
    }

    /**
     * `allowedAttributes` is a serialised list of {key, value} rows, the value a
     * comma-separated attribute list. `*` is the row that applies to every tag.
     *
     * @return array<string, list<string>>
     */
    private function allowedAttributes(): array
    {
        $config = $this->framework->getAdapter(Config::class);

        $out = [];

        foreach (StringUtil::deserialize($config->get('allowedAttributes'), true) as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $key = strtolower(trim((string) ($row['key'] ?? '')));
            $value = (string) ($row['value'] ?? '');
            if ($key === '' || $value === '') {
                continue;
            }

            $names = array_values(array_filter(array_map(
                static fn (string $name) => strtolower(trim($name)),
                explode(',', $value),
            )));

            $out[$key] = array_values(array_unique(array_merge($out[$key] ?? [], $names)));
        }

        return $out;
    }
}
