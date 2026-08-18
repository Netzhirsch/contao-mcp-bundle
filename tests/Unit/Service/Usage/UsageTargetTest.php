<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Service\Usage;

use Netzhirsch\ContaoMcpBundle\Service\Usage\UsageScanner;
use Netzhirsch\ContaoMcpBundle\Service\Usage\UsageTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The identifiers a reference lookup searches for, and the rule that decides
 * whether a finding is allowed to stop a deletion.
 */
#[CoversClass(UsageTarget::class)]
#[CoversClass(UsageScanner::class)]
final class UsageTargetTest extends TestCase
{
    public function testRecordIsSearchedByIdAndAlias(): void
    {
        $target = new UsageTarget('page', 'tl_page', 42, 'Contact', ['contact', 'kontakt']);

        self::assertSame(['42', 'contact', 'kontakt'], $target->insertTagNeedles());
    }

    /**
     * `{{file::…}}` takes a UUID or a path — never the tl_files id. Searching
     * for the id anyway would match every unrelated `{{link::<same number>}}`
     * and block file deletions for no reason.
     */
    public function testFileIsNeverSearchedByItsNumericId(): void
    {
        $target = new UsageTarget(
            type: 'file',
            table: 'tl_files',
            id: 7,
            label: 'logo.svg',
            uuid: 'a1b2c3d4-0000-1111-2222-333344445555',
            path: 'files/theme/logo.svg',
        );

        self::assertNotContains('7', $target->insertTagNeedles());
        self::assertSame(
            ['a1b2c3d4-0000-1111-2222-333344445555', 'files/theme/logo.svg'],
            $target->insertTagNeedles(),
        );
    }

    public function testFolderStandsForEverythingInsideIt(): void
    {
        $target = new UsageTarget(
            type: 'folder',
            table: 'tl_files',
            id: 3,
            label: 'theme',
            uuid: 'aaaaaaaa-0000-0000-0000-000000000000',
            path: 'files/theme',
            isFolder: true,
            contents: ['bbbbbbbb-0000-0000-0000-000000000000'],
        );

        // Deleting the folder deletes the files in it, so a reference to any
        // of them is a reference to this deletion.
        self::assertSame(
            ['aaaaaaaa-0000-0000-0000-000000000000', 'bbbbbbbb-0000-0000-0000-000000000000'],
            $target->uuids(),
        );
    }

    public function testOnlyProvableAndBreakingReferencesBlock(): void
    {
        self::assertTrue(UsageScanner::blocks([
            'confidence' => UsageScanner::CONFIDENCE_CERTAIN,
            'blocking' => true,
        ]));

        // A backend permission mount: real, but a stale entry is harmless.
        self::assertFalse(UsageScanner::blocks([
            'confidence' => UsageScanner::CONFIDENCE_CERTAIN,
            'blocking' => false,
        ]));

        // A file name that merely looks like a match.
        self::assertFalse(UsageScanner::blocks([
            'confidence' => UsageScanner::CONFIDENCE_POSSIBLE,
            'blocking' => false,
        ]));

        // Anything malformed must not block — the guard fails open, loudly.
        self::assertFalse(UsageScanner::blocks([]));
    }
}
