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
use Composer\Util\Archive\TarArchiver;

/**
 * TarArchiver test
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class TarArchiverTest extends TestCase
{
    /**
     * @var TarArchiver
     */
    private $archiver;

    protected function setUp()
    {
        $this->archiver = new TarArchiver();

        $this->testDir = sys_get_temp_dir().'/composer-zip-archiver-test';
        parent::setUp();
    }

    /**
     * Test that compressDir creates an archive with a right structure
     */
    public function testCompressDir()
    {
        $this->archiver->compressDir(dirname(__DIR__), $this->testDir . '/compress.tar');

        $tar = new \PharData($this->testDir . '/compress.tar');

        $this->assertTrue(isset($tar['Archive']));
        $this->assertTrue(isset($tar['Archive/TarArchiverTest.php']));
    }

    /**
     * Test that extractTo creates a directory with an archive content
     */
    public function testExtractTo()
    {
        $tar = new \PharData($this->testDir . '/extract.tar');
        $tar->addEmptyDir('Archive');
        $tar->addFile(__FILE__, 'Archive/' . basename(__FILE__));

        $this->archiver->extractTo($this->testDir . '/extract.tar', $this->testDir . '/extract');

        $this->assertTrue(is_file($this->testDir . '/extract/Archive/' . basename(__FILE__)));
    }
}
