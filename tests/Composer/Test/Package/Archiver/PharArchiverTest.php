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

namespace Composer\Test\Package\Archiver;

use Composer\Package\Archiver\PharArchiver;
use Composer\Util\Platform;

class PharArchiverTest extends ArchiverTest
{
    public function testTarArchive(): void
    {
        // Set up repository
        $this->setupDummyRepo();
        $package = $this->setupPackage();
        $target = self::getUniqueTmpDirectory().'/composer_archiver_test.tar';

        // Test archive
        $archiver = new PharArchiver();
        $archiver->archive($package->getSourceUrl(), $target, 'tar', array('foo/bar', 'baz', '!/foo/bar/baz'));
        $this->assertFileExists($target);

        $this->filesystem->removeDirectory(dirname($target));
    }

    public function testZipArchive(): void
    {
        // Set up repository
        $this->setupDummyRepo();
        $package = $this->setupPackage();
        $target = self::getUniqueTmpDirectory().'/composer_archiver_test.zip';

        // Test archive
        $archiver = new PharArchiver();
        $archiver->archive($package->getSourceUrl(), $target, 'zip');
        $this->assertFileExists($target);

        $this->filesystem->removeDirectory(dirname($target));
    }

    /**
     * Create a local dummy repository to run tests against!
     *
     * @return void
     */
    protected function setupDummyRepo(): void
    {
        $currentWorkDir = Platform::getCwd();
        chdir($this->testDir);

        $this->writeFile('file.txt', 'content', $currentWorkDir);
        $this->writeFile('foo/bar/baz', 'content', $currentWorkDir);
        $this->writeFile('foo/bar/ignoreme', 'content', $currentWorkDir);
        $this->writeFile('x/baz', 'content', $currentWorkDir);
        $this->writeFile('x/includeme', 'content', $currentWorkDir);

        chdir($currentWorkDir);
    }

    /**
     * @param string $path
     * @param string $content
     * @param string $currentWorkDir
     *
     * @return void
     */
    protected function writeFile(string $path, string $content, string $currentWorkDir): void
    {
        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        $result = file_put_contents($path, $content);
        if (false === $result) {
            chdir($currentWorkDir);
            throw new \RuntimeException('Could not save file.');
        }
    }
}
