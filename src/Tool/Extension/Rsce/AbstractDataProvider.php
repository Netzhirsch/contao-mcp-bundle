<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce;

use Contao\Model;
use Netzhirsch\ContaoMcpBundle\Tool\Contract\FieldProvider;

/**
 * Makes `rsce_data` writable on RSCE records of one table.
 *
 * The column carries the whole configuration of an RSCE element — grid, button
 * URL, background colour — and was readable through the *_get tools but refused
 * by every write tool: it is not a palette field, it is the storage behind RSCE's
 * virtual fields. Reported against v1.33.0 with the themore theme, where the only
 * way to get a configured element was copying an existing one with
 * entity_duplicate and a raw override.
 *
 * RSCE keeps the column on tl_content, tl_module and tl_form_field — a config
 * registers its element as content element, frontend module and form field
 * unless it restricts `types` — so there is one provider per table, all of them
 * this class. Only for `rsce_*` types, only with RSCE installed; the value rules
 * (merge, storage form, key check against the type's config) are in
 * {@see RsceData}.
 *
 * The read side is left alone on purpose: the *_get tools keep returning the JSON
 * string they always returned, and a write accepts that string back unchanged.
 */
abstract class AbstractDataProvider implements FieldProvider
{
    private const FIELD = 'rsce_data';

    public function __construct(
        private readonly RsceElements $rsce,
    ) {
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
        if ($model::getTable() !== $this->getTable() || !\array_key_exists(self::FIELD, $input) || $input[self::FIELD] === null) {
            return [];
        }

        // The model's type, not the input's: the core mapper has already set
        // it by the time providers run, so a type change in the same call is
        // validated against the NEW type's config.
        $type = (string) $model->type;

        if (!RsceElements::isRsceType($type)) {
            throw new \InvalidArgumentException(sprintf('rsce_data only exists on RSCE elements (types starting with "rsce_"), not on "%s".', $type));
        }

        $new = $this->rsce->write($type, $input[self::FIELD], $model->{self::FIELD}, $this->getTable());

        if ($detectChanges && !RsceElements::differs($new, $model->{self::FIELD})) {
            return [];
        }

        $model->{self::FIELD} = $new;

        return [self::FIELD];
    }
}
