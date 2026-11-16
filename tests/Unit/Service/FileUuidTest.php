<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service;

use Netzhirsch\ContaoMcpBundle\Service\FileUuid;
use Netzhirsch\ContaoMcpBundle\Tool\Content\FieldMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The UUID spellings a fileTree value may arrive in, and which columns count
 * as fileTree columns. Paths need tl_files and are covered by the smoke test.
 *
 * The 32-hex form is the one that slipped: Contao's isStringUuid() knows only
 * the dashed lowercase spelling, while several tools read file fields back as
 * bare hex — so writing back what had just been read was taken for a path.
 */
#[CoversClass(FileUuid::class)]
final class FileUuidTest extends TestCase
{
    private const UUID = 'a1b2c3d4-e5f6-11ef-9a2b-0123456789ab';

    /**
     * @return iterable<string, array{string}>
     */
    public static function spellings(): iterable
    {
        yield 'dashed' => [self::UUID];
        yield 'dashed, upper case' => [strtoupper(self::UUID)];
        yield 'hex as the tools read it back' => [str_replace('-', '', self::UUID)];
        yield 'hex, upper case' => [strtoupper(str_replace('-', '', self::UUID))];
    }

    #[DataProvider('spellings')]
    public function testEverySpellingBecomesTheSameBinary(string $value): void
    {
        self::assertSame(hex2bin(str_replace('-', '', self::UUID)), FileUuid::toBinary($value, 'posterSRC'));
    }

    public function testBinaryIsPassedThrough(): void
    {
        $binary = (string) hex2bin(str_replace('-', '', self::UUID));

        self::assertSame($binary, FileUuid::toBinary($binary, 'posterSRC'));
    }

    public function testEmptyMeansNoFile(): void
    {
        self::assertNull(FileUuid::toBinary('', 'posterSRC'));
        self::assertNull(FileUuid::toBinary(null, 'posterSRC'));
        self::assertSame('', FileUuid::toBinaryList([], 'multiSRC'));
    }

    public function testAListIsStoredSerialised(): void
    {
        $binary = (string) hex2bin(str_replace('-', '', self::UUID));

        self::assertSame(serialize([$binary, $binary]), FileUuid::toBinaryList([self::UUID, str_replace('-', '', self::UUID)], 'multiSRC'));
        self::assertSame(serialize([$binary]), FileUuid::toBinaryList(self::UUID, 'multiSRC'), 'A single value is a list of one.');
    }

    public function testANonStringNamesTheField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"posterSRC"');

        FileUuid::toBinary(42, 'posterSRC');
    }

    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function definitions(): iterable
    {
        yield 'single, string sql' => [['inputType' => 'fileTree', 'sql' => 'binary(16) NULL'], 'single'];
        yield 'single, array sql' => [['inputType' => 'fileTree', 'sql' => ['type' => 'binary', 'length' => 16, 'fixed' => true, 'notnull' => false]], 'single'];
        yield 'multiple' => [['inputType' => 'fileTree', 'eval' => ['multiple' => true], 'sql' => 'blob NULL'], 'multiple'];
        yield 'multiple, array sql' => [['inputType' => 'fileTree', 'eval' => ['multiple' => true], 'sql' => ['type' => 'blob', 'notnull' => false]], 'multiple'];
        yield 'a fileTree that keeps the path as text' => [['inputType' => 'fileTree', 'sql' => "varchar(255) NOT NULL default ''"], null];
        yield 'no fileTree' => [['inputType' => 'text', 'sql' => 'binary(16) NULL'], null];
        yield 'no definition' => [null, null];
    }

    #[DataProvider('definitions')]
    public function testWhichColumnsHoldFileUuids(mixed $definition, ?string $kind): void
    {
        self::assertSame($kind, FieldMapper::fileTreeKindOf($definition));
    }
}
