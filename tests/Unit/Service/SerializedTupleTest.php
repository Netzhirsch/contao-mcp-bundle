<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service;

use Netzhirsch\ContaoMcpBundle\Service\SerializedTuple;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The grass-merkur report caught this one live:
 *
 *     content_update(id: 6969, fields: {headline: {unit: "h1"}})
 *     → applied: 1, updated: true, no error
 *     → the page's headline text was gone
 *
 * The report called the reverse direction safe. It is not — the element it was
 * tested on simply happened to be an h2 already, so resetting the unit to h2
 * looked like preservation. Both halves are covered here.
 */
#[CoversClass(SerializedTuple::class)]
final class SerializedTupleTest extends TestCase
{
    private const STORED = 'a:2:{s:5:"value";s:26:"Thank You for Your Message";s:4:"unit";s:2:"h1";}';

    public function testChangingOnlyTheUnitKeepsTheText(): void
    {
        $result = SerializedTuple::headline(['unit' => 'h3'], self::STORED);

        self::assertSame(['value' => 'Thank You for Your Message', 'unit' => 'h3'], $result);
    }

    public function testChangingOnlyTheTextKeepsTheLevel(): void
    {
        $result = SerializedTuple::headline(['value' => 'Danke für Ihre Nachricht'], self::STORED);

        self::assertSame(['value' => 'Danke für Ihre Nachricht', 'unit' => 'h1'], $result);
    }

    /**
     * The shorthand is the same case wearing a different hat: someone passing
     * a bare string is changing the text, not asking for h2.
     */
    public function testTheStringShorthandKeepsTheLevel(): void
    {
        $result = SerializedTuple::headline('Neuer Text', self::STORED);

        self::assertSame(['value' => 'Neuer Text', 'unit' => 'h1'], $result);
    }

    public function testBothAtOnceStillWorks(): void
    {
        $result = SerializedTuple::headline(['value' => 'X', 'unit' => 'h4'], self::STORED);

        self::assertSame(['value' => 'X', 'unit' => 'h4'], $result);
    }

    /**
     * Clearing has to stay possible — an explicit empty string is an
     * instruction, an absent key is not.
     */
    public function testAnExplicitEmptyStringStillClears(): void
    {
        $result = SerializedTuple::headline(['value' => ''], self::STORED);

        self::assertSame(['value' => '', 'unit' => 'h1'], $result);
    }

    public function testANewRecordFallsBackToTheDefaults(): void
    {
        self::assertSame(['value' => 'Neu', 'unit' => 'h2'], SerializedTuple::headline('Neu', ''));
        self::assertSame(['value' => '', 'unit' => 'h2'], SerializedTuple::headline([], null));
    }

    public function testAnUnreadableColumnDoesNotThrow(): void
    {
        $result = SerializedTuple::headline(['unit' => 'h3'], 'not serialised at all');

        self::assertSame(['value' => '', 'unit' => 'h3'], $result);
    }

    public function testAnAlreadyDecodedArrayIsAccepted(): void
    {
        $result = SerializedTuple::headline(['unit' => 'h5'], ['value' => 'Bestand', 'unit' => 'h2']);

        self::assertSame(['value' => 'Bestand', 'unit' => 'h5'], $result);
    }

    public function testARejectedInputTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SerializedTuple::headline(42, self::STORED);
    }

    // ───────────────────────── positional pairs ─────────────────────────

    public function testAPartialPairKeepsTheOtherHalf(): void
    {
        $stored = serialize(['meine-id', 'meine-klasse']);

        self::assertSame(
            ['meine-id', 'neue-klasse'],
            SerializedTuple::pair(['class' => 'neue-klasse'], $stored, 'id', 'class'),
        );
        self::assertSame(
            ['neue-id', 'meine-klasse'],
            SerializedTuple::pair(['id' => 'neue-id'], $stored, 'id', 'class'),
        );
    }

    public function testAPositionalPairStillWorks(): void
    {
        self::assertSame(
            ['oben', 'unten'],
            SerializedTuple::pair(['oben', 'unten'], '', 'top', 'bottom'),
        );
    }

    public function testAnExplicitEmptyHalfClears(): void
    {
        $stored = serialize(['meine-id', 'meine-klasse']);

        self::assertSame(
            ['meine-id', ''],
            SerializedTuple::pair(['class' => ''], $stored, 'id', 'class'),
        );
    }
}
