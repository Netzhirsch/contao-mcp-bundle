<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tests\Unit\Security;

use Netzhirsch\ContaoMcpBundle\Security\FileScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Regression for the gap a customer reported on 16.10.2026: a backend user
 * mounted on two subfolders saw the whole upload tree through `files_list`
 * (default `path=""`) and `files_search` (no `path`), because the permission
 * guard can only judge paths a call names and a listing names none.
 *
 * The two questions this class separates are what the fix turns on. Mixing
 * them up either leaks a sibling folder or locks a deep-mounted user out of
 * their own.
 */
#[CoversClass(FileScope::class)]
final class FileScopeTest extends TestCase
{
    public function testAMountAndEverythingBelowItIsAllowed(): void
    {
        $scope = new FileScope(['files/kundeA']);

        self::assertTrue($scope->allows('files/kundeA'));
        self::assertTrue($scope->allows('files/kundeA/vertraege'));
        self::assertTrue($scope->allows('files/kundeA/vertraege/preisliste.pdf'));
    }

    public function testEverythingOutsideTheMountIsRefused(): void
    {
        $scope = new FileScope(['files/kundeA']);

        self::assertFalse($scope->allows('files/kundeB'));
        self::assertFalse($scope->allows('files/AutoBackupDB/dump-2026-10-16.sql'));
        self::assertFalse($scope->allows('files/theme'));
    }

    /**
     * The exact shape of the reported leak: the upload root itself. It is not
     * a mount, so it is not readable — the listing of it is scoped instead.
     */
    public function testTheUploadRootIsNotItselfAllowed(): void
    {
        $scope = new FileScope(['files/kundeA']);

        self::assertFalse($scope->allows('files'));
        self::assertFalse($scope->allows(''));
    }

    /**
     * A sibling whose name merely starts with the mount's must not pass. Prefix
     * matching without the separator is the classic way this check goes wrong.
     */
    public function testASiblingWithTheMountAsNamePrefixIsRefused(): void
    {
        $scope = new FileScope(['files/kunde']);

        self::assertFalse($scope->allows('files/kunde-intern'));
        self::assertFalse($scope->allows('files/kunden'));
        self::assertTrue($scope->allows('files/kunde/a.jpg'));
    }

    public function testAUserWithoutMountsSeesNothing(): void
    {
        $scope = new FileScope([]);

        self::assertFalse($scope->allows('files/kundeA'));
        self::assertFalse($scope->mayDescend('files'));
        self::assertFalse($scope->mayDescend('files/kundeA'));
    }

    /**
     * Navigating to a deep mount has to work, or the fix would lock the user
     * out of the folder they are entitled to.
     */
    public function testAncestorsOfAMountCanBeOpenedButNotRead(): void
    {
        $scope = new FileScope(['files/kunde/a/bilder']);

        self::assertTrue($scope->mayDescend('files'));
        self::assertTrue($scope->mayDescend('files/kunde'));
        self::assertTrue($scope->mayDescend('files/kunde/a'));
        self::assertTrue($scope->mayDescend('files/kunde/a/bilder'));

        // Opening the way down is not the same as being inside it: a file lying
        // directly in an ancestor folder stays invisible.
        self::assertFalse($scope->allows('files/kunde'));
        self::assertFalse($scope->allows('files/kunde/a/notizen.txt'));
    }

    public function testAForeignBranchCannotBeOpened(): void
    {
        $scope = new FileScope(['files/kunde/a/bilder']);

        self::assertFalse($scope->mayDescend('files/kundeB'));
        self::assertFalse($scope->mayDescend('files/kunde/b'));
    }

    /**
     * Contao's own filemount voter compares case-insensitively (`/i`), and on
     * Windows and macOS the filesystem does too. A byte comparison here would
     * disagree with both.
     */
    public function testComparisonIsCaseInsensitive(): void
    {
        $scope = new FileScope(['files/KundeA']);

        self::assertTrue($scope->allows('files/kundea/bild.jpg'));
        self::assertTrue($scope->mayDescend('FILES/KundeA'));
    }

    public function testMountsAreNormalisedOnTheWayIn(): void
    {
        $scope = new FileScope(['/files/kundeA/', 'files\\kundeB', '', 'files/kundeA']);

        self::assertSame(['files/kundea', 'files/kundeb'], $scope->mounts());
        self::assertTrue($scope->allows('files/kundeB/x.png'));
    }
}
