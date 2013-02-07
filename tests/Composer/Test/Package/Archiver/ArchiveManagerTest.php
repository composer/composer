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

namespace Composer\Test\Package\Archiver;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\Archiver;
use Composer\Package\Archiver\ArchiveManager;
use Composer\Package\PackageInterface;

/**
 * @author Till Klampaeckel <till@php.net>
 * @author Matthieu Moquet <matthieu@moquet.net>
 */
class ArchiveManagerTest extends ArchiverTest
{
    protected $manager;
    protected $targetDir;

    public function setUp()
    {
        parent::setUp();

        $factory = new Factory();
        $this->manager = $factory->createArchiveManager($factory->createConfig());
        $this->targetDir = $this->testDir.'/composer_archiver_tests';
    }

    public function testUnknownFormat()
    {
        $this->setExpectedException('RuntimeException');

        $package = $this->setupPackage();

        $this->manager->archive($package, '__unknown_format__', $this->targetDir);
    }

    public function testArchiveTar()
    {
        $this->setupGitRepo();

        $package = $this->setupPackage();

        // The package is source from git,
        // so it should `git archive --format tar`
        $this->manager->archive($package, 'tar', $this->targetDir);

        $target = $this->getTargetName($package, 'tar');
        $this->assertFileExists($target);

        unlink($target);
    }

    protected function getTargetName(PackageInterface $package, $format)
    {
        $packageName = $package->getUniqueName();
        $target = $this->targetDir.'/'.$packageName.'.'.$format;

        return $target;
    }

    /**
     * Create local git repository to run tests against!
     */
    protected function setupGitRepo()
    {
        $currentWorkDir = getcwd();
        chdir($this->testDir);

        $result = $this->process->execute('git init -q');
        if ($result > 0) {
            chdir($currentWorkDir);
            throw new \RuntimeException('Could not init: '.$this->process->getErrorOutput());
        }

        $result = file_put_contents('b', 'a');
        if (false === $result) {
            chdir($currentWorkDir);
            throw new \RuntimeException('Could not save file.');
        }

        $result = $this->process->execute('git add b && git commit -m "commit b" -q');
        if ($result > 0) {
            chdir($currentWorkDir);
            throw new \RuntimeException('Could not commit: '.$this->process->getErrorOutput());
        }

        chdir($currentWorkDir);
    }
}
