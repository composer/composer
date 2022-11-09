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

namespace Composer\Test\Downloader;

use Composer\Downloader\XzDownloader;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\Util\Loop;
use Composer\Util\HttpDownloader;

class XzDownloaderTest extends TestCase
{
    /**
     * @var Filesystem
     */
    private $fs;

    /**
     * @var string
     */
    private $testDir;

    public function setUp(): void
    {
        if (Platform::isWindows()) {
            $this->markTestSkipped('Skip test on Windows');
        }
        $this->testDir = self::getUniqueTmpDirectory();
    }

    protected function tearDown(): void
    {
        if (Platform::isWindows()) {
            return;
        }
        parent::tearDown();
        $this->fs = new Filesystem;
        $this->fs->removeDirectory($this->testDir);
    }

    public function testErrorMessages(): void
    {
        $package = $this->getPackage();
        $package->setDistUrl($distUrl = 'file://'.__FILE__);

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $config = $this->getConfig(['vendor-dir' => $this->testDir]);
        $downloader = new XzDownloader($io, $config, $httpDownloader = new HttpDownloader($io, $config), null, null, null);

        try {
            $loop = new Loop($httpDownloader);
            $promise = $downloader->download($package, $this->testDir.'/install-path');
            $loop->wait([$promise]);
            $downloader->install($package, $this->testDir.'/install-path');

            $this->fail('Download of invalid tarball should throw an exception');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/(File format not recognized|Unrecognized archive format)/i', $e->getMessage());
        }
    }
}
