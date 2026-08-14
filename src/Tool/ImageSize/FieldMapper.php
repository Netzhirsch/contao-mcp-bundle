<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\ImageSize;

use Contao\ImageSizeItemModel;
use Contao\ImageSizeModel;

/**
 * Maps MCP-facing dicts to ImageSizeModel + ImageSizeItemModel columns.
 *
 * tl_image_size fields:
 *   name (mandatory), width, height (int), resize_mode (proportional|box|crop),
 *   zoom (0-100 %), image_quality (0-100 %), css_class, densities, sizes,
 *   formats (list<string>, serialized), preserve_metadata (default|overwrite|delete),
 *   preserve_metadata_fields (multi-checkbox blob), skip_if_dimensions_match,
 *   lazy_loading (bool flags).
 *
 * tl_image_size_item fields:
 *   media, width, height, resize_mode, zoom, densities, sizes, active (= !invisible).
 */
final class FieldMapper
{
    private const RESIZE_MODES = ['proportional', 'box', 'crop'];
    private const PRESERVE_METADATA = ['default', 'overwrite', 'delete'];

    /**
     * @return array{errors: list<string>, applied: int}
     */
    public function applyToSize(ImageSizeModel $s, array $input): array
    {
        $errors = [];
        $applied = 0;

        if (\array_key_exists('name', $input)) {
            $value = trim((string) $input['name']);
            if ($value === '') {
                $errors[] = 'name must not be empty';
            } else {
                $s->name = mb_substr($value, 0, 64);
                ++$applied;
            }
        }

        foreach (['width', 'height'] as $intKey) {
            if (\array_key_exists($intKey, $input)) {
                $v = $input[$intKey];
                $s->{$intKey} = ($v === null || $v === '') ? null : (int) $v;
                ++$applied;
            }
        }

        if (\array_key_exists('resize_mode', $input)) {
            $value = (string) $input['resize_mode'];
            if ($value !== '' && !\in_array($value, self::RESIZE_MODES, true)) {
                $errors[] = 'resize_mode must be one of: '.implode(', ', self::RESIZE_MODES);
            } else {
                $s->resizeMode = $value;
                ++$applied;
            }
        }

        foreach (['zoom' => 'zoom', 'image_quality' => 'imageQuality'] as $key => $column) {
            if (\array_key_exists($key, $input)) {
                $v = $input[$key];
                if ($v === null || $v === '') {
                    $s->{$column} = null;
                } else {
                    $int = (int) $v;
                    if ($int < 0 || $int > 100) {
                        $errors[] = sprintf('%s must be between 0 and 100', $key);
                        continue;
                    }
                    $s->{$column} = $int;
                }
                ++$applied;
            }
        }

        if (\array_key_exists('css_class', $input)) {
            $s->cssClass = (string) $input['css_class'];
            ++$applied;
        }
        if (\array_key_exists('densities', $input)) {
            $s->densities = (string) $input['densities'];
            ++$applied;
        }
        if (\array_key_exists('sizes', $input)) {
            $s->sizes = (string) $input['sizes'];
            ++$applied;
        }

        if (\array_key_exists('formats', $input)) {
            $value = $input['formats'];
            if ($value === null || $value === '') {
                $s->formats = '';
                ++$applied;
            } elseif (!\is_array($value) || !array_is_list($value)) {
                $errors[] = 'formats must be a list of strings like "jpg:webp,jpg"';
            } else {
                $clean = [];
                $bad = false;
                foreach ($value as $entry) {
                    if (!\is_string($entry) || $entry === '') {
                        $errors[] = 'formats entries must be non-empty strings';
                        $bad = true;
                        break;
                    }
                    $clean[] = $entry;
                }
                if (!$bad) {
                    $s->formats = $clean === [] ? '' : serialize($clean);
                    ++$applied;
                }
            }
        }

        if (\array_key_exists('preserve_metadata', $input)) {
            $value = (string) $input['preserve_metadata'];
            if (!\in_array($value, self::PRESERVE_METADATA, true)) {
                $errors[] = 'preserve_metadata must be one of: '.implode(', ', self::PRESERVE_METADATA);
            } else {
                $s->preserveMetadata = $value;
                ++$applied;
            }
        }

        if (\array_key_exists('preserve_metadata_fields', $input)) {
            // The DCA stores these as serialized list<serialize({field=>value})>.
            // We expose them as opaque pass-through to avoid baking the schema
            // into the bundle — callers can copy the blob from another row.
            $value = $input['preserve_metadata_fields'];
            $s->preserveMetadataFields = \is_string($value) ? $value : serialize($value);
            ++$applied;
        }

        if (\array_key_exists('skip_if_dimensions_match', $input)) {
            $s->skipIfDimensionsMatch = (bool) $input['skip_if_dimensions_match'] ? 1 : 0;
            ++$applied;
        }
        if (\array_key_exists('lazy_loading', $input)) {
            $s->lazyLoading = (bool) $input['lazy_loading'] ? 1 : 0;
            ++$applied;
        }

        return ['errors' => $errors, 'applied' => $applied];
    }

    /**
     * @return array{errors: list<string>, applied: int}
     */
    public function applyToItem(ImageSizeItemModel $i, array $input): array
    {
        $errors = [];
        $applied = 0;

        if (\array_key_exists('media', $input)) {
            $i->media = (string) $input['media'];
            ++$applied;
        }
        foreach (['width', 'height'] as $intKey) {
            if (\array_key_exists($intKey, $input)) {
                $v = $input[$intKey];
                $i->{$intKey} = ($v === null || $v === '') ? null : (int) $v;
                ++$applied;
            }
        }
        if (\array_key_exists('resize_mode', $input)) {
            $value = (string) $input['resize_mode'];
            if ($value !== '' && !\in_array($value, self::RESIZE_MODES, true)) {
                $errors[] = 'resize_mode must be one of: '.implode(', ', self::RESIZE_MODES);
            } else {
                $i->resizeMode = $value;
                ++$applied;
            }
        }
        if (\array_key_exists('zoom', $input)) {
            $v = $input['zoom'];
            if ($v === null || $v === '') {
                $i->zoom = null;
            } else {
                $int = (int) $v;
                if ($int < 0 || $int > 100) {
                    $errors[] = 'zoom must be between 0 and 100';
                } else {
                    $i->zoom = $int;
                }
            }
            ++$applied;
        }
        if (\array_key_exists('densities', $input)) {
            $i->densities = (string) $input['densities'];
            ++$applied;
        }
        if (\array_key_exists('sizes', $input)) {
            $i->sizes = (string) $input['sizes'];
            ++$applied;
        }
        if (\array_key_exists('active', $input)) {
            // DCA stores `invisible` (reverseToggle) — we expose positive `active`.
            $i->invisible = (bool) $input['active'] ? 0 : 1;
            ++$applied;
        }
        if (\array_key_exists('sorting', $input)) {
            $i->sorting = (int) $input['sorting'];
            ++$applied;
        }

        return ['errors' => $errors, 'applied' => $applied];
    }
}
