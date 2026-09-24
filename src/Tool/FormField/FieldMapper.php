<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\FormField;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FormFieldModel;
use Netzhirsch\ContaoMcpBundle\Service\DcaPalette;
use Netzhirsch\ContaoMcpBundle\Service\ProviderFields;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\Rsce\RsceElements;

/**
 * Field mapper for tl_form_field. The valid column set depends on the field
 * type (text / textarea / select / radio / checkbox / captcha / submit / …),
 * so we resolve the allowed fields at runtime against the live tl_form_field
 * DCA palette — same pattern as Modules and Content elements, including the
 * columns extensions declare through a FieldProvider (rsce_data on RSCE form
 * fields).
 */
final class FieldMapper
{
    /**
     * Always allowed regardless of type.
     *
     * @var list<string>
     */
    public const COMMON_FIELDS = [
        'pid', 'sorting', 'type', 'name', 'label', 'class', 'customTpl', 'active',
    ];

    /**
     * @var list<string>
     */
    private const BOOL_FIELDS = [
        'mandatory', 'multiple', 'storeFile', 'useHomeDir', 'doNotOverwrite',
        'multipleFiles', 'imageSubmit',
    ];

    /**
     * @var list<string>
     */
    private const INT_FIELDS = [
        'minlength', 'maxlength', 'minval', 'maxval', 'step', 'size', 'mSize', 'fSize',
        'maxImageWidth', 'maxImageHeight',
    ];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly ProviderFields $providerFields,
        private readonly RsceElements $rsce,
    ) {
    }

    /**
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    public function apply(FormFieldModel $f, array $input, bool $detectChanges = true): array
    {
        $this->framework->initialize();

        $type = $this->resolveType($f, $input);
        if (!$this->isKnownType($type)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown form field type "%s". Call form_field_types_list for the currently registered types.',
                $type,
            ));
        }

        $rejected = $this->rejectedFields($type, array_map(strval(...), array_keys($input)));
        if ($rejected !== []) {
            throw new \InvalidArgumentException((string) reset($rejected));
        }

        $changed = [];
        $touch = static function (string $field) use (&$changed): void {
            if (!\in_array($field, $changed, true)) {
                $changed[] = $field;
            }
        };

        // Extension-owned columns are left to their provider: it may merge
        // into the stored value (rsce_data does), which the generic cast below
        // would already have overwritten.
        $providerOwned = $this->providerFields->declaredFor('tl_form_field');

        foreach ($input as $field => $value) {
            if ($value === null || \in_array($field, $providerOwned, true)) {
                continue;
            }
            $column = $field === 'active' ? 'invisible' : $field;
            $newValue = $this->castValue($field, $value);
            if (!$detectChanges || self::isDifferent($f->{$column}, $newValue)) {
                $f->{$column} = $newValue;
                $touch($field);
            }
        }

        // Providers last; a refused value stops the write like any other.
        $fromProviders = $this->providerFields->apply('tl_form_field', $f, $input, $detectChanges, $type);
        if ($fromProviders['errors'] !== []) {
            throw new \InvalidArgumentException(implode(' ', $fromProviders['errors']));
        }
        foreach ($fromProviders['applied'] as $field) {
            $touch($field);
        }

        return $changed;
    }

    /**
     * @return list<string>
     */
    public function allowedFieldsFor(string $type): array
    {
        $fields = $this->resolvePalette($type)['fields'];

        return array_values(array_unique(array_merge(
            self::COMMON_FIELDS,
            $fields,
            // Extension-owned columns, only where their provider allows them.
            $this->providerFields->allowedFor('tl_form_field', $type),
        )));
    }

    /**
     * The fields among $fields a form field of this type cannot be written
     * with, each with the message the write tools report — for
     * form_field_create/_update and for entity_duplicate's overrides alike.
     * Core fields first, then the provider refusals.
     *
     * @param list<string> $fields
     *
     * @return array<string, string> field => message
     */
    public function rejectedFields(string $type, array $fields): array
    {
        $allowed = $this->allowedFieldsFor($type);
        $declared = $this->providerFields->declaredFor('tl_form_field');

        $rejected = [];
        $claimed = [];

        foreach ($fields as $field) {
            if (\in_array($field, $allowed, true)) {
                continue;
            }

            if (\in_array($field, $declared, true)) {
                $claimed[] = $field;
                continue;
            }

            $rejected[$field] = sprintf(
                'Field "%s" is not valid for form field type "%s". Allowed: %s',
                $field,
                $type,
                implode(', ', $allowed),
            );
        }

        return $rejected + $this->providerFields->refusals('tl_form_field', $claimed, $type);
    }

    /**
     * The sub-palettes this type actually opens, as selector => child fields.
     *
     * Answers the question a caller otherwise has to reverse-engineer from a
     * rejection: which of these fields only exist because a toggle is in the
     * palette, and which toggle is it.
     *
     * @return array<string, list<string>>
     */
    public function subpalettesFor(string $type): array
    {
        return $this->resolvePalette($type)['subpalettes'];
    }

    /**
     * @return array{fields: list<string>, subpalettes: array<string, list<string>>}
     */
    private function resolvePalette(string $type): array
    {
        $this->framework->initialize();

        $adapter = $this->framework->getAdapter(Controller::class);
        $adapter->loadDataContainer('tl_form_field');

        $dca = $GLOBALS['TL_DCA']['tl_form_field'] ?? [];

        // An RSCE form field has no palette until its edit mask runs RSCE's
        // onload callback; rebuilt from the type's config (RscePalette).
        if (trim((string) ($dca['palettes'][$type] ?? '')) === '') {
            $palette = $this->rsce->paletteFor($type, $dca, 'tl_form_field');
            if ($palette !== null) {
                $dca['palettes'][$type] = $palette;
            }
        }

        return DcaPalette::resolve($dca, $type);
    }

    /**
     * Returns every form-field type Contao knows. Source: $GLOBALS['TL_FFL']
     * (form field labels) — a flat map type → ClassName, populated by core
     * and bundle contributions.
     *
     * @return list<string>
     */
    public function listTypes(): array
    {
        $this->framework->initialize();

        $ffl = $GLOBALS['TL_FFL'] ?? [];
        return array_values(array_filter(array_map('strval', array_keys($ffl))));
    }

    private function isKnownType(string $type): bool
    {
        return \in_array($type, $this->listTypes(), true);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function resolveType(FormFieldModel $f, array $input): string
    {
        if (\array_key_exists('type', $input) && $input['type'] !== null && $input['type'] !== '') {
            return (string) $input['type'];
        }
        $current = (string) $f->type;

        return $current !== '' ? $current : 'text';
    }


    private function castValue(string $field, mixed $value): mixed
    {
        if ($field === 'active') {
            return ((bool) $value) ? 0 : 1; // active=true → invisible=0
        }
        if (\in_array($field, self::BOOL_FIELDS, true)) {
            return $value ? 1 : 0;
        }
        if (\in_array($field, self::INT_FIELDS, true)) {
            return (int) $value;
        }
        if ($field === 'options') {
            // Expect list of {value, label, default?, group?}
            if (!\is_array($value)) {
                throw new \InvalidArgumentException('options must be a list of {value, label, default?, group?} objects');
            }
            $clean = [];
            foreach ($value as $opt) {
                if (!\is_array($opt)) {
                    throw new \InvalidArgumentException('options entries must be objects');
                }
                $clean[] = [
                    'value' => (string) ($opt['value'] ?? ''),
                    'label' => (string) ($opt['label'] ?? ''),
                    'default' => !empty($opt['default']) ? '1' : '',
                    'group' => !empty($opt['group']) ? '1' : '',
                ];
            }
            return $clean === [] ? '' : serialize($clean);
        }
        if ($field === 'pid') {
            return (int) $value;
        }

        return $value;
    }

    private static function isDifferent(mixed $current, mixed $next): bool
    {
        if (\is_array($current) || \is_array($next)) {
            return serialize($current) !== serialize($next);
        }
        return (string) $current !== (string) $next;
    }
}
