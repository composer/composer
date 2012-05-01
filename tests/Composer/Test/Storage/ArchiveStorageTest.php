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

use Composer\Storage\ArchiveStorage;
use Composer\Test\TestCase;

/**
 * ArchiveStorage test
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class ArchiveStorageTest extends TestCase
{
    /**
     * @var ArchiveStorage
     */
    private $storage;
    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|\Composer\Util\Archive\CompressorInterface
     */
    private $compressor;
    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|\Composer\Package\PackageInterface
     */
    private $package;

    protected function setUp()
    {
        $this->compressor = $this->getMock('Composer\Util\Archive\CompressorInterface');
        $this->compressor->expects($this->any())
            ->method('getArchiveType')
            ->will($this->returnValue('tmp'));
        $this->compressor->expects($this->any())
            ->method('compressDir')
            ->will($this->returnCallback(function($sourceDir, $fileName) {
                file_put_contents($fileName, 'package');
            }));

        $this->package = $this->getMock('Composer\Package\PackageInterface');
        $this->package->expects($this->any())
            ->method('getUniqueName')
            ->will($this->returnValue('acme/package-name-1.0.1'));

        $this->testDir = sys_get_temp_dir().'/composer-archive-storage-test';

        $this->storage = new ArchiveStorage($this->testDir . '/packages', $this->compressor);

        parent::setUp();
    }

    /**
     * Test storePackage creates file based on package name
     */
    public function testStorePackage()
    {
        $dist = $this->storage->storePackage($this->package, $this->testDir);

        $this->assertEquals('package', file_get_contents($this->testDir . '/packages/acme/package-name-1.0.1.tmp'));
        $this->assertEquals($this->testDir . '/packages/acme/package-name-1.0.1.tmp', $dist->getUrl());
        $this->assertEquals('tmp', $dist->getType());
        $this->assertEquals(sha1('package'), $dist->getSha1Checksum());
    }

    /**
     * Test retrievePackage returns distribution for an existing package file
     */
    public function testRetrieveExistingPackage()
    {
        mkdir($this->testDir . '/packages/acme', 0777, true);
        file_put_contents($this->testDir . '/packages/acme/package-name-1.0.1.tmp', 'package');

        $dist = $this->storage->retrievePackage($this->package);

        $this->assertEquals($this->testDir . '/packages/acme/package-name-1.0.1.tmp', $dist->getUrl());
        $this->assertEquals('tmp', $dist->getType());
        $this->assertEquals(sha1('package'), $dist->getSha1Checksum());
    }

    /**
     * Test retrievePackage returns null if package file is not exists
     */
    public function testRetrieveNotExistingPackage()
    {
        $this->assertNull($this->storage->retrievePackage($this->package));
    }
}
