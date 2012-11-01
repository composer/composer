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

namespace Composer\Test\Storage;

use Composer\Package\PackageInterface;
use Composer\Storage\RepositoryStorage;
use Composer\Storage\PackageDistribution;
use Composer\Test\TestCase;

/**
 * RepositoryStorage test
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class RepositoryStorageTest extends TestCase
{
    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|\Composer\Repository\WritableRepositoryInterface
     */
    private $repository;
    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|\Composer\Storage\StorageInterface
     */
    private $internalStorage;
    /**
     * @var RepositoryStorage
     */
    private $storage;
    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|PackageInterface
     */
    private $package;

    protected function setUp()
    {
        $this->repository = $this->getMock('Composer\Repository\WritableRepositoryInterface');

        $this->internalStorage = $this->getMock('Composer\Storage\StorageInterface');

        $this->storage = new RepositoryStorage($this->repository, $this->internalStorage);

        $this->package = $this->getMock('Composer\Package\PackageInterface');
        $this->package->expects($this->any())
            ->method('getPrettyName')
            ->will($this->returnValue('package'));
        $this->package->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('1.0.0'));

        parent::setUp();
    }

    /**
     * Test storePackage proxies to internal storage and uses repository to save metadata
     */
    public function testStorePackageWritesToRepository()
    {
        $dist = new PackageDistribution('ext', '/tmp/package.ext', sha1('test'));
        $this->internalStorage->expects($this->once())
            ->method('storePackage')
            ->with($this->package, '/tmp')
            ->will($this->returnValue($dist));

        /* @var PackageInterface $storedPackage */
        $storedPackage = null;
        $this->repository->expects($this->once())
            ->method('addPackage')
            ->will($this->returnCallback(function(PackageInterface $package) use (&$storedPackage) {
                $storedPackage = $package;
            }));
        $this->repository->expects($this->once())
            ->method('write');

        $this->assertSame($dist, $this->storage->storePackage($this->package, '/tmp'));

        $this->assertEquals($dist->getUrl(), $storedPackage->getDistUrl());
        $this->assertEquals($dist->getType(), $storedPackage->getDistType());
        $this->assertEquals($dist->getSha1Checksum(), $storedPackage->getDistSha1Checksum());
    }

    /**
     * Test retrievePackage just proxies to internal storage
     */
    public function testRetrievePackageProxiesToInternalStorage()
    {
        $dist = new PackageDistribution('ext', '/tmp/package.ext', sha1('test'));

        $this->internalStorage
            ->expects($this->once())
            ->method('retrievePackage')
            ->with($this->package)
            ->will($this->returnValue($dist));

        $this->internalStorage
            ->expects($this->any())
            ->method('hasPackage')
            ->with($this->package)
            ->will($this->returnValue(true));

        $this->repository
            ->expects($this->any())
            ->method('hasPackage')
            ->with($this->package)
            ->will($this->returnValue(true));

        $this->assertSame($dist, $this->storage->retrievePackage($this->package));
    }

    /**
     * Test hasPackage return true only if package exists in repository and storage
     * @dataProvider hasPackageData
     */
    public function testHasPackageCheckRepositoryAndStorage($hasRepository, $hasStorage)
    {

        $this->internalStorage
            ->expects($this->any())
            ->method('hasPackage')
            ->with($this->package)
            ->will($this->returnValue($hasRepository));

        $this->repository
            ->expects($this->any())
            ->method('hasPackage')
            ->with($this->package)
            ->will($this->returnValue($hasStorage));

        $this->assertEquals($hasRepository && $hasStorage, $this->storage->hasPackage($this->package));
    }

    public function hasPackageData()
    {
        return array(
            array(false, false),
            array(false, true),
            array(true, false),
            array(true, true)
        );
    }
}
