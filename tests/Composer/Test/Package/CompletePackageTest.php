<?php declare(strict_types=1);

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

use Composer\Package\Package;
use Composer\Semver\VersionParser;
use Composer\Test\TestCase;

class CompletePackageTest extends TestCase
{
    /**
     * Memory package naming, versioning, and marshalling semantics provider
     *
     * demonstrates several versioning schemes
     */
    public static function providerVersioningSchemes(): array
    {
        $provider[] = ['foo',              '1-beta'];
        $provider[] = ['node',             '0.5.6'];
        $provider[] = ['li3',              '0.10'];
        $provider[] = ['mongodb_odm',      '1.0.0BETA3'];
        $provider[] = ['DoctrineCommon',   '2.2.0-DEV'];

        return $provider;
    }

    /**
     * @dataProvider providerVersioningSchemes
     */
    public function testPackageHasExpectedNamingSemantics(string $name, string $version): void
    {
        $versionParser = new VersionParser();
        $normVersion = $versionParser->normalize($version);
        $package = new Package($name, $normVersion, $version);
        $this->assertEquals(strtolower($name), $package->getName());
    }

    /**
     * @dataProvider providerVersioningSchemes
     */
    public function testPackageHasExpectedVersioningSemantics(string $name, string $version): void
    {
        $versionParser = new VersionParser();
        $normVersion = $versionParser->normalize($version);
        $package = new Package($name, $normVersion, $version);
        $this->assertEquals($version, $package->getPrettyVersion());
        $this->assertEquals($normVersion, $package->getVersion());
    }

    /**
     * @dataProvider providerVersioningSchemes
     */
    public function testPackageHasExpectedMarshallingSemantics(string $name, string $version): void
    {
        $versionParser = new VersionParser();
        $normVersion = $versionParser->normalize($version);
        $package = new Package($name, $normVersion, $version);
        $this->assertEquals(strtolower($name).'-'.$normVersion, (string) $package);
    }

    public function testGetTargetDir(): void
    {
        $package = new Package('a', '1.0.0.0', '1.0');

        $this->assertNull($package->getTargetDir());

        $package->setTargetDir('./../foo/');
        $this->assertEquals('foo/', $package->getTargetDir());

        $package->setTargetDir('foo/../../../bar/');
        $this->assertEquals('foo/bar/', $package->getTargetDir());

        $package->setTargetDir('../..');
        $this->assertEquals('', $package->getTargetDir());

        $package->setTargetDir('..');
        $this->assertEquals('', $package->getTargetDir());

        $package->setTargetDir('/..');
        $this->assertEquals('', $package->getTargetDir());

        $package->setTargetDir('/foo/..');
        $this->assertEquals('foo/', $package->getTargetDir());

        $package->setTargetDir('/foo/..//bar');
        $this->assertEquals('foo/bar', $package->getTargetDir());
    }
}
