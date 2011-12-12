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

use Composer\Package\Loader\ArrayLoader;

class ArrayLoaderTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        $this->loader = new ArrayLoader();
    }

    public function testSelfVersion()
    {
        $config = array(
            'name' => 'A',
            'version' => '1.2.3.4',
            'replace' => array(
                'foo' => 'self.version',
            ),
        );

        $package = $this->loader->load($config);
        $replaces = $package->getReplaces();
        $this->assertEquals('== 1.2.3.4', (string) $replaces[0]->getConstraint());
    }
}
