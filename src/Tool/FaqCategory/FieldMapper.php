<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\FaqCategory;

use Contao\FaqCategoryModel;

/**
 * Maps MCP input onto a FaqCategoryModel (tl_faq_category). Unlike tl_news_archive,
 * faq categories have no protected/groups — access control is handled at page level.
 * The extra `headline` field is a FE-display heading (separate from the backend
 * label `title`).
 */
final class FieldMapper
{
    /**
     * @var list<string>
     */
    private const STRING_FIELDS = ['title', 'headline', 'notify', 'sortOrder'];

    /**
     * @var list<string>
     */
    private const BOOL_FIELDS = ['allowComments', 'moderate', 'bbcode', 'requireLogin', 'disableCaptcha'];

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
    public function apply(FaqCategoryModel $category, array $input, bool $detectChanges = true): array
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
            if (!$detectChanges || (string) $category->$field !== $new) {
                $category->$field = $new;
                $touch($field);
            }
        }

        foreach (self::BOOL_FIELDS as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $new = $input[$field] ? 1 : 0;
            if (!$detectChanges || (int) $category->$field !== $new) {
                $category->$field = $new;
                $touch($field);
            }
        }

        foreach (self::INT_FIELDS as $field) {
            if (!\array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $new = (int) $input[$field];
            if (!$detectChanges || (int) $category->$field !== $new) {
                $category->$field = $new;
                $touch($field);
            }
        }

        return $changed;
    }
}
