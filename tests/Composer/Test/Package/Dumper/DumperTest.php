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

namespace Composer\Test\Package\Dumper;

use Composer\Package\MemoryPackage;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;

abstract class DumperTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \Composer\Util\Filesystem
     */
    protected $fs;

    /**
     * @var \Composer\Util\ProcessExecutor
     */
    protected $process;

    /**
     * @var string
     */
    protected $testdir = '';

    public function setUp()
    {
        $this->fs      = new Filesystem;
		$this->process = new ProcessExecutor;
        $this->testdir = sys_get_temp_dir() . '/composer_dumpertest_git_repository' . mt_rand();
    }

    protected function getTestDir()
    {
        return $this->testdir;
    }

    /**
     * Create local git repository to run tests against!
     */
    protected function setupGitRepo()
    {
        $td = $this->getTestDir();

        $this->fs->removeDirectory($td);
        $this->fs->ensureDirectoryExists($td);

        $currentWorkDir = getcwd();
        chdir($td);

        $result = $this->process->execute("git init -q");
		if ($result > 0) {
            throw new \RuntimeException(
                "Could not init: " . $this->process->getErrorOutput());
        }
        $result = file_put_contents('b', 'a');
        if (false === $result) {
            throw new \RuntimeExcepton("Could not save file.");
        }
        $result = $this->process->execute("git add b && git commit -m 'commit b' -q");
        if ($result > 0) {
            throw new \RuntimeException(
                "Could not init: " . $this->process->getErrorOutput());
        }
		chdir($currentWorkDir);
    }

    protected function removeGitRepo()
    {
        $td = $this->getTestDir();
        $this->fs->removeDirectory($td);
    }

    protected function setupPackage()
    {
        $td = $this->getTestDir();
        $package = new MemoryPackage('dumpertest/dumpertest', 'master', 'master');
        $package->setSourceUrl("file://$td");
        $package->setSourceReference('master');
        $package->setSourceType('git');
        return $package;
    }

    protected function getPackageFileName(MemoryPackage $package)
    {
        return preg_replace('#[^a-z0-9_-]#', '-', $package->getUniqueName());
    }
}
