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

namespace Composer\Test\DependencyResolver;

use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Pool;
use Composer\Repository\ArrayRepository;
use Composer\TestCase;

class RequestTest extends TestCase
{
    public function testRequestInstallAndRemove()
    {
        $pool = new Pool;
        $repo = new ArrayRepository;
        $foo = $this->getPackage('foo', '1');
        $bar = $this->getPackage('bar', '1');
        $foobar = $this->getPackage('foobar', '1');

        $repo->addPackage($foo);
        $repo->addPackage($bar);
        $repo->addPackage($foobar);
        $pool->addRepository($repo);

        $request = new Request($pool);
        $request->install('foo');
        $request->install('bar');
        $request->remove('foobar');

        $this->assertEquals(
            array(
                array('packages' => array($foo), 'cmd' => 'install', 'packageName' => 'foo', 'constraint' => null),
                array('packages' => array($bar), 'cmd' => 'install', 'packageName' => 'bar', 'constraint' => null),
                array('packages' => array($foobar), 'cmd' => 'remove', 'packageName' => 'foobar', 'constraint' => null),
            ),
            $request->getJobs());
    }

    public function testRequestInstallSamePackageFromDifferentRepositories()
    {
        $pool = new Pool;
        $repo1 = new ArrayRepository;
        $repo2 = new ArrayRepository;

        $foo1 = $this->getPackage('foo', '1');
        $foo2 = $this->getPackage('foo', '1');

        $repo1->addPackage($foo1);
        $repo2->addPackage($foo2);

        $pool->addRepository($repo1);
        $pool->addRepository($repo2);

        $request = new Request($pool);
        $request->install('foo', $constraint = $this->getVersionConstraint('=', '1'));

        $this->assertEquals(
            array(
                    array('packages' => array($foo1, $foo2), 'cmd' => 'install', 'packageName' => 'foo', 'constraint' => $constraint),
            ),
            $request->getJobs()
        );
    }

    public function testUpdateAll()
    {
        $pool = new Pool;
        $request = new Request($pool);

        $request->updateAll();

        $this->assertEquals(
            array(array('cmd' => 'update-all', 'packages' => array())),
            $request->getJobs());
    }
}
