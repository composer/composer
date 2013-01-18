<?php

/*
 * This file is part of Composer.
 *
 * (c) René Patzer <rene.patzer@gamepay.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Downloader;

use \Composer\Downloader\LocalSymlinkCreater;

/**
 * Unittest for the LocalSymlinkCreater-Class 
 */
class LocalSymlinkCreaterTest extends \PHPUnit_Framework_TestCase
{    
    public function testSymlinking() {
        // create a temporary directory
        $testdir= sys_get_temp_dir().DIRECTORY_SEPARATOR;
        // create a source and a destination-directory
        $sourcedir= $testdir.'source'.rand(10000, 100000);
        $destinationdir= $testdir.'dest'.rand(10000, 100000);
        if (!@mkdir($sourcedir, 0700, true)) {
            $this->fail('Could not create temporary source-directory!');
        }
        if (!@mkdir($destinationdir, 0700, true)) {
            $this->fail('Could not create temporary destination-directory!');
        }
        $file1= $sourcedir.DIRECTORY_SEPARATOR.'unittest'.rand(100, 5000);
        $file1Content= uniqid(__METHOD__);
        $file2= $sourcedir.DIRECTORY_SEPARATOR.'unittest'.rand(5001, 10000);
        $file2Content= uniqid(__METHOD__);
        // create some files in source
        file_put_contents($file1, $file1Content);
        file_put_contents($file2, $file2Content);
        
        
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getName')
            ->will($this->returnValue('unittest-package'))
        ;
        
        $repositoriesMock= $this->getMock('Composer\Repository\RepositoryInterface');
        $symlinkPackageMock= $this->getMock('Composer\Package\PackageInterface');
        $symlinkPackageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue($sourcedir))
        ;

        $packageMock->expects($this->any())
            ->method('getRepository')
            ->will($this->returnValue($repositoriesMock))
        ;
        $repositoriesMock->expects($this->any())
            ->method('getPackages')
            ->will($this->returnValue(array($symlinkPackageMock)))
        ;
        $packageMock->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue($destinationdir))
        ;
        
        
        $io = $this->getMock('Composer\IO\IOInterface');
        $config = $this->getMock('Composer\Config');
        
        $downloader= new \Composer\Downloader\LocalSymlinkCreater($io, $config);
        // let it symlink to dest
        try {
            $downloader->download($packageMock, $destinationdir);
        } catch (\Exception $e) {
            $this->fail($e->getMessage());
        }
        
        // compare filelist
        $sourceFileList= array();
        $destFileList= array();
        
        $d = dir($sourcedir);
        while (false !== ($entry = $d->read())) {
            $sourceFileList[]= $entry;
        }
        $d->close();
        sort($sourceFileList);
        
        $d = dir($destinationdir);
        while (false !== ($entry = $d->read())) {
            $destFileList[]= $entry;
        }
        $d->close();
        sort($destFileList);
        
        $this->assertEquals($sourceFileList, $destFileList, 'Directory Contents mismatch!');
    }
}
