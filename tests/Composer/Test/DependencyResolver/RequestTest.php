<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien.potencier@symfony-project.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\DependencyResolver;

use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\ArrayRepository;
use Composer\DependencyResolver\Literal;
use Composer\DependencyResolver\MemoryPackage;

class RequestTest extends \PHPUnit_Framework_TestCase
{
    public function testRequestInstallAndRemove()
    {
        $pool = new Pool;
        $repo = new ArrayRepository;
        $foo = new MemoryPackage('foo', '1');
        $bar = new MemoryPackage('bar', '1');
        $foobar = new MemoryPackage('foobar', '1');

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
                array('packages' => array($foo), 'cmd' => 'install', 'packageName' => 'foo'),
                array('packages' => array($bar), 'cmd' => 'install', 'packageName' => 'bar'),
                array('packages' => array($foobar), 'cmd' => 'remove', 'packageName' => 'foobar'),
            ),
            $request->getJobs());
    }
}
