<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Article;

use Contao\ArticleModel;
use Netzhirsch\ContaoMcpBundle\Service\FieldProviderRegistry;

/**
 * Flattens an ArticleModel into a JSON-friendly array. Output is symmetric with what
 * Article\Tool's create/update accept. Available FieldProviders (e.g.
 * terminal42/contao-changelanguage's languageMain) are merged in at the end.
 *
 * Special handling:
 *   - cssID:     serialized [id, class]   →  {id: "...", class: "..."}
 *   - space:     serialized [top, bottom] →  {top: "...", bottom: "..."}
 *   - printable: serialized list<string>  →  ["print", "pdf", ...]
 *   - groups:    serialized list<int>     →  [1, 2]
 */
final class Serializer
{
    public function __construct(
        private readonly FieldProviderRegistry $providers,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(ArticleModel $a): array
    {
        $core = [
            'id' => (int) $a->id,
            'page_id' => (int) $a->pid,
            'sorting' => (int) $a->sorting,

            'title' => (string) $a->title,
            'alias' => (string) $a->alias,
            'author_id' => (int) $a->author,
            'inColumn' => (string) $a->inColumn,

            'showTeaser' => (bool) $a->showTeaser,
            'teaserCssID' => (string) $a->teaserCssID,
            'teaser' => $a->teaser !== null ? (string) $a->teaser : null,

            'printable' => self::unserializeStringList($a->printable),
            'customTpl' => (string) $a->customTpl,

            'protected' => (bool) $a->protected,
            'groups' => self::unserializeIntList($a->groups),
            'guests' => (bool) $a->guests,

            'cssID' => self::unserializeIdClassTuple($a->cssID),
            'space' => self::unserializeTopBottomTuple($a->space),

            'published' => (bool) $a->published,
            'start' => $a->start ? date(\DATE_ATOM, (int) $a->start) : null,
            'stop' => $a->stop ? date(\DATE_ATOM, (int) $a->stop) : null,
        ];

        foreach ($this->providers->availableForTable('tl_article') as $provider) {
            $core = array_merge($core, $provider->serialize($a));
        }

        return $core;
    }

    /**
     * @return array{id: string, class: string}
     */
    private static function unserializeIdClassTuple(mixed $value): array
    {
        $default = ['id' => '', 'class' => ''];
        if (!$value) {
            return $default;
        }
        $decoded = @unserialize((string) $value, ['allowed_classes' => false]);
        if (!\is_array($decoded)) {
            return $default;
        }

        return [
            'id' => (string) ($decoded[0] ?? ''),
            'class' => (string) ($decoded[1] ?? ''),
        ];
    }

    /**
     * @return array{top: string, bottom: string}
     */
    private static function unserializeTopBottomTuple(mixed $value): array
    {
        $default = ['top' => '', 'bottom' => ''];
        if (!$value) {
            return $default;
        }
        $decoded = @unserialize((string) $value, ['allowed_classes' => false]);
        if (!\is_array($decoded)) {
            return $default;
        }

        return [
            'top' => (string) ($decoded[0] ?? ''),
            'bottom' => (string) ($decoded[1] ?? ''),
        ];
    }

    /**
     * @return list<string>
     */
    private static function unserializeStringList(mixed $value): array
    {
        if (!$value) {
            return [];
        }
        $decoded = @unserialize((string) $value, ['allowed_classes' => false]);
        if (!\is_array($decoded)) {
            return [];
        }

        return array_values(array_map('strval', $decoded));
    }

    /**
     * @return list<int>
     */
    private static function unserializeIntList(mixed $value): array
    {
        if (!$value) {
            return [];
        }
        $decoded = @unserialize((string) $value, ['allowed_classes' => false]);
        if (!\is_array($decoded)) {
            return [];
        }

        return array_values(array_map('intval', $decoded));
    }
}
