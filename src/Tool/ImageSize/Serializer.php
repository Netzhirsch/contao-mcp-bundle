<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\ImageSize;

use Contao\ImageSizeItemModel;
use Contao\ImageSizeModel;
use Contao\StringUtil;

/**
 * Renders tl_image_size + tl_image_size_item rows for MCP responses.
 *
 * `formats` is stored as a serialized list of "<from>:<to>[,<chain>]" strings —
 * we decode it to a plain list. `preserveMetadataFields` is a serialized list
 * of "field-key=>value" structures and we just pass it through deserialized.
 */
final class Serializer
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(ImageSizeModel $s): array
    {
        return [
            'id' => (int) $s->id,
            'theme_id' => (int) $s->pid,
            'name' => (string) $s->name,
            'width' => $s->width === null ? null : (int) $s->width,
            'height' => $s->height === null ? null : (int) $s->height,
            'resize_mode' => (string) $s->resizeMode,
            'zoom' => $s->zoom === null ? null : (int) $s->zoom,
            'tstamp' => (int) $s->tstamp,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function full(ImageSizeModel $s): array
    {
        return self::summary($s) + [
            'image_quality' => $s->imageQuality === null ? null : (int) $s->imageQuality,
            'css_class' => (string) $s->cssClass,
            'densities' => (string) $s->densities,
            'sizes' => (string) $s->sizes,
            'formats' => self::deserializeStringList($s->formats),
            'preserve_metadata' => (string) $s->preserveMetadata,
            'preserve_metadata_fields' => self::deserializeAny($s->preserveMetadataFields),
            'skip_if_dimensions_match' => (bool) $s->skipIfDimensionsMatch,
            'lazy_loading' => (bool) $s->lazyLoading,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function itemSummary(ImageSizeItemModel $i): array
    {
        return [
            'id' => (int) $i->id,
            'image_size_id' => (int) $i->pid,
            'sorting' => (int) $i->sorting,
            'media' => (string) $i->media,
            'width' => $i->width === null ? null : (int) $i->width,
            'height' => $i->height === null ? null : (int) $i->height,
            'resize_mode' => (string) $i->resizeMode,
            'zoom' => $i->zoom === null ? null : (int) $i->zoom,
            'active' => !(bool) $i->invisible,
            'tstamp' => (int) $i->tstamp,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function itemFull(ImageSizeItemModel $i): array
    {
        return self::itemSummary($i) + [
            'densities' => (string) $i->densities,
            'sizes' => (string) $i->sizes,
        ];
    }

    /**
     * @return list<string>
     */
    private static function deserializeStringList(mixed $blob): array
    {
        $list = StringUtil::deserialize($blob, true);

        return array_values(array_filter(
            $list,
            static fn ($v): bool => \is_string($v) && $v !== '',
        ));
    }

    private static function deserializeAny(mixed $blob): mixed
    {
        return StringUtil::deserialize($blob, true);
    }
}
