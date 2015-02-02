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

namespace Composer\Repository;

use Composer\TestCase;
use Composer\IO\NullIO;
use Composer\Config;
use Composer\Package\BasePackage;

class ArtifactRepositoryTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('You need the zip extension to run this test.');
        }
    }

    public function testExtractsConfigsFromZipArchives()
    {
        $expectedPackages = array(
            'vendor0/package0-0.0.1',
            'composer/composer-1.0.0-alpha6',
            'vendor1/package2-4.3.2',
            'vendor3/package1-5.4.3',
            'test/jsonInRoot-1.0.0',
            'test/jsonInFirstLevel-1.0.0',
            //The files not-an-artifact.zip and jsonSecondLevel are not valid
            //artifacts and do not get detected.
        );

        $coordinates = array('type' => 'artifact', 'url' => __DIR__ . '/Fixtures/artifacts');
        $repo = new ArtifactRepository($coordinates, new NullIO(), new Config());

        $foundPackages = array_map(function (BasePackage $package) {
            return "{$package->getPrettyName()}-{$package->getPrettyVersion()}";
        }, $repo->getPackages());

        sort($expectedPackages);
        sort($foundPackages);

        $this->assertSame($expectedPackages, $foundPackages);
    }

    public function testAbsoluteRepoUrlCreatesAbsoluteUrlPackages()
    {
        $absolutePath = __DIR__ . '/Fixtures/artifacts';
        $coordinates = array('type' => 'artifact', 'url' => $absolutePath);
        $repo = new ArtifactRepository($coordinates, new NullIO(), new Config());

        foreach ($repo->getPackages() as $package) {
            $this->assertTrue(strpos($package->getDistUrl(), $absolutePath) === 0);
        }
    }

    public function testRelativeRepoUrlCreatesRelativeUrlPackages()
    {
        $relativePath = 'tests/Composer/Test/Repository/Fixtures/artifacts';
        $coordinates = array('type' => 'artifact', 'url' => $relativePath);
        $repo = new ArtifactRepository($coordinates, new NullIO(), new Config());

        foreach ($repo->getPackages() as $package) {
            $this->assertTrue(strpos($package->getDistUrl(), $relativePath) === 0);
        }
    }
}

//Files jsonInFirstLevel.zip, jsonInRoot.zip and jsonInSecondLevel.zip were generated with:
//
//$archivesToCreate = array(
//    'jsonInRoot' => array(
//        "extra.txt"     => "Testing testing testing",
//        "composer.json" => '{  "name": "test/jsonInRoot", "version": "1.0.0" }',
//        "subdir/extra.txt"     => "Testing testing testing",
//        "subdir/extra2.txt"     => "Testing testing testing",
//    ),
//
//    'jsonInFirstLevel' => array(
//        "extra.txt"     => "Testing testing testing",
//        "subdir/composer.json" => '{  "name": "test/jsonInFirstLevel", "version": "1.0.0" }',
//        "subdir/extra.txt"     => "Testing testing testing",
//        "subdir/extra2.txt"     => "Testing testing testing",
//    ),
//
//    'jsonInSecondLevel' => array(
//        "extra.txt"     => "Testing testing testing",
//        "subdir/extra1.txt"     => "Testing testing testing",
//        "subdir/foo/composer.json" => '{  "name": "test/jsonInSecondLevel", "version": "1.0.0" }',
//        "subdir/foo/extra1.txt"     => "Testing testing testing",
//        "subdir/extra2.txt"     => "Testing testing testing",
//        "subdir/extra3.txt"     => "Testing testing testing",
//    ),
//);
//
//foreach ($archivesToCreate as $archiveName => $fileDetails) {
//    $zipFile = new ZipArchive();
//    $zipFile->open("$archiveName.zip", ZIPARCHIVE::CREATE);
//
//    foreach ($fileDetails as $filename => $fileContents) {
//        $zipFile->addFromString($filename, $fileContents);
//    }
//
//    $zipFile->close();
//}
