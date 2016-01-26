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
use Composer\Util\Filesystem;

class RepositoryManagerTest extends TestCase
{
    protected $tmpdir;

    public function setUp()
    {
        $this->tmpdir = $this->getUniqueTmpDirectory();
    }

    public function tearDown()
    {
        if (is_dir($this->tmpdir)) {
            $fs = new Filesystem();
            $fs->removeDirectory($this->tmpdir);
        }
    }

    /**
     * @dataProvider creationCases
     */
    public function testRepoCreation($type, $options, $exception = null)
    {
        if ($exception) {
            $this->setExpectedException($exception);
        }

        $rm = new RepositoryManager(
            $this->getMock('Composer\IO\IOInterface'),
            $config = $this->getMock('Composer\Config', array('get')),
            $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock()
        );

        $tmpdir = $this->tmpdir;
        $config
            ->expects($this->any())
            ->method('get')
            ->will($this->returnCallback(function ($arg) use ($tmpdir) {
                return 'cache-repo-dir' === $arg ? $tmpdir : null;
            }))
        ;

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
        $rm->createRepository($type, $options);
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
            array('package', array('package' => array())),
            array('invalid', array(), 'InvalidArgumentException'),
        );
    }
}
