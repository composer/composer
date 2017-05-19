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

use Composer\Repository\RepositoryFactory;
use Composer\TestCase;

class RepositoryFactoryTest extends TestCase
{
    public function testManagerWithAllRepositoryTypes()
    {
        $manager = RepositoryFactory::manager(
            $this->getMock('Composer\IO\IOInterface'),
            $this->getMock('Composer\Config')
        );

        $ref = new \ReflectionProperty($manager, 'repositoryClasses');
        $ref->setAccessible(true);
        $repositoryClasses = $ref->getValue($manager);

        $this->assertEquals(array(
            'composer',
            'vcs',
            'package',
            'pear',
            'git',
            'github',
            'gitlab',
            'svn',
            'fossil',
            'perforce',
            'hg',
            'artifact',
            'path',
        ), array_keys($repositoryClasses));
    }
}
