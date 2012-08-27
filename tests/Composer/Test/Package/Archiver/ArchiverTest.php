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

use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Composer\Package\Package;

/**
 * @author Till Klampaeckel <till@php.net>
 * @author Matthieu Moquet <matthieu@moquet.net>
 */
abstract class ArchiverTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \Composer\Util\Filesystem
     */
    protected $filesystem;

    /**
     * @var \Composer\Util\ProcessExecutor
     */
    protected $process;

    /**
     * @var string
     */
    protected $testDir;

    public function setUp()
    {
        $this->filesystem = new Filesystem();
        $this->process    = new ProcessExecutor();
        $this->testDir    = sys_get_temp_dir().'/composer_archivertest_git_repository'.mt_rand();
    }

    /**
     * Create local git repository to run tests against!
     */
    protected function setupGitRepo()
    {
        $this->filesystem->removeDirectory($this->testDir);
        $this->filesystem->ensureDirectoryExists($this->testDir);

        $currentWorkDir = getcwd();
        chdir($this->testDir);

        $result = $this->process->execute('git init -q');
        if ($result > 0) {
            throw new \RuntimeException('Could not init: '.$this->process->getErrorOutput());
        }

        $result = file_put_contents('b', 'a');
        if (false === $result) {
            throw new \RuntimeException('Could not save file.');
        }

        $result = $this->process->execute('git add b && git commit -m "commit b" -q');
        if ($result > 0) {
            throw new \RuntimeException('Could not commit: '.$this->process->getErrorOutput());
        }

        chdir($currentWorkDir);
    }

    protected function removeGitRepo()
    {
        $this->filesystem->removeDirectory($this->testDir);
    }

    protected function setupPackage()
    {
        $package = new Package('archivertest/archivertest', 'master', 'master');
        $package->setSourceUrl(realpath($this->testDir));
        $package->setSourceReference('master');
        $package->setSourceType('git');

        return $package;
    }
}
