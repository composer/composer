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

namespace Composer\Test;

use Composer\TestCase;
use Composer\Util\Filesystem;

class CacheTest extends TestCase
{
    private $files;
    private $root;
    private $finder;
    private $cache;

    public function setUp()
    {
        if (getenv('TRAVIS')) {
            $this->markTestSkipped('Test causes intermittent failures on Travis');
        }

        $this->root = $this->getUniqueTmpDirectory();
        $this->files = array();
        $zeros = str_repeat('0', 1000);

        for ($i = 0; $i < 4; $i++) {
            file_put_contents("{$this->root}/cached.file{$i}.zip", $zeros);
            $this->files[] = new \SplFileInfo("{$this->root}/cached.file{$i}.zip");
        }

        $this->finder = $this->getMockBuilder('Symfony\Component\Finder\Finder')->disableOriginalConstructor()->getMock();

        $io = $this->getMock('Composer\IO\IOInterface');
        $this->cache = $this->getMock(
            'Composer\Cache',
            array('getFinder'),
            array($io, $this->root)
        );
        $this->cache
            ->expects($this->any())
            ->method('getFinder')
            ->will($this->returnValue($this->finder));
    }

    protected function tearDown()
    {
        if (is_dir($this->root)) {
            $fs = new Filesystem;
            $fs->removeDirectory($this->root);
        }
    }

    public function testRemoveOutdatedFiles()
    {
        $outdated = array_slice($this->files, 1);
        $this->finder
            ->expects($this->once())
            ->method('getIterator')
            ->will($this->returnValue(new \ArrayIterator($outdated)));
        $this->finder
            ->expects($this->once())
            ->method('date')
            ->will($this->returnValue($this->finder));

        $this->cache->gc(600, 1024 * 1024 * 1024);

        for ($i = 1; $i < 4; $i++) {
            $this->assertFileNotExists("{$this->root}/cached.file{$i}.zip");
        }
        $this->assertFileExists("{$this->root}/cached.file0.zip");
    }

    public function testRemoveFilesWhenCacheIsTooLarge()
    {
        $emptyFinder = $this->getMockBuilder('Symfony\Component\Finder\Finder')->disableOriginalConstructor()->getMock();
        $emptyFinder
            ->expects($this->once())
            ->method('getIterator')
            ->will($this->returnValue(new \EmptyIterator()));

        $this->finder
            ->expects($this->once())
            ->method('date')
            ->will($this->returnValue($emptyFinder));
        $this->finder
            ->expects($this->once())
            ->method('getIterator')
            ->will($this->returnValue(new \ArrayIterator($this->files)));
        $this->finder
            ->expects($this->once())
            ->method('sortByAccessedTime')
            ->will($this->returnValue($this->finder));

        $this->cache->gc(600, 1500);

        for ($i = 0; $i < 3; $i++) {
            $this->assertFileNotExists("{$this->root}/cached.file{$i}.zip");
        }
        $this->assertFileExists("{$this->root}/cached.file3.zip");
    }
}
