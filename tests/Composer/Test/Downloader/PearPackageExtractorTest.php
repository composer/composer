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

namespace Composer\Test\Downloader;

use Composer\Downloader\PearPackageExtractor;

class PearPackageExtractorTest extends \PHPUnit_Framework_TestCase
{
    public function testShouldExtractPackage_1_0()
    {
        $extractor = $this->getMockForAbstractClass('Composer\Downloader\PearPackageExtractor', array(), '', false);
        $method = new \ReflectionMethod($extractor, 'buildCopyActions');
        $method->setAccessible(true);

        $fileActions = $method->invoke($extractor, __DIR__ . '/Fixtures/Package_v1.0', array('php' => '/'), array());

        $expectedFileActions = array(
            'Gtk.php' => Array(
                'from' => 'PEAR_Frontend_Gtk-0.4.0/Gtk.php',
                'to' => 'PEAR/Frontend/Gtk.php',
                'role' => 'php',
                'tasks' => array(),
            ),
            'Gtk/Config.php' => Array(
                'from' => 'PEAR_Frontend_Gtk-0.4.0/Gtk/Config.php',
                'to' => 'PEAR/Frontend/Gtk/Config.php',
                'role' => 'php',
                'tasks' => array(),
            ),
            'Gtk/xpm/black_close_icon.xpm' => Array(
                'from' => 'PEAR_Frontend_Gtk-0.4.0/Gtk/xpm/black_close_icon.xpm',
                'to' => 'PEAR/Frontend/Gtk/xpm/black_close_icon.xpm',
                'role' => 'php',
                'tasks' => array(),
            )
        );
        $this->assertSame($expectedFileActions, $fileActions);
    }

    public function testShouldExtractPackage_2_0()
    {
        $extractor = $this->getMockForAbstractClass('Composer\Downloader\PearPackageExtractor', array(), '', false);
        $method = new \ReflectionMethod($extractor, 'buildCopyActions');
        $method->setAccessible(true);

        $fileActions = $method->invoke($extractor, __DIR__ . '/Fixtures/Package_v2.0', array('php' => '/'), array());

        $expectedFileActions = array(
            'URL.php' => Array(
                'from' => 'Net_URL-1.0.15/URL.php',
                'to' => 'Net/URL.php',
                'role' => 'php',
                'tasks' => array(),
            )
        );
        $this->assertSame($expectedFileActions, $fileActions);
    }

    public function testShouldExtractPackage_2_1()
    {
        $extractor = $this->getMockForAbstractClass('Composer\Downloader\PearPackageExtractor', array(), '', false);
        $method = new \ReflectionMethod($extractor, 'buildCopyActions');
        $method->setAccessible(true);

        $fileActions = $method->invoke($extractor, __DIR__ . '/Fixtures/Package_v2.1', array('php' => '/', 'script' => '/bin'), array());

        $expectedFileActions = array(
            'php/Zend/Authentication/Storage/StorageInterface.php' => Array(
                'from' => 'Zend_Authentication-2.0.0beta4/php/Zend/Authentication/Storage/StorageInterface.php',
                'to' => '/php/Zend/Authentication/Storage/StorageInterface.php',
                'role' => 'php',
                'tasks' => array(),
            ),
            'php/Zend/Authentication/Result.php' => Array(
                'from' => 'Zend_Authentication-2.0.0beta4/php/Zend/Authentication/Result.php',
                'to' => '/php/Zend/Authentication/Result.php',
                'role' => 'php',
                'tasks' => array(),
            ),
            'php/Test.php' => array (
                'from' => 'Zend_Authentication-2.0.0beta4/php/Test.php',
                'to' => '/php/Test.php',
                'role' => 'script',
                'tasks' => array (
                    array (
                        'from' => '@version@',
                        'to' => 'version',
                    )
                )
            ),
            'renamedFile.php' => Array(
                'from' => 'Zend_Authentication-2.0.0beta4/renamedFile.php',
                'to' => 'correctFile.php',
                'role' => 'php',
                'tasks' => array(),
            ),
        );
        $this->assertSame($expectedFileActions, $fileActions);
    }

    public function testShouldPerformReplacements()
    {
        $from = tempnam(sys_get_temp_dir(), 'pear-extract');
        $to = $from.'-to';

        $original = 'replaced: @placeholder@; not replaced: @another@; replaced again: @placeholder@';
        $expected = 'replaced: value; not replaced: @another@; replaced again: value';

        file_put_contents($from, $original);

        $extractor = new PearPackageExtractor($from);
        $method = new \ReflectionMethod($extractor, 'copyFile');
        $method->setAccessible(true);

        $method->invoke($extractor, $from, $to, array(array('from' => '@placeholder@', 'to' => 'variable')), array('variable' => 'value'));
        $result = file_get_contents($to);

        unlink($to);
        unlink($from);

        $this->assertEquals($expected, $result);
    }
}
