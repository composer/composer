<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *     Till Klampaeckel <till@php.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// is this namespace correct? I mean, who cares... but?
namespace Composer\Test\Json;

/**
 * Why does composer force an install when I need an autoloader instead?
 */
$root = dirname(dirname(dirname(dirname(__DIR__)))) . '/src';
set_include_path($root . ':' . get_include_path());

require_once $root . '/Composer/Autoload/ClassLoader.php';
$loader = new \Composer\Autoload\ClassLoader;
$loader->register();
$loader->setUseIncludePath(true);

//use Symfony\Component\Process\ExecutableFinder;
//use Composer\Package\Dumper\ArrayDumper;
use Composer\Repository\VcsRepository;
use Composer\Repository\Vcs\SvnDriver;
//use Composer\Util\Filesystem;
//use Composer\Util\ProcessExecutor;
use Composer\IO\NullIO;

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
