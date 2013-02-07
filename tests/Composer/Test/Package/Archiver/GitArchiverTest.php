<?php

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

use Composer\Package\Archiver\GitArchiver;

/**
 * @author Till Klampaeckel <till@php.net>
 * @author Matthieu Moquet <matthieu@moquet.net>
 */
class GitArchiverTest extends ArchiverTest
{
    protected $targetFile;

    public function testZipArchive()
    {
        // Set up repository
        $this->setupGitRepo();
        $package = $this->setupPackage();
        $target  = sys_get_temp_dir().'/composer_archiver_test.zip';

        // Test archive
        $archiver = new GitArchiver();
        $archiver->archive($package->getSourceUrl(), $target, 'zip', 'master^1');
        $this->assertFileExists($target);

        unlink($target);
    }

    public function testTarArchive()
    {
        // Set up repository
        $this->setupGitRepo();
        $package = $this->setupPackage();
        $target  = sys_get_temp_dir().'/composer_archiver_test.tar';

        // Test archive
        $archiver = new GitArchiver();
        $archiver->archive($package->getSourceUrl(), $target, 'tar', 'master^1');
        $this->assertFileExists($target);

        unlink($target);
    }

    /**
     * Create local git repository to run tests against!
     */
    protected function setupGitRepo()
    {
        $currentWorkDir = getcwd();
        chdir($this->testDir);

        $result = $this->process->execute('git init -q');
        if ($result > 0) {
            chdir($currentWorkDir);
            throw new \RuntimeException('Could not init: '.$this->process->getErrorOutput());
        }

        $result = file_put_contents('b', 'a');
        if (false === $result) {
            chdir($currentWorkDir);
            throw new \RuntimeException('Could not save file.');
        }

        $result = $this->process->execute('git add b && git commit -m "commit b" -q');
        if ($result > 0) {
            chdir($currentWorkDir);
            throw new \RuntimeException('Could not commit: '.$this->process->getErrorOutput());
        }

        $result = file_put_contents('d', 'c');
        if (false === $result) {
            chdir($currentWorkDir);
            throw new \RuntimeException('Could not save file.');
        }

        $result = $this->process->execute('git add d && git commit -m "commit d" -q');
        if ($result > 0) {
            chdir($currentWorkDir);
            throw new \RuntimeException('Could not commit: '.$this->process->getErrorOutput());
        }

        chdir($currentWorkDir);
    }
}
