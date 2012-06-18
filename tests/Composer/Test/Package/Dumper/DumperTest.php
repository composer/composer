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
    public function getPackageName()
    {
        $testdir = '/tmp/composer_dumpertest_git_repository';

        system("rm -rf $testdir; mkdir $testdir");
        system("cd $testdir; git init; echo 'a' > b; git add b; git commit -m test");

        $package = new MemoryPackage('dumpertest/dumpertest', 'master', 'master');
        $package->setSourceUrl("file://$testdir");
        $package->setSourceReference('master');
        $package->setSourceType('git');

        $name = preg_replace('#[^a-z0-9_-]#', '-', $package->getUniqueName());

        $retu = array('package' => $package, 'name' => $name);
        return $retu;
    }
}
