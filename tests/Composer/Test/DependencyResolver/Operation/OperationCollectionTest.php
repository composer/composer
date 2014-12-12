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

namespace Composer\Test\DependencyResolver\Operation;

use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\MarkAliasInstalledOperation;
use Composer\DependencyResolver\Operation\MarkAliasUninstalledOperation;
use Composer\DependencyResolver\Operation\OperationCollection;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Package\AliasPackage;
use Composer\TestCase;

class OperationCollectionTest extends TestCase
{
    protected function addPackagesToCollection(OperationCollection $collection)
    {
        $package = $this->getMock('Composer\Package\PackageInterface');
        $expects = array (
            'getName' => 'foo',
            'getRequires' => array(),
            'getDevRequires' => array(),
            'getConflicts' => array(),
            'getProvides' => array(),
            'getReplaces' => array(),
        );
        foreach ($expects as $method => $returns) {
            $package->expects($this->any())
                ->method($method)
                ->willReturn($returns);
        }
        $alias = new AliasPackage($package,'1.0','1.0');
        $plugin = $this->getMock('Composer\Package\PackageInterface');
        $plugin->expects($this->any())
            ->method('getType')
            ->willReturn('composer-plugin');
        $plugin->expects($this->any())
            ->method('getRequires')
            ->willReturn(array());

        $collection->add(new UpdateOperation($package, $package));
        $collection->add(new InstallOperation($package));
        $collection->add(new UninstallOperation($package));
        $collection->add(new MarkAliasInstalledOperation($alias));
        $collection->add(new MarkAliasUninstalledOperation($alias));
        $collection->add(new InstallOperation($plugin));
    }

    public function testAddOperations()
    {
        $collection = new OperationCollection();
        $this->assertTrue($collection->isEmpty());

        $this->addPackagesToCollection($collection);

        $this->assertFalse($collection->isEmpty());
        $this->assertCount(6, $collection->toArray());
        $this->assertCount(1, $collection->getInstalls());
        $this->assertCount(1, $collection->getUpdates());
        $this->assertCount(1, $collection->getUninstalls());
        $this->assertCount(1, $collection->getMarkAliasInstalled());
        $this->assertCount(1, $collection->getMarkAliasUninstalled());
        $this->assertCount(1, $collection->getPlugins());
    }

    public function testOperationSorting()
    {
        $collection = new OperationCollection();
        $this->addPackagesToCollection($collection);
        $operations = $collection->toArray();

        $this->assertInstanceOf('Composer\DependencyResolver\Operation\UninstallOperation', reset($operations));
        $this->assertTrue(next($operations)->getPackage()->getType() == 'composer-plugin');
        $this->assertInstanceOf('Composer\DependencyResolver\Operation\MarkAliasUninstalledOperation', next($operations));
        $this->assertInstanceOf('Composer\DependencyResolver\Operation\InstallOperation', next($operations));
        $this->assertInstanceOf('Composer\DependencyResolver\Operation\MarkAliasInstalledOperation', next($operations));
        $this->assertInstanceOf('Composer\DependencyResolver\Operation\UpdateOperation', next($operations));
    }

    public function testIsIterable()
    {
        $collection = new OperationCollection();
        $this->assertInstanceOf('\IteratorAggregate', $collection);
    }
}
