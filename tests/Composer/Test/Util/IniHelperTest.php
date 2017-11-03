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

namespace Composer\Test\Util;

use Composer\Util\IniHelper;

/**
 * @author John Stevenson <john-stevenson@blueyonder.co.uk>
 */
class IniHelperTest extends \PHPUnit_Framework_TestCase
{
    public static $envOriginal;

    public function testWithNoIni()
    {
        $paths = array(
            '',
        );

        $this->setEnv($paths);
        $this->assertContains('does not exist', IniHelper::getMessage());
        $this->assertEquals($paths, IniHelper::getAll());
    }

    public function testWithLoadedIniOnly()
    {
        $paths = array(
            'loaded.ini',
        );

        $this->setEnv($paths);
        $this->assertContains('loaded.ini', IniHelper::getMessage());
        $this->assertEquals($paths, IniHelper::getAll());
    }

    public function testWithLoadedIniAndAdditional()
    {
        $paths = array(
            'loaded.ini',
            'one.ini',
            'two.ini',
        );

        $this->setEnv($paths);
        $this->assertContains('multiple ini files', IniHelper::getMessage());
        $this->assertEquals($paths, IniHelper::getAll());
    }

    public function testWithoutLoadedIniAndAdditional()
    {
        $paths = array(
            '',
            'one.ini',
            'two.ini',
        );

        $this->setEnv($paths);
        $this->assertContains('multiple ini files', IniHelper::getMessage());
        $this->assertEquals($paths, IniHelper::getAll());
    }

    public static function setUpBeforeClass()
    {
        // Save current state
        self::$envOriginal = getenv(IniHelper::ENV_ORIGINAL);
    }

    public static function tearDownAfterClass()
    {
        // Restore original state
        if (false !== self::$envOriginal) {
            putenv(IniHelper::ENV_ORIGINAL.'='.self::$envOriginal);
        } else {
            putenv(IniHelper::ENV_ORIGINAL);
        }
    }

    protected function setEnv(array $paths)
    {
        putenv(IniHelper::ENV_ORIGINAL.'='.implode(PATH_SEPARATOR, $paths));
    }
}
