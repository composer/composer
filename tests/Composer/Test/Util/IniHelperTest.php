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

namespace Composer\Test\Util;

use Composer\Util\IniHelper;
use Composer\XdebugHandler\XdebugHandler;
use Composer\Test\TestCase;

/**
 * @author John Stevenson <john-stevenson@blueyonder.co.uk>
 */
class IniHelperTest extends TestCase
{
    /**
     * @var string|false
     */
    public static $envOriginal;

    public function testWithNoIni(): void
    {
        $paths = [
            '',
        ];

        $this->setEnv($paths);
        self::assertStringContainsString('does not exist', IniHelper::getMessage());
        self::assertEquals($paths, IniHelper::getAll());
    }

    public function testWithLoadedIniOnly(): void
    {
        $paths = [
            'loaded.ini',
        ];

        $this->setEnv($paths);
        self::assertStringContainsString('loaded.ini', IniHelper::getMessage());
    }

    public function testWithLoadedIniAndAdditional(): void
    {
        $paths = [
            'loaded.ini',
            'one.ini',
            'two.ini',
        ];

        $this->setEnv($paths);
        self::assertStringContainsString('multiple ini files', IniHelper::getMessage());
        self::assertEquals($paths, IniHelper::getAll());
    }

    public function testWithoutLoadedIniAndAdditional(): void
    {
        $paths = [
            '',
            'one.ini',
            'two.ini',
        ];

        $this->setEnv($paths);
        self::assertStringContainsString('multiple ini files', IniHelper::getMessage());
        self::assertEquals($paths, IniHelper::getAll());
    }

    public static function setUpBeforeClass(): void
    {
        // Register our name with XdebugHandler
        $xdebug = new XdebugHandler('composer');
        // Save current state
        self::$envOriginal = getenv('COMPOSER_ORIGINAL_INIS');
    }

    public static function tearDownAfterClass(): void
    {
        // Restore original state
        if (false !== self::$envOriginal) {
            putenv('COMPOSER_ORIGINAL_INIS='.self::$envOriginal);
        } else {
            putenv('COMPOSER_ORIGINAL_INIS');
        }
    }

    /**
     * @param string[] $paths
     */
    protected function setEnv(array $paths): void
    {
        putenv('COMPOSER_ORIGINAL_INIS='.implode(PATH_SEPARATOR, $paths));
    }
}
