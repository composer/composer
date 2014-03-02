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

class RepositoryManagerTest extends TestCase
{
    /**
     * @dataProvider creationCases
     */
    public function testRepoCreation($type, $config)
    {
        $rm = new RepositoryManager(
            $this->getMock('Composer\IO\IOInterface'),
            $this->getMock('Composer\Config'),
            $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock()
        );
        $rm->setRepositoryClass('composer', 'Composer\Repository\ComposerRepository');
        $rm->setRepositoryClass('vcs', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('package', 'Composer\Repository\PackageRepository');
        $rm->setRepositoryClass('pear', 'Composer\Repository\PearRepository');
        $rm->setRepositoryClass('git', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('svn', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('perforce', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('hg', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('artifact', 'Composer\Repository\ArtifactRepository');

        $rm->createRepository('composer', array('url' => 'http://example.org'));
        $rm->createRepository('composer', array('url' => 'http://example.org'));
        $rm->createRepository('composer', array('url' => 'http://example.org'));
    }

    public function creationCases()
    {
        return array(
            array('composer', array('url' => 'http://example.org')),
            array('vcs', array('url' => 'http://github.com/foo/bar')),
            array('git', array('url' => 'http://github.com/foo/bar')),
            array('git', array('url' => 'git@example.org:foo/bar.git')),
            array('svn', array('url' => 'svn://example.org/foo/bar')),
            array('pear', array('url' => 'http://pear.example.org/foo')),
            array('artifact', array('url' => '/path/to/zips')),
            array('package', array()),
        );
    }
}
