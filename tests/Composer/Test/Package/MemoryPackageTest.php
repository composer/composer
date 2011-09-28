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

namespace Composer\Test\Package;

use Composer\Package\MemoryPackage;

class MemoryPackageTest extends \PHPUnit_Framework_TestCase
{

    /**
     * Memory package naming, versioning, and marshalling semantics provider
     *
     * demonstrates several versioning schemes
     */
    public function ProviderVersioningSchemes()
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
     * @dataProvider    ProviderVersioningSchemes
     *
     * @param   string  $name
     * @param   string  $version
     */
    public function testMemoryPackageHasExpectedNamingSemantics($name, $version)
    {
        $package = new MemoryPackage($name, $version);
        $this->assertEquals(strtolower($name), $package->getName());
    }

    /**
     * Tests memory package versioning semantics
     *
     * @dataProvider    ProviderVersioningSchemes
     *
     * @param   string  $name
     * @param   string  $version
     */
    public function testMemoryPackageHasExpectedVersioningSemantics($name, $version)
    {
        $package = new MemoryPackage($name, $version);
        $this->assertEquals($version, $package->getVersion());
    }

    /**
     * Tests memory package marshalling/serialization semantics
     *
     * @dataProvider    ProviderVersioningSchemes
     *
     * @param   string  $name
     * @param   string  $version
     * @param   string  $marshalled
     */
    public function testMemoryPackageHasExpectedMarshallingSemantics($name, $version, $marshalled)
    {
        $package = new MemoryPackage($name, $version);
        $this->assertEquals($marshalled, (string) $package);
    }

}
