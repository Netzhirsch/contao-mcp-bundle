<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Tool\DeepL;

use Netzhirsch\ContaoMcpBundle\Service\AuditedUpdater;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\DeepL\TranslatableFields;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslatableFields::class)]
final class TranslatableFieldsTest extends TestCase
{
    /**
     * The registry decides what a translator is allowed to overwrite, so the
     * dangerous direction is a field slipping IN, not one being forgotten.
     * These names are machine tokens: a URL segment, a CSS hook, a template
     * name, a backend label, a form field's POST key. Translating any of them
     * breaks the site quietly, and `alias` is the one that keeps looking
     * tempting because it is derived from prose.
     *
     * @return iterable<string, array{string}>
     */
    public static function forbiddenFields(): iterable
    {
        foreach (['alias', 'cssID', 'customTpl', 'type', 'name', 'robots', 'canonicalLink'] as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('forbiddenFields')]
    public function testMachineFieldsAreNeverTranslatable(string $field): void
    {
        foreach (TranslatableFields::tables() as $table) {
            self::assertNotContains(
                $field,
                TranslatableFields::all($table),
                sprintf('%s.%s must not be translatable — it is a machine value, not prose.', $table, $field),
            );
        }
    }

    /**
     * Every table that can be translated must have a way back into the
     * database, or the failure only shows up on real content at save time.
     */
    public function testEveryTranslatableTableHasAnAuditedUpdater(): void
    {
        self::assertSame(
            [],
            array_diff(TranslatableFields::tables(), AuditedUpdater::tables()),
            'A table is translatable but AuditedUpdater cannot write it.',
        );
    }

    public function testEveryTableOffersAtLeastOneDefaultField(): void
    {
        foreach (TranslatableFields::tables() as $table) {
            self::assertNotEmpty(
                TranslatableFields::defaults($table),
                sprintf('%s would translate nothing when the caller passes no fields.', $table),
            );
        }
    }

    public function testDefaultsAreASubsetOfAllFields(): void
    {
        foreach (TranslatableFields::tables() as $table) {
            self::assertSame(
                [],
                array_diff(TranslatableFields::defaults($table), TranslatableFields::all($table)),
                sprintf('%s has a default field that is not registered.', $table),
            );
        }
    }

    public function testEveryFieldDeclaresAKnownFormat(): void
    {
        $known = [
            TranslatableFields::FORMAT_TEXT,
            TranslatableFields::FORMAT_HTML,
            TranslatableFields::FORMAT_HEADLINE,
            TranslatableFields::FORMAT_LIST,
            TranslatableFields::FORMAT_MATRIX,
        ];

        foreach (TranslatableFields::tables() as $table) {
            foreach (TranslatableFields::all($table) as $field) {
                self::assertContains(
                    TranslatableFields::formatOf($table, $field),
                    $known,
                    sprintf('%s.%s declares a format nothing can decode.', $table, $field),
                );
            }
        }
    }

    public function testNoSelectionFallsBackToTheDefaults(): void
    {
        $resolved = TranslatableFields::resolve('tl_page', null);

        self::assertSame(TranslatableFields::defaults('tl_page'), $resolved['fields']);
        self::assertSame([], $resolved['ignored']);
    }

    /**
     * An unknown field name is reported, not fatal — the same contract every
     * other write tool in this bundle uses.
     */
    public function testUnknownFieldsAreReportedAndTheRestIsKept(): void
    {
        $resolved = TranslatableFields::resolve('tl_page', ['title', 'nonsense', 'description']);

        self::assertSame(['title', 'description'], $resolved['fields']);
        self::assertSame(['nonsense'], $resolved['ignored']);
    }

    public function testARequestedNonDefaultFieldIsAccepted(): void
    {
        $resolved = TranslatableFields::resolve('tl_content', ['html']);

        self::assertSame(['html'], $resolved['fields']);
        self::assertNotContains('html', TranslatableFields::defaults('tl_content'));
    }

    public function testRepeatedFieldNamesAreCollapsed(): void
    {
        $resolved = TranslatableFields::resolve('tl_page', ['title', 'title']);

        self::assertSame(['title'], $resolved['fields']);
    }

    public function testAnUnknownTableKnowsNothing(): void
    {
        self::assertFalse(TranslatableFields::knows('tl_user'));
        self::assertSame([], TranslatableFields::all('tl_user'));
        self::assertSame([], TranslatableFields::defaults('tl_user'));
        self::assertNull(TranslatableFields::formatOf('tl_user', 'username'));
    }
}
