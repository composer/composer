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

abstract class DumperTest extends \PHPUnit_Framework_TestCase
{
    protected $testdir = '';

    public function setUp()
    {
        $this->testdir = sys_get_temp_dir() . '/composer_dumpertest_git_repository' . mt_rand();
    }

    protected function getTestDir()
    {
        return $this->testdir;
    }

    protected function setupGitRepo()
    {
        $td = $this->getTestDir();
        system("rm -rf $td; mkdir $td");
        system("cd $td; git init; echo 'a' > b; git add b; git commit -m test");
    }

    protected function removeGitRepo()
    {
        $td = $this->getTestDir();
        system("rm -rf $td");
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
