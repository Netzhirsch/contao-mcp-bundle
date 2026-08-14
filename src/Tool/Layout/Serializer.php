<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Layout;

use Contao\FilesModel;
use Contao\LayoutModel;
use Contao\StringUtil;

/**
 * Renders LayoutModel rows for MCP responses. Serialized blobs (modules,
 * sections, framework, jquery/mootools/scripts/analytics, external files)
 * are deserialized into structured arrays so the LLM doesn't have to
 * unpack PHP-serialize strings itself.
 */
final class Serializer
{
    /**
     * Short row for list views.
     *
     * @return array<string, mixed>
     */
    public static function summary(LayoutModel $l): array
    {
        return [
            'id' => (int) $l->id,
            'theme_id' => (int) $l->pid,
            'name' => (string) $l->name,
            'type' => (string) $l->type,
            'rows' => (string) $l->rows,
            'cols' => (string) $l->cols,
            'template' => (string) $l->template,
            'tstamp' => (int) $l->tstamp,
        ];
    }

    /**
     * Full payload for layout_get / write-tool responses.
     *
     * @return array<string, mixed>
     */
    public static function full(LayoutModel $l): array
    {
        return self::summary($l) + [
            'header_height' => (string) $l->headerHeight,
            'footer_height' => (string) $l->footerHeight,
            'width_left' => (string) $l->widthLeft,
            'width_right' => (string) $l->widthRight,
            'sections' => self::deserializeList($l->sections),
            'modules' => self::deserializeList($l->modules),
            'framework' => self::deserializeStringList($l->framework),
            'external' => self::resolveFilePaths($l->external),
            'external_js' => self::resolveFilePaths($l->externalJs),
            'combine_scripts' => (bool) $l->combineScripts,
            'minify_markup' => (bool) $l->minifyMarkup,
            'lightbox_size' => (string) $l->lightboxSize,
            'default_image_densities' => (string) $l->defaultImageDensities,
            'viewport' => (string) $l->viewport,
            'title_tag' => (string) $l->titleTag,
            'css_class' => (string) $l->cssClass,
            'onload' => (string) $l->onload,
            'head' => (string) $l->head,
            'add_jquery' => (bool) $l->addJQuery,
            'jquery' => self::deserializeStringList($l->jquery),
            'add_mootools' => (bool) $l->addMooTools,
            'mootools' => self::deserializeStringList($l->mootools),
            'analytics' => self::deserializeStringList($l->analytics),
            'scripts' => self::deserializeStringList($l->scripts),
            'script' => (string) $l->script,
            'static' => (bool) $l->static,
            'width' => (string) $l->width,
            'align' => (string) $l->align,
        ];
    }

    /**
     * @return list<mixed>
     */
    private static function deserializeList(mixed $blob): array
    {
        $list = StringUtil::deserialize($blob, true);

        return array_values($list);
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

    /**
     * Resolves a serialized list of file UUIDs (fileTree multi) back to paths.
     *
     * @return list<string>
     */
    private static function resolveFilePaths(mixed $blob): array
    {
        $uuids = StringUtil::deserialize($blob, true);
        $out = [];
        foreach ($uuids as $uuid) {
            if (!\is_string($uuid) || $uuid === '') {
                continue;
            }
            $model = FilesModel::findByUuid($uuid);
            if ($model !== null) {
                $out[] = (string) $model->path;
            }
        }

        return $out;
    }
}
