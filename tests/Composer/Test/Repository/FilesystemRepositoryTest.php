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

use Composer\Repository\FilesystemRepository;

class FilesystemRepositoryTest extends \PHPUnit_Framework_TestCase
{
    private $dir;
    private $repositoryFile;

    protected function setUp()
    {
        $this->dir = sys_get_temp_dir().'/.composer';
        $this->repositoryFile = $this->dir.'/some_registry-reg.json';

        if (file_exists($this->repositoryFile)) {
            unlink($this->repositoryFile);
        }
    }

    public function testRepositoryReadWrite()
    {
        $this->assertFileNotExists($this->repositoryFile);
        $repository = new FilesystemRepository($this->repositoryFile);

        $repository->getPackages();
        $repository->write();
        $this->assertFileExists($this->repositoryFile);

        file_put_contents($this->repositoryFile, json_encode(array(
            array('name' => 'package1', 'version' => '1.0.0-beta', 'type' => 'vendor')
        )));

        $repository = new FilesystemRepository($this->repositoryFile);
        $repository->getPackages();
        $repository->write();
        $this->assertFileExists($this->repositoryFile);

        $data = json_decode(file_get_contents($this->repositoryFile), true);
        $this->assertEquals(array(
            array('name' => 'package1', 'type' => 'vendor', 'version' => '1.0.0', 'releaseType' => 'beta', 'names' => array('package1'))
        ), $data);
    }
}
