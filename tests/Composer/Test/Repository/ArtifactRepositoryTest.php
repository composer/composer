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
    public function testExtractsConfigsFromZipArchives()
    {
        $expectedPackages = array(
            'vendor0/package0-0.0.1',
            'composer/composer-1.0.0-alpha6',
            'vendor1/package2-4.3.2',
            'vendor3/package1-5.4.3',
        );

        $coordinates = array('type' => 'artifact', 'url' => __DIR__ . '/Fixtures/artifacts');
        $repo = new ArtifactRepository($coordinates, new NullIO(), new Config());

        $foundPackages = array_map(function(BasePackage $package) {
            return "{$package->getPrettyName()}-{$package->getPrettyVersion()}";
        }, $repo->getPackages());

        sort($expectedPackages);
        sort($foundPackages);

        $this->assertSame($expectedPackages, $foundPackages);
    }
}
