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

namespace Composer\Test\Repository;

use Composer\Repository\ComposerRepository;
use Composer\IO\NullIO;
use Composer\Test\Mock\FactoryMock;
use Composer\Test\TestCase;

class ComposerRepositoryTest extends TestCase
{
    /**
     * @dataProvider loadDataProvider
     */
    public function testLoadData(array $expected, array $repoPackages)
    {
        $repoConfig = array(
            'url' => 'http://example.org',
        );

        $repository = $this->getMock(
            'Composer\Repository\ComposerRepository',
            array(
                'loadRootServerFile',
                'createPackage',
            ),
            array(
                $repoConfig,
                new NullIO,
                FactoryMock::createConfig(),
            )
        );

        $repository
            ->expects($this->exactly(2))
            ->method('loadRootServerFile')
            ->will($this->returnValue($repoPackages));

        foreach ($expected as $at => $arg) {
            $stubPackage = $this->getPackage('stub/stub', '1.0.0');

            $repository
                ->expects($this->at($at + 2))
                ->method('createPackage')
                ->with($this->identicalTo($arg), $this->equalTo('Composer\Package\CompletePackage'))
                ->will($this->returnValue($stubPackage));
        }

        // Triggers initialization
        $packages = $repository->getPackages();

        // Final sanity check, ensure the correct number of packages were added.
        $this->assertCount(count($expected), $packages);
    }

    public function loadDataProvider()
    {
        return array(
            // Old repository format
            array(
                array(
                    array('name' => 'foo/bar', 'version' => '1.0.0'),
                ),
                array('foo/bar' => array(
                    'name' => 'foo/bar',
                    'versions' => array(
                        '1.0.0' => array('name' => 'foo/bar', 'version' => '1.0.0')
                    )
                )),
            ),
            // New repository format
            array(
                array(
                    array('name' => 'bar/foo', 'version' => '3.14'),
                    array('name' => 'bar/foo', 'version' => '3.145'),
                ),
                array('packages' => array(
                    'bar/foo' => array(
                        '3.14'  => array('name' => 'bar/foo', 'version' => '3.14'),
                        '3.145' => array('name' => 'bar/foo', 'version' => '3.145'),
                    ),
                )),
            ),
        );
    }
}
