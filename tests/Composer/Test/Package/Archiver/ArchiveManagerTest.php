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

    protected $workDir;

    public function setUp()
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir();

        $this->manager = new ArchiveManager($this->workDir);
        $this->manager->addArchiver(new Archiver\GitArchiver);
        $this->manager->addArchiver(new Archiver\MercurialArchiver);
        $this->manager->addArchiver(new Archiver\PharArchiver);
    }

    public function testUnknownFormat()
    {
        $this->setExpectedException('RuntimeException');

        $package = $this->setupPackage();

        $this->manager->archive($package, '__unknown_format__');
    }

    public function testArchiveTarWithVcs()
    {
        $this->setupGitRepo();

        $package = $this->setupPackage();

        // The package is source from git,
        // so it should `git archive --format tar`
        $this->manager->archive($package, 'tar');

        $target = $this->getTargetName($package, 'tar');
        $this->assertFileExists($target);

        unlink($target);
        $this->removeGitRepo();
    }

    public function testArchiveTarWithoutVcs()
    {
        $this->setupGitRepo();

        $package = $this->setupPackage();

        // This should use the TarArchiver
        $this->manager->archive($package, 'tar');

        $package->setSourceType('__unknown_type__'); // disable VCS recognition
        $target = $this->getTargetName($package, 'tar');
        $this->assertFileExists($target);

        unlink($target);
        $this->removeGitRepo();
    }

    protected function getTargetName(PackageInterface $package, $format)
    {
        $packageName = str_replace('/', DIRECTORY_SEPARATOR, $package->getUniqueName());
        $target = $this->workDir.DIRECTORY_SEPARATOR.$packageName.'.'.$format;

        return $target;
    }
}
