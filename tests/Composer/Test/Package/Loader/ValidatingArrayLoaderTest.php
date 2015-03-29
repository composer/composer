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

namespace Composer\Test\Package\Loader;

use Composer\Package;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Package\Loader\InvalidPackageException;

class ValidatingArrayLoaderTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider successProvider
     */
    public function testLoadSuccess($config)
    {
        $internalLoader = $this->getMock('Composer\Package\Loader\LoaderInterface');
        $internalLoader
            ->expects($this->once())
            ->method('load')
            ->with($config);

        $loader = new ValidatingArrayLoader($internalLoader, true, null, ValidatingArrayLoader::CHECK_ALL);
        $loader->load($config);
    }

    public function successProvider()
    {
        return array(
            array( // minimal
                array(
                    'name' => 'foo/bar',
                ),
            ),
            array( // complete
                array(
                    'name' => 'foo/bar',
                    'description' => 'Foo bar',
                    'version' => '1.0.0',
                    'type' => 'library',
                    'keywords' => array('a', 'b_c', 'D E'),
                    'homepage' => 'https://foo.com',
                    'time' => '2010-10-10T10:10:10+00:00',
                    'license' => 'MIT',
                    'authors' => array(
                        array(
                            'name' => 'Alice',
                            'email' => 'alice@example.org',
                            'role' => 'Lead',
                            'homepage' => 'http://example.org',
                        ),
                        array(
                            'name' => 'Bob',
                            'homepage' => '',
                        ),
                    ),
                    'support' => array(
                        'email' => 'mail@example.org',
                        'issues' => 'http://example.org/',
                        'forum' => 'http://example.org/',
                        'wiki' => 'http://example.org/',
                        'source' => 'http://example.org/',
                        'irc' => 'irc://example.org/example',
                    ),
                    'require' => array(
                        'a/b' => '1.*',
                        'b/c' => '~2',
                        'example' => '>2.0-dev,<2.4-dev',
                    ),
                    'require-dev' => array(
                        'a/b' => '1.*',
                        'b/c' => '*',
                        'example' => '>2.0-dev,<2.4-dev',
                    ),
                    'conflict' => array(
                        'a/b' => '1.*',
                        'b/c' => '>2.7',
                        'example' => '>2.0-dev,<2.4-dev',
                    ),
                    'replace' => array(
                        'a/b' => '1.*',
                        'example' => '>2.0-dev,<2.4-dev',
                    ),
                    'provide' => array(
                        'a/b' => '1.*',
                        'example' => '>2.0-dev,<2.4-dev',
                    ),
                    'suggest' => array(
                        'foo/bar' => 'Foo bar is very useful',
                    ),
                    'autoload' => array(
                        'psr-0' => array(
                            'Foo\\Bar' => 'src/',
                            '' => 'fallback/libs/',
                        ),
                        'classmap' => array(
                            'dir/',
                            'dir2/file.php',
                        ),
                        'files' => array(
                            'functions.php',
                        ),
                    ),
                    'include-path' => array(
                        'lib/',
                    ),
                    'target-dir' => 'Foo/Bar',
                    'minimum-stability' => 'dev',
                    'repositories' => array(
                        array(
                            'type' => 'composer',
                            'url' => 'http://packagist.org/',
                        )
                    ),
                    'config' => array(
                        'bin-dir' => 'bin',
                        'vendor-dir' => 'vendor',
                        'process-timeout' => 10000,
                    ),
                    'archive' => array(
                        'exclude' => array('/foo/bar', 'baz', '!/foo/bar/baz'),
                    ),
                    'scripts' => array(
                        'post-update-cmd' => 'Foo\\Bar\\Baz::doSomething',
                        'post-install-cmd' => array(
                            'Foo\\Bar\\Baz::doSomething',
                        ),
                    ),
                    'extra' => array(
                        'random' => array('stuff' => array('deeply' => 'nested')),
                        'branch-alias' => array(
                            'dev-master' => '2.0-dev',
                            'dev-old' => '1.0.x-dev',
                            '3.x-dev' => '3.1.x-dev'
                        ),
                    ),
                    'bin' => array(
                        'bin/foo',
                        'bin/bar',
                    ),
                    'transport-options' => array('ssl' => array('local_cert' => '/opt/certs/test.pem'))
                ),
            ),
            array( // test as array
                array(
                    'name' => 'foo/bar',
                    'license' => array('MIT', 'WTFPL'),
                ),
            ),
        );
    }

    /**
     * @dataProvider errorProvider
     */
    public function testLoadFailureThrowsException($config, $expectedErrors)
    {
        $internalLoader = $this->getMock('Composer\Package\Loader\LoaderInterface');
        $loader = new ValidatingArrayLoader($internalLoader, true, null, ValidatingArrayLoader::CHECK_ALL);
        try {
            $loader->load($config);
            $this->fail('Expected exception to be thrown');
        } catch (InvalidPackageException $e) {
            $errors = $e->getErrors();
            sort($expectedErrors);
            sort($errors);
            $this->assertEquals($expectedErrors, $errors);
        }
    }

    /**
     * @dataProvider warningProvider
     */
    public function testLoadWarnings($config, $expectedWarnings)
    {
        $internalLoader = $this->getMock('Composer\Package\Loader\LoaderInterface');
        $loader = new ValidatingArrayLoader($internalLoader, true, null, ValidatingArrayLoader::CHECK_ALL);

        $loader->load($config);
        $warnings = $loader->getWarnings();
        sort($expectedWarnings);
        sort($warnings);
        $this->assertEquals($expectedWarnings, $warnings);
    }

    /**
     * @dataProvider warningProvider
     */
    public function testLoadSkipsWarningDataWhenIgnoringErrors($config, $expectedWarnings, $mustCheck = true)
    {
        if (!$mustCheck) {
            $this->assertTrue(true);

            return;
        }
        $internalLoader = $this->getMock('Composer\Package\Loader\LoaderInterface');
        $internalLoader
            ->expects($this->once())
            ->method('load')
            ->with(array('name' => 'a/b'));

        $loader = new ValidatingArrayLoader($internalLoader, true, null, ValidatingArrayLoader::CHECK_ALL);
        $config['name'] = 'a/b';
        $loader->load($config);
    }

    public function errorProvider()
    {
        return array(
            array(
                array(
                    'name' => 'foo',
                ),
                array(
                    'name : invalid value (foo), must match [A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*'
                )
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'homepage' => 43,
                ),
                array(
                    'homepage : should be a string, integer given',
                )
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'support' => array(
                        'source' => array(),
                    ),
                ),
                array(
                    'support.source : invalid value, must be a string',
                )
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'autoload' => 'strings',
                ),
                array(
                    'autoload : should be an array, string given'
                )
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'autoload' => array(
                        'psr0' => array(
                            'foo' => 'src',
                        ),
                    ),
                ),
                array(
                    'autoload : invalid value (psr0), must be one of psr-0, psr-4, classmap, files'
                )
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'transport-options' => 'test',
                ),
                array(
                    'transport-options : should be an array, string given'
                )
            ),
        );
    }

    public function warningProvider()
    {
        return array(
            array(
                array(
                    'name' => 'foo/bar',
                    'homepage' => 'foo:bar',
                ),
                array(
                    'homepage : invalid value (foo:bar), must be an http/https URL'
                )
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'support' => array(
                        'source' => 'foo:bar',
                        'forum' => 'foo:bar',
                        'issues' => 'foo:bar',
                        'wiki' => 'foo:bar',
                    ),
                ),
                array(
                    'support.source : invalid value (foo:bar), must be an http/https URL',
                    'support.forum : invalid value (foo:bar), must be an http/https URL',
                    'support.issues : invalid value (foo:bar), must be an http/https URL',
                    'support.wiki : invalid value (foo:bar), must be an http/https URL',
                )
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'require' => array(
                        'foo/baz' => '*',
                        'bar/baz' => '>=1.0',
                        'bar/foo' => 'dev-master',
                        'bar/hacked' => '@stable',
                    ),
                ),
                array(
                    'require.foo/baz : unbound version constraints (*) should be avoided',
                    'require.bar/baz : unbound version constraints (>=1.0) should be avoided',
                    'require.bar/foo : unbound version constraints (dev-master) should be avoided',
                    'require.bar/hacked : unbound version constraints (@stable) should be avoided',
                ),
                false
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'extra' => array(
                        'branch-alias' => array(
                            '5.x-dev' => '3.1.x-dev'
                        ),
                    )
                ),
                array(
                    'extra.branch-alias.5.x-dev : the target branch (3.1.x-dev) is not a valid numeric alias for this version'
                ),
                false
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'extra' => array(
                        'branch-alias' => array(
                            '5.x-dev' => '3.1-dev'
                        ),
                    )
                ),
                array(
                    'extra.branch-alias.5.x-dev : the target branch (3.1-dev) is not a valid numeric alias for this version'
                ),
                false
            ),
        );
    }
}
