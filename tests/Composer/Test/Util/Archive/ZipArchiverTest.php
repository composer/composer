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

namespace Composer\Test\Util\Archive;

use Composer\Test\TestCase;
use Composer\Util\Archive\ZipArchiver;

/**
 * ZipArchiver test
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class ZipArchiverTest extends TestCase
{
    /**
     * @var ZipArchiver
     */
    private $archiver;

    protected function setUp()
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->archiver = new ZipArchiver();

        $this->testDir = sys_get_temp_dir().'/composer-zip-archiver-test';
        parent::setUp();
    }

    /**
     * Test that compressDir creates an archive with a right structure
     */
    public function testCompressDir()
    {
        $this->archiver->compressDir(dirname(__DIR__), $this->testDir . '/compress.zip');

        $zip = new \ZipArchive();
        $zip->open($this->testDir . '/compress.zip');

        $this->assertNotSame(false, $zip->locateName('Archive/'));
        $this->assertNotSame(false, $zip->locateName('Archive/ZipArchiverTest.php'));
    }

    /**
     * Test that extractTo creates a directory with an archive content
     */
    public function testExtractTo()
    {
        $zip = new \ZipArchive();
        $zip->open($this->testDir . '/extract.zip', \ZipArchive::CREATE);
        $zip->addEmptyDir('Archive');
        $zip->addFile(__FILE__, 'Archive/' . basename(__FILE__));
        $zip->close();

        $this->archiver->extractTo($this->testDir . '/extract.zip', $this->testDir . '/extract');

        $this->assertTrue(is_file($this->testDir . '/extract/Archive/' . basename(__FILE__)));
    }
}
