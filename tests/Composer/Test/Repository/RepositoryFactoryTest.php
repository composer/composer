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
use Composer\Test\TestCase;

class RepositoryFactoryTest extends TestCase
{
    public function testManagerWithAllRepositoryTypes()
    {
        $manager = RepositoryFactory::manager(
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $this->getMockBuilder('Composer\Config')->getMock()
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
            'git-bitbucket',
            'github',
            'gitlab',
            'svn',
            'fossil',
            'perforce',
            'hg',
            'hg-bitbucket',
            'artifact',
            'path',
        ), array_keys($repositoryClasses));
    }
}
