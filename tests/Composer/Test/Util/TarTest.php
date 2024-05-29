<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Util;

use Composer\Util\Tar;
use Composer\Test\TestCase;

/**
 * @author Wissem Riahi <wissemr@gmail.com>
 */
class TarTest extends TestCase
{
    public function testReturnsNullifTheTarIsNotFound(): void
    {
        $result = Tar::getComposerJson(__DIR__.'/Fixtures/Tar/invalid.zip');

        self::assertNull($result);
    }

    public function testReturnsNullIfTheTarIsEmpty(): void
    {
        $result = Tar::getComposerJson(__DIR__.'/Fixtures/Tar/empty.tar.gz');
        self::assertNull($result);
    }

    public function testThrowsExceptionIfTheTarHasNoComposerJson(): void
    {
        self::expectException('RuntimeException');
        Tar::getComposerJson(__DIR__.'/Fixtures/Tar/nojson.tar.gz');
    }

    public function testThrowsExceptionIfTheComposerJsonIsInASubSubfolder(): void
    {
        self::expectException('RuntimeException');
        Tar::getComposerJson(__DIR__.'/Fixtures/Tar/subfolders.tar.gz');
    }

    public function testReturnsComposerJsonInTarRoot(): void
    {
        $result = Tar::getComposerJson(__DIR__.'/Fixtures/Tar/root.tar.gz');
        self::assertEquals("{\n    \"name\": \"foo/bar\"\n}\n", $result);
    }

    public function testReturnsComposerJsonInFirstFolder(): void
    {
        $result = Tar::getComposerJson(__DIR__.'/Fixtures/Tar/folder.tar.gz');
        self::assertEquals("{\n    \"name\": \"foo/bar\"\n}\n", $result);
    }

    public function testMultipleTopLevelDirsIsInvalid(): void
    {
        self::expectException('RuntimeException');
        Tar::getComposerJson(__DIR__.'/Fixtures/Tar/multiple.tar.gz');
    }
}
