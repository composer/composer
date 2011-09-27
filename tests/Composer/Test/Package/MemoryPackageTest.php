<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *     Wil Moore III <wil.moore@wilmoore.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Package;

use Composer\Package\MemoryPackage;

class MemoryPackageTest extends \PHPUnit_Framework_TestCase
{

    /**
     * Memory package naming, versioning, and marshalling semantics provider
     *
     * demonstrates several versioning schemes
     *
     * @todo    if all package types share the same semantics, we could use a data provider
     *          to test them all in a single suite
     */
    public function provider_memory_package_semantics()
    {
        $provider[] = array('foo',              '1-beta',       'foo-1-beta');
        $provider[] = array('node',             '0.5.6',        'node-0.5.6');
        $provider[] = array('li3',              '0.10',         'li3-0.10');
        $provider[] = array('mongodb_odm',      '1.0.0BETA3',   'mongodb_odm-1.0.0BETA3');
        $provider[] = array('DoctrineCommon',   '2.2.0-DEV',    'doctrinecommon-2.2.0-DEV');

        return $provider;
    }

    /**
     * Tests memory package naming semantics
     *
     * @test
     * @dataProvider    provider_memory_package_semantics
     */
    public function Memory_Package_Has_Expected_Naming_Semantics($name, $version, $marshalled)
    {
        $package = new MemoryPackage($name, $version);
        $this->assertEquals(strtolower($name), $package->getName());
    }

    /**
     * Tests memory package versioning semantics
     *
     * @test
     * @dataProvider    provider_memory_package_semantics
     */
    public function Memory_Package_Has_Expected_Versioning_Semantics($name, $version, $marshalled)
    {
        $package = new MemoryPackage($name, $version);
        $this->assertEquals($version, $package->getVersion());
    }

    /**
     * Tests memory package marshalling/serialization semantics
     *
     * @test
     * @dataProvider    provider_memory_package_semantics
     */
    public function Memory_Package_Has_Expected_Marshalling_Semantics($name, $version, $marshalled)
    {
        $package = new MemoryPackage($name, $version);
        $this->assertEquals($marshalled, (string) $package);
    }

}
