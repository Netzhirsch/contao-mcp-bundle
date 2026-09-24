<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce;

use Contao\ContentModel;
use Contao\Model;
use Netzhirsch\ContaoMcpBundle\Tool\Contract\FieldProvider;

/**
 * Makes `tl_content.rsce_data` writable on RSCE elements.
 *
 * The column carries the whole configuration of an RSCE element — grid, button
 * URL, background colour — and was readable through content_get but refused by
 * every write tool: it is not a palette field, it is the storage behind RSCE's
 * virtual fields. Reported against v1.33.0 with the themore theme, where the only
 * way to get a configured element was copying an existing one with
 * entity_duplicate and a raw override.
 *
 * Only for `rsce_*` types, only with RSCE installed; the value rules (merge,
 * storage form, key check against the type's config) are in {@see RsceData}.
 *
 * The read side is left alone on purpose: content_get keeps returning the JSON
 * string it always returned, and a write accepts that string back unchanged.
 */
final class ContentProvider implements FieldProvider
{
    private const FIELD = 'rsce_data';

    public function __construct(
        private readonly RsceElements $rsce,
    ) {
    }

    public function getTable(): string
    {
        return 'tl_content';
    }

    public function getRequiredExtension(): string
    {
        return RsceElements::PACKAGE;
    }

    public function isAvailable(): bool
    {
        return $this->rsce->isAvailable();
    }

    public function getDeclaredFields(): array
    {
        return [self::FIELD];
    }

    public function getAllowedFields(?string $type): array
    {
        if ($type === null || RsceElements::isRsceType($type)) {
            return [self::FIELD];
        }

        return [];
    }

    public function serialize(Model $model): array
    {
        return [];
    }

    public function apply(Model $model, array $input, bool $detectChanges): array
    {
        if (!$model instanceof ContentModel || !\array_key_exists(self::FIELD, $input) || $input[self::FIELD] === null) {
            return [];
        }

        // The model's type, not the input's: the core mapper has already set
        // it by the time providers run, so a type change in the same call is
        // validated against the NEW type's config.
        $type = (string) $model->type;

        if (!RsceElements::isRsceType($type)) {
            throw new \InvalidArgumentException(sprintf('rsce_data only exists on RSCE elements (types starting with "rsce_"), not on "%s".', $type));
        }

        $new = $this->rsce->write($type, $input[self::FIELD], $model->{self::FIELD});

        if ($detectChanges && !RsceElements::differs($new, $model->{self::FIELD})) {
            return [];
        }

        $model->{self::FIELD} = $new;

        return [self::FIELD];
    }
}
