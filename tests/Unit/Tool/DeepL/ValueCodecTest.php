<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Tool\DeepL;

use Netzhirsch\ContaoMcpBundle\Tool\Extension\DeepL\TranslatableFields;
use Netzhirsch\ContaoMcpBundle\Tool\Extension\DeepL\ValueCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Extraction and rebuilding are index-aligned by contract. If they ever drift
 * apart, translations land in the wrong slots — a table element would come back
 * with its cells shuffled, and nothing would report an error.
 */
#[CoversClass(ValueCodec::class)]
final class ValueCodecTest extends TestCase
{
    public function testAHeadlineKeepsItsUnitAndOnlyTheTextChanges(): void
    {
        $stored = serialize(['value' => 'Guten Tag', 'unit' => 'h3']);

        self::assertSame(['Guten Tag'], ValueCodec::extract(TranslatableFields::FORMAT_HEADLINE, $stored));
        self::assertSame(
            ['value' => 'Good day', 'unit' => 'h3'],
            ValueCodec::rebuild(TranslatableFields::FORMAT_HEADLINE, $stored, ['Good day']),
        );
    }

    /**
     * Older records (and the shorthand the field mappers accept) store a bare
     * string. It must still translate, and it must not lose the record.
     */
    public function testAPlainStringHeadlineStillWorks(): void
    {
        self::assertSame(['Guten Tag'], ValueCodec::extract(TranslatableFields::FORMAT_HEADLINE, 'Guten Tag'));
        self::assertSame(
            ['value' => 'Good day', 'unit' => 'h2'],
            ValueCodec::rebuild(TranslatableFields::FORMAT_HEADLINE, 'Guten Tag', ['Good day']),
        );
    }

    public function testAnEmptyHeadlineDoesNotInventContent(): void
    {
        self::assertSame([''], ValueCodec::extract(TranslatableFields::FORMAT_HEADLINE, ''));
        self::assertSame(
            ['value' => '', 'unit' => 'h2'],
            ValueCodec::rebuild(TranslatableFields::FORMAT_HEADLINE, '', ['']),
        );
    }

    public function testAListRoundTripsItemByItem(): void
    {
        $stored = serialize(['Eins', 'Zwei', 'Drei']);

        self::assertSame(['Eins', 'Zwei', 'Drei'], ValueCodec::extract(TranslatableFields::FORMAT_LIST, $stored));
        self::assertSame(
            ['One', 'Two', 'Three'],
            ValueCodec::rebuild(TranslatableFields::FORMAT_LIST, $stored, ['One', 'Two', 'Three']),
        );
    }

    /**
     * A table element is the case where a drift between the two halves would be
     * invisible: the cell count stays the same, only the arrangement breaks.
     */
    public function testAMatrixKeepsItsRowShape(): void
    {
        $stored = serialize([['Kopf A', 'Kopf B'], ['Zelle 1', 'Zelle 2'], ['Zelle 3', 'Zelle 4']]);

        $cells = ValueCodec::extract(TranslatableFields::FORMAT_MATRIX, $stored);
        self::assertSame(['Kopf A', 'Kopf B', 'Zelle 1', 'Zelle 2', 'Zelle 3', 'Zelle 4'], $cells);

        $translated = ['Head A', 'Head B', 'Cell 1', 'Cell 2', 'Cell 3', 'Cell 4'];
        self::assertSame(
            [['Head A', 'Head B'], ['Cell 1', 'Cell 2'], ['Cell 3', 'Cell 4']],
            ValueCodec::rebuild(TranslatableFields::FORMAT_MATRIX, $stored, $translated),
        );
    }

    public function testARaggedMatrixKeepsEachRowsOwnLength(): void
    {
        $stored = serialize([['A'], ['B', 'C', 'D'], ['E', 'F']]);

        self::assertSame(['A', 'B', 'C', 'D', 'E', 'F'], ValueCodec::extract(TranslatableFields::FORMAT_MATRIX, $stored));
        self::assertSame(
            [['a'], ['b', 'c', 'd'], ['e', 'f']],
            ValueCodec::rebuild(TranslatableFields::FORMAT_MATRIX, $stored, ['a', 'b', 'c', 'd', 'e', 'f']),
        );
    }

    public function testTextAndHtmlAreSingleSlots(): void
    {
        foreach ([TranslatableFields::FORMAT_TEXT, TranslatableFields::FORMAT_HTML] as $format) {
            self::assertSame(['<p>Hallo</p>'], ValueCodec::extract($format, '<p>Hallo</p>'));
            self::assertSame('<p>Hello</p>', ValueCodec::rebuild($format, '<p>Hallo</p>', ['<p>Hello</p>']));
        }
    }

    /**
     * Whatever extract() produced, rebuild() must consume — for every format,
     * with the values unchanged this is the identity.
     */
    public function testExtractAndRebuildAreIndexAligned(): void
    {
        $cases = [
            TranslatableFields::FORMAT_TEXT => 'Hallo',
            TranslatableFields::FORMAT_HTML => '<p>Hallo</p>',
            TranslatableFields::FORMAT_LIST => serialize(['Eins', 'Zwei']),
            TranslatableFields::FORMAT_MATRIX => serialize([['A', 'B'], ['C', 'D']]),
        ];

        foreach ($cases as $format => $stored) {
            $slots = ValueCodec::extract($format, $stored);
            $rebuilt = ValueCodec::rebuild($format, $stored, $slots);

            self::assertSame(
                $slots,
                ValueCodec::extract($format, $rebuilt),
                sprintf('Format "%s" does not survive an extract → rebuild → extract cycle.', $format),
            );
        }
    }

    /**
     * The preview shows prose on both sides, so a headline must not answer with
     * a serialised blob on one side and an object on the other.
     */
    public function testDisplayShowsProseNotStructure(): void
    {
        self::assertSame(
            'Guten Tag',
            ValueCodec::display(TranslatableFields::FORMAT_HEADLINE, serialize(['value' => 'Guten Tag', 'unit' => 'h3'])),
        );
        self::assertSame(
            'Good day',
            ValueCodec::display(TranslatableFields::FORMAT_HEADLINE, ['value' => 'Good day', 'unit' => 'h3']),
        );
        self::assertSame(
            ['Eins', 'Zwei'],
            ValueCodec::display(TranslatableFields::FORMAT_LIST, serialize(['Eins', 'Zwei'])),
        );
    }

    public function testAnUnparsableStructuredValueYieldsNothingToTranslate(): void
    {
        self::assertSame([], ValueCodec::extract(TranslatableFields::FORMAT_LIST, ''));
        self::assertSame([], ValueCodec::extract(TranslatableFields::FORMAT_MATRIX, 'not serialised at all'));
    }
}
