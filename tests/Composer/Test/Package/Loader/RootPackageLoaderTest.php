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

namespace Composer\Test\Package\Loader;

use Composer\Package\Loader\RootPackageLoader;
use Composer\Repository\RepositoryManager;

class RootPackageLoaderTest extends \PHPUnit_Framework_TestCase
{
    public function testAllowsDisabledDefaultRepository()
    {
        $loader = new RootPackageLoader(
            new RepositoryManager(
                $this->getMock('Composer\\IO\\IOInterface'),
                $this->getMock('Composer\\Config')
            )
        );

        $repos = array(array('packagist' => false));
        $package = $loader->load(array('repositories' => $repos));

        $this->assertEquals($repos, $package->getRepositories());
    }
}
