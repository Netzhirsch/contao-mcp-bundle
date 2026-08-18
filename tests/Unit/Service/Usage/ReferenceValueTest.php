<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service\Usage;

use Contao\StringUtil;
use Netzhirsch\ContaoMcpBundle\Service\Usage\ReferenceFieldMap;
use Netzhirsch\ContaoMcpBundle\Service\Usage\ReferenceValue;
use Netzhirsch\ContaoMcpBundle\Service\Usage\UsageTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The rules that turn a raw column value into "yes, this really points here".
 *
 * Every encoding Contao uses is represented, with its specific trap: an image
 * size hiding behind two other numbers, a module id sitting next to an
 * `enable` flag that looks just like it, a UUID stored as raw bytes.
 */
#[CoversClass(ReferenceValue::class)]
final class ReferenceValueTest extends TestCase
{
    private const UUID = 'a1b2c3d4-0000-1111-2222-333344445555';
    private const OTHER_UUID = 'ffffffff-0000-1111-2222-333344445555';

    public function testIntColumn(): void
    {
        self::assertTrue($this->matchesRecord('42', ReferenceFieldMap::ENC_INT));
        self::assertTrue($this->matchesRecord(42, ReferenceFieldMap::ENC_INT));
        self::assertFalse($this->matchesRecord('421', ReferenceFieldMap::ENC_INT));
        self::assertFalse($this->matchesRecord('0', ReferenceFieldMap::ENC_INT));
        self::assertFalse($this->matchesRecord(null, ReferenceFieldMap::ENC_INT));
    }

    public function testSerializedIdList(): void
    {
        self::assertTrue($this->matchesRecord(serialize(['7', '42']), ReferenceFieldMap::ENC_INT_LIST));
        self::assertTrue($this->matchesRecord(serialize([7, 42]), ReferenceFieldMap::ENC_INT_LIST));
        self::assertFalse($this->matchesRecord(serialize(['7', '421']), ReferenceFieldMap::ENC_INT_LIST));
        self::assertFalse($this->matchesRecord(serialize([]), ReferenceFieldMap::ENC_INT_LIST));
        self::assertFalse($this->matchesRecord('not serialized', ReferenceFieldMap::ENC_INT_LIST));
    }

    /**
     * `serialize(['42', '300', '5'])` is width 42, height 300, size id 5.
     * Reading anything but the third element would report an image size as
     * used by every element that happens to be 42 pixels wide.
     */
    public function testImageSizeReadsOnlyTheThirdElement(): void
    {
        $target = $this->target(5);

        self::assertTrue(ReferenceValue::matches(serialize(['', '', '5']), ReferenceFieldMap::ENC_IMAGE_SIZE, $target));
        self::assertTrue(ReferenceValue::matches(serialize(['100', '200', '5']), ReferenceFieldMap::ENC_IMAGE_SIZE, $target));
        self::assertFalse(ReferenceValue::matches(serialize(['5', '200', 'crop']), ReferenceFieldMap::ENC_IMAGE_SIZE, $target));
        self::assertFalse(ReferenceValue::matches(serialize(['100', '5', '']), ReferenceFieldMap::ENC_IMAGE_SIZE, $target));
    }

    /**
     * The trap that would have made this feature unusable: every layout entry
     * carries `enable => 1`, so a naive "is this id anywhere in the array"
     * search reports EVERY layout as a user of module id 1.
     */
    public function testModuleWizardReadsOnlyTheModuleKey(): void
    {
        $layout = serialize([
            ['mod' => '23', 'col' => 'header', 'enable' => '1'],
            ['mod' => '0', 'col' => 'main', 'enable' => '1'],
        ]);

        self::assertTrue(ReferenceValue::matches($layout, ReferenceFieldMap::ENC_MODULE_WIZARD, $this->target(23)));
        self::assertFalse(
            ReferenceValue::matches($layout, ReferenceFieldMap::ENC_MODULE_WIZARD, $this->target(1)),
            'enable => 1 must not read as a reference to module id 1',
        );
    }

    public function testBinaryUuidColumn(): void
    {
        $target = $this->fileTarget();

        self::assertTrue(ReferenceValue::matches(StringUtil::uuidToBin(self::UUID), ReferenceFieldMap::ENC_UUID, $target));
        self::assertFalse(ReferenceValue::matches(StringUtil::uuidToBin(self::OTHER_UUID), ReferenceFieldMap::ENC_UUID, $target));
        self::assertFalse(ReferenceValue::matches(self::UUID, ReferenceFieldMap::ENC_UUID, $target));
    }

    public function testSerializedUuidList(): void
    {
        $target = $this->fileTarget();

        self::assertTrue(ReferenceValue::matches(
            serialize([StringUtil::uuidToBin(self::OTHER_UUID), StringUtil::uuidToBin(self::UUID)]),
            ReferenceFieldMap::ENC_UUID_LIST,
            $target,
        ));

        self::assertFalse(ReferenceValue::matches(
            serialize([StringUtil::uuidToBin(self::OTHER_UUID)]),
            ReferenceFieldMap::ENC_UUID_LIST,
            $target,
        ));
    }

    /**
     * Deleting a folder deletes the files in it, so a column pointing at any
     * of them is a reason to refuse.
     */
    public function testFolderMatchesOnTheUuidsItContains(): void
    {
        $folder = new UsageTarget(
            type: 'folder',
            table: 'tl_files',
            id: 3,
            label: 'theme',
            uuid: self::UUID,
            path: 'files/theme',
            isFolder: true,
            contents: [self::OTHER_UUID],
        );

        self::assertTrue(ReferenceValue::matches(StringUtil::uuidToBin(self::OTHER_UUID), ReferenceFieldMap::ENC_UUID, $folder));
    }

    public function testTemplateNameIsMatchedExactly(): void
    {
        $target = new UsageTarget(
            type: 'template',
            table: UsageTarget::TABLE_TEMPLATES,
            id: 0,
            label: 'ce_text_my',
            aliases: ['ce_text_my'],
            path: 'templates/ce_text_my.html5',
        );

        self::assertTrue(ReferenceValue::matches('ce_text_my', ReferenceFieldMap::ENC_TEMPLATE_NAME, $target));
        self::assertFalse(ReferenceValue::matches('ce_text_my_variant', ReferenceFieldMap::ENC_TEMPLATE_NAME, $target));
        self::assertFalse(ReferenceValue::matches('ce_text', ReferenceFieldMap::ENC_TEMPLATE_NAME, $target));
        self::assertFalse(ReferenceValue::matches('', ReferenceFieldMap::ENC_TEMPLATE_NAME, $target));
    }

    public function testUnknownEncodingNeverMatches(): void
    {
        // A future encoding that reaches an old verifier must fail closed on
        // the MATCH side — reporting nothing rather than reporting nonsense.
        self::assertFalse($this->matchesRecord('42', 'something_new'));
    }

    private function matchesRecord(mixed $value, string $encoding): bool
    {
        return ReferenceValue::matches($value, $encoding, $this->target(42));
    }

    private function target(int $id): UsageTarget
    {
        return new UsageTarget('module', 'tl_module', $id, 'Demo');
    }

    private function fileTarget(): UsageTarget
    {
        return new UsageTarget(
            type: 'file',
            table: 'tl_files',
            id: 1,
            label: 'logo.svg',
            uuid: self::UUID,
            path: 'files/theme/logo.svg',
        );
    }
}
