<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\NewsArchive;

use Contao\NewsArchiveModel;

/**
 * Field mapper for tl_news_archive (mirrors News\FieldMapper for tl_news).
 *
 * DCA-editable fields (Contao 5.7 news-bundle):
 *   - title     : string (required for create)
 *   - jumpTo    : int    (required for create; tl_page.id)
 *   - protected : bool
 *   - groups    : list<int>  (tl_member_group.id; stored as PHP-serialized blob)
 */
final class FieldMapper
{
    /**
     * @param array<string, mixed> $input
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    public function apply(NewsArchiveModel $archive, array $input, bool $detectChanges = true): array
    {
        $changed = [];

        $touch = static function (string $field) use (&$changed): void {
            if (!\in_array($field, $changed, true)) {
                $changed[] = $field;
            }
        };

        if (\array_key_exists('title', $input) && $input['title'] !== null) {
            $new = (string) $input['title'];
            if (!$detectChanges || (string) $archive->title !== $new) {
                $archive->title = $new;
                $touch('title');
            }
        }

        if (\array_key_exists('jumpTo', $input) && $input['jumpTo'] !== null) {
            $new = (int) $input['jumpTo'];
            if (!$detectChanges || (int) $archive->jumpTo !== $new) {
                $archive->jumpTo = $new;
                $touch('jumpTo');
            }
        }

        if (\array_key_exists('protected', $input) && $input['protected'] !== null) {
            $new = $input['protected'] ? 1 : 0;
            if (!$detectChanges || (int) $archive->protected !== $new) {
                $archive->protected = $new;
                $touch('protected');
            }
        }

        if (\array_key_exists('groups', $input) && $input['groups'] !== null) {
            $raw = $input['groups'];
            if (!\is_array($raw)) {
                throw new \InvalidArgumentException("'groups' must be an array of tl_member_group.id integers.");
            }
            $cleaned = array_values(array_map('intval', $raw));
            $new = $cleaned === [] ? '' : serialize($cleaned);
            if (!$detectChanges || (string) $archive->groups !== $new) {
                $archive->groups = $new;
                $touch('groups');
            }
        }

        return $changed;
    }
}
