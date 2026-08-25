<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Service;

use Netzhirsch\ContaoMcpBundle\Tool\Content\FieldMapper as ContentFieldMapper;
use Netzhirsch\ContaoMcpBundle\Tool\FormField\FieldMapper as FormFieldFieldMapper;
use Netzhirsch\ContaoMcpBundle\Tool\Module\FieldMapper as ModuleFieldMapper;

/**
 * Which columns a RECORD may be written with, given its type.
 *
 * Three Contao tables are type-driven: tl_content, tl_module and tl_form_field
 * each keep one wide table and decide per row which of its columns the type
 * actually has. A tool that picks fields per TABLE — "translate headline and
 * text wherever they are filled" — therefore plans columns a given row cannot
 * take, and the update refuses the whole record.
 *
 * That refusal is all-or-nothing, which is what makes it expensive: a headline
 * element carrying a leftover `text` value from an earlier type change loses
 * its headline translation too, because one invalid field came along. In a
 * 61-page run the result is a handful of records that stayed in the source
 * language inside an otherwise translated tree — and nothing in the backend
 * shows which ones.
 *
 * The answer is to ask the same source `content_palette_get(type)` answers
 * from, per record, BEFORE planning the write. That also stops the caller
 * paying a translation service for text that could never have been stored.
 */
final class TypePaletteFields
{
    public function __construct(
        private readonly ContentFieldMapper $content,
        private readonly ModuleFieldMapper $module,
        private readonly FormFieldFieldMapper $formField,
    ) {
    }

    public function isTypeDriven(string $table): bool
    {
        return \in_array($table, ['tl_content', 'tl_module', 'tl_form_field'], true);
    }

    /**
     * The writable columns for one record's type, or null when the table has no
     * per-type palette (tl_page, tl_news, …) and the table-level answer stands.
     *
     * A row without a usable `type` also yields null: guessing a palette would
     * be worse than leaving the existing validation to reject the write.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>|null
     */
    public function writableFor(string $table, array $row): ?array
    {
        if (!$this->isTypeDriven($table)) {
            return null;
        }

        $type = $this->typeOf($row);
        if ($type === null) {
            return null;
        }

        try {
            return match ($table) {
                'tl_content' => $this->content->allowedFieldsFor($type),
                'tl_module' => $this->module->allowedFieldsFor($type),
                'tl_form_field' => $this->formField->allowedFieldsFor($type),
                default => null,
            };
        } catch (\Throwable) {
            // An unknown type has no palette to narrow against. Let the write
            // path report it rather than silently dropping every field.
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function typeOf(array $row): ?string
    {
        foreach ($row as $key => $value) {
            if (strcasecmp((string) $key, 'type') !== 0) {
                continue;
            }

            return \is_string($value) && trim($value) !== '' ? $value : null;
        }

        return null;
    }
}
