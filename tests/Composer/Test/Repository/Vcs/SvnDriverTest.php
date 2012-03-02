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

namespace Composer\Test\Repository\Vcs;

/**
 * Why does composer force an install when I need an autoloader instead?
 */
$root = dirname(dirname(dirname(dirname(dirname(__DIR__))))) . '/src';
set_include_path($root . ':' . get_include_path());

require_once $root . '/Composer/Autoload/ClassLoader.php';
$loader = new \Composer\Autoload\ClassLoader;
$loader->register();
$loader->setUseIncludePath(true);

use Composer\Repository\Vcs\SvnDriver;
use Composer\IO\NullIO;

/**
 * @author Till Klampaeckel <till@php.net>
 */
class SvnDriverTest extends \PHPUnit_Framework_TestCase
{
    private static $gitRepo;
    private static $skipped;

    public function setUp()
    {
        if (self::$skipped) {
            $this->markTestSkipped(self::$skipped);
        }
    }

    /**
     * Provide some examples for {@self::testCredentials()}.
     *
     * @return array
     */
    public static function urlProvider()
    {
        return array(
            array('http://till:test@svn.example.org/', ' --no-auth-cache --username "till" --password "test" '),
            array('http://svn.apache.org/', ''),
            array('svn://johndoe@example.org', ' --no-auth-cache --username "johndoe" --password "" '),
        );
    }

    /**
     * @dataProvider urlProvider
     */
    public function testCredentials($url, $expect)
    {
        $io  = new \Composer\IO\NullIO;
        $svn = new SvnDriver($url, $io);

        $this->assertEquals($expect, $svn->getSvnCredentialString());
    }
}
