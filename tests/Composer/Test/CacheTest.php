<?php declare(strict_types=1);

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

use Composer\Cache;
use Composer\Util\Filesystem;

class CacheTest extends TestCase
{
    /** @var array<\SplFileInfo> */
    private $files;
    /** @var string */
    private $root;
    /** @var \Symfony\Component\Finder\Finder&\PHPUnit\Framework\MockObject\MockObject */
    private $finder;
    /** @var Filesystem&\PHPUnit\Framework\MockObject\MockObject */
    private $filesystem;
    /** @var Cache&\PHPUnit\Framework\MockObject\MockObject */
    private $cache;

    public function setUp(): void
    {
        $this->root = self::getUniqueTmpDirectory();
        $this->files = array();
        $zeros = str_repeat('0', 1000);

        for ($i = 0; $i < 4; $i++) {
            file_put_contents("{$this->root}/cached.file{$i}.zip", $zeros);
            $this->files[] = new \SplFileInfo("{$this->root}/cached.file{$i}.zip");
        }

        $this->finder = $this->getMockBuilder('Symfony\Component\Finder\Finder')->disableOriginalConstructor()->getMock();
        $this->filesystem = $this->getMockBuilder('Composer\Util\Filesystem')->getMock();

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $this->cache = $this->getMockBuilder('Composer\Cache')
            ->onlyMethods(array('getFinder'))
            ->setConstructorArgs(array($io, $this->root))
            ->getMock();
        $this->cache
            ->expects($this->any())
            ->method('getFinder')
            ->will($this->returnValue($this->finder));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->root)) {
            $fs = new Filesystem;
            $fs->removeDirectory($this->root);
        }
    }

    public function testRemoveOutdatedFiles(): void
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
            $this->assertFileDoesNotExist("{$this->root}/cached.file{$i}.zip");
        }
        $this->assertFileExists("{$this->root}/cached.file0.zip");
    }

    public function testRemoveFilesWhenCacheIsTooLarge(): void
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
            $this->assertFileDoesNotExist("{$this->root}/cached.file{$i}.zip");
        }
        $this->assertFileExists("{$this->root}/cached.file3.zip");
    }

    public function testClearCache(): void
    {
        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $cache = new Cache($io, $this->root, 'a-z0-9.', $this->filesystem);
        $this->assertTrue($cache->clear());
    }
}
