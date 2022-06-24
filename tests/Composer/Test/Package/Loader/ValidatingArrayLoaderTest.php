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

namespace Composer\Test\Package\Loader;

use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Package\Loader\InvalidPackageException;
use Composer\Test\TestCase;

class ValidatingArrayLoaderTest extends TestCase
{
    /**
     * @dataProvider successProvider
     *
     * @param array<string, mixed> $config
     */
    public function testLoadSuccess(array $config): void
    {
        $internalLoader = $this->getMockBuilder('Composer\Package\Loader\LoaderInterface')->getMock();
        $internalLoader
            ->expects($this->once())
            ->method('load')
            ->with($config);

        $loader = new ValidatingArrayLoader($internalLoader, true, null, ValidatingArrayLoader::CHECK_ALL);
        $loader->load($config);
    }

    public function successProvider(): array
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
                    'keywords' => array('a', 'b_c', 'D E', 'éîüø', '微信'),
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
                        'rss' => 'http://example.org/rss',
                        'chat' => 'http://example.org/chat',
                    ),
                    'funding' => array(
                        array(
                            'type' => 'example',
                            'url' => 'https://example.org/fund',
                        ),
                        array(
                            'url' => 'https://example.org/fund',
                        ),
                    ),
                    'require' => array(
                        'a/b' => '1.*',
                        'b/c' => '~2',
                        'example/pkg' => '>2.0-dev,<2.4-dev',
                        'composer-runtime-api' => '*',
                    ),
                    'require-dev' => array(
                        'a/b' => '1.*',
                        'b/c' => '*',
                        'example/pkg' => '>2.0-dev,<2.4-dev',
                    ),
                    'conflict' => array(
                        'a/bx' => '1.*',
                        'b/cx' => '>2.7',
                        'example/pkgx' => '>2.0-dev,<2.4-dev',
                    ),
                    'replace' => array(
                        'a/b' => '1.*',
                        'example/pkg' => '>2.0-dev,<2.4-dev',
                    ),
                    'provide' => array(
                        'a/b' => '1.*',
                        'example/pkg' => '>2.0-dev,<2.4-dev',
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
                            'url' => 'https://repo.packagist.org/',
                        ),
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
                            '3.x-dev' => '3.1.x-dev',
                        ),
                    ),
                    'bin' => array(
                        'bin/foo',
                        'bin/bar',
                    ),
                    'transport-options' => array('ssl' => array('local_cert' => '/opt/certs/test.pem')),
                ),
            ),
            array( // test licenses as array
                array(
                    'name' => 'foo/bar',
                    'license' => array('MIT', 'WTFPL'),
                ),
            ),
            array( // test bin as string
                array(
                    'name' => 'foo/bar',
                    'bin' => 'bin1',
                ),
            ),
            array( // package name with dashes
                array(
                    'name' => 'foo/bar-baz',
                ),
            ),
            array( // package name with dashes
                array(
                    'name' => 'foo/bar--baz',
                ),
            ),
            array( // package name with dashes
                array(
                    'name' => 'foo/b-ar--ba-z',
                ),
            ),
            array( // package name with dashes
                array(
                    'name' => 'npm-asset/angular--core',
                ),
            ),
            array( // refs as int or string
                array(
                    'name' => 'foo/bar',
                    'source' => array('url' => 'https://example.org', 'reference' => 1234, 'type' => 'baz'),
                    'dist' => array('url' => 'https://example.org', 'reference' => 'foobar', 'type' => 'baz'),
                ),
            ),
        );
    }

    /**
     * @dataProvider errorProvider
     *
     * @param array<string, mixed> $config
     * @param string[]             $expectedErrors
     */
    public function testLoadFailureThrowsException(array $config, array $expectedErrors): void
    {
        $internalLoader = $this->getMockBuilder('Composer\Package\Loader\LoaderInterface')->getMock();
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
     *
     * @param array<string, mixed> $config
     * @param string[]             $expectedWarnings
     */
    public function testLoadWarnings(array $config, array $expectedWarnings): void
    {
        $internalLoader = $this->getMockBuilder('Composer\Package\Loader\LoaderInterface')->getMock();
        $loader = new ValidatingArrayLoader($internalLoader, true, null, ValidatingArrayLoader::CHECK_ALL);

        $loader->load($config);
        $warnings = $loader->getWarnings();
        sort($expectedWarnings);
        sort($warnings);
        $this->assertEquals($expectedWarnings, $warnings);
    }

    /**
     * @dataProvider warningProvider
     *
     * @param array<string, mixed> $config
     * @param string[]             $expectedWarnings
     * @param bool                 $mustCheck
     */
    public function testLoadSkipsWarningDataWhenIgnoringErrors(array $config, array $expectedWarnings, bool $mustCheck = true): void
    {
        if (!$mustCheck) {
            $this->assertTrue(true);

            return;
        }
        $internalLoader = $this->getMockBuilder('Composer\Package\Loader\LoaderInterface')->getMock();
        $internalLoader
            ->expects($this->once())
            ->method('load')
            ->with(array('name' => 'a/b'));

        $loader = new ValidatingArrayLoader($internalLoader, true, null, ValidatingArrayLoader::CHECK_ALL);
        $config['name'] = 'a/b';
        $loader->load($config);
    }

    public function errorProvider(): array
    {
        $invalidNames = array(
            'foo',
            'foo/-bar-',
            'foo/-bar',
        );
        $invalidNaming = array();
        foreach ($invalidNames as $invalidName) {
            $invalidNaming[] = array(
                array(
                    'name' => $invalidName,
                ),
                array(
                    "name : $invalidName is invalid, it should have a vendor name, a forward slash, and a package name. The vendor and package name can be words separated by -, . or _. The complete name should match \"^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$\".",
                ),
            );
        }

        $invalidNames = array(
            'fo--oo/bar',
            'fo-oo/bar__baz',
            'fo-oo/bar_.baz',
            'foo/bar---baz',
        );
        foreach ($invalidNames as $invalidName) {
            $invalidNaming[] = array(
                array(
                    'name' => $invalidName,
                ),
                array(
                    "name : $invalidName is invalid, it should have a vendor name, a forward slash, and a package name. The vendor and package name can be words separated by -, . or _. The complete name should match \"^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$\".",
                ),
                false,
            );
        }

        return array_merge($invalidNaming, array(
            array(
                array(
                    'name' => 'foo/bar',
                    'homepage' => 43,
                ),
                array(
                    'homepage : should be a string, integer given',
                ),
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
                ),
            ),
            array(
                array(
                    'name' => 'foo/bar.json',
                ),
                array(
                    'name : foo/bar.json is invalid, package names can not end in .json, consider renaming it or perhaps using a -json suffix instead.',
                ),
            ),
            array(
                array(
                    'name' => 'com1/foo',
                ),
                array(
                    'name : com1/foo is reserved, package and vendor names can not match any of: nul, con, prn, aux, com1, com2, com3, com4, com5, com6, com7, com8, com9, lpt1, lpt2, lpt3, lpt4, lpt5, lpt6, lpt7, lpt8, lpt9.',
                ),
            ),
            array(
                array(
                    'name' => 'Foo/Bar',
                ),
                array(
                    'name : Foo/Bar is invalid, it should not contain uppercase characters. We suggest using foo/bar instead.',
                ),
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'autoload' => 'strings',
                ),
                array(
                    'autoload : should be an array, string given',
                ),
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
                    'autoload : invalid value (psr0), must be one of psr-0, psr-4, classmap, files, exclude-from-classmap',
                ),
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'transport-options' => 'test',
                ),
                array(
                    'transport-options : should be an array, string given',
                ),
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'source' => array('url' => '--foo', 'reference' => ' --bar', 'type' => 'baz'),
                    'dist' => array('url' => ' --foox', 'reference' => '--barx', 'type' => 'baz'),
                ),
                array(
                    'dist.reference : must not start with a "-", "--barx" given',
                    'dist.url : must not start with a "-", " --foox" given',
                    'source.reference : must not start with a "-", " --bar" given',
                    'source.url : must not start with a "-", "--foo" given',
                ),
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'require' => array('foo/Bar' => '1.*'),
                ),
                array(
                    'require.foo/Bar : a package cannot set a require on itself',
                ),
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'source' => array('url' => 1),
                    'dist' => array('url' => null),
                ),
                array(
                    'source.type : must be present',
                    'source.url : should be a string, integer given',
                    'source.reference : must be present',
                    'dist.type : must be present',
                    'dist.url : must be present',
                ),
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'replace' => array('acme/bar'),
                ),
                array('replace.0 : invalid version constraint (Could not parse version constraint foo/Bar: Invalid version string "foo/Bar")')
            ),
        ));
    }

    public function warningProvider(): array
    {
        return array(
            array(
                array(
                    'name' => 'foo/bar',
                    'homepage' => 'foo:bar',
                ),
                array(
                    'homepage : invalid value (foo:bar), must be an http/https URL',
                ),
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'support' => array(
                        'source' => 'foo:bar',
                        'forum' => 'foo:bar',
                        'issues' => 'foo:bar',
                        'wiki' => 'foo:bar',
                        'chat' => 'foo:bar',
                    ),
                ),
                array(
                    'support.source : invalid value (foo:bar), must be an http/https URL',
                    'support.forum : invalid value (foo:bar), must be an http/https URL',
                    'support.issues : invalid value (foo:bar), must be an http/https URL',
                    'support.wiki : invalid value (foo:bar), must be an http/https URL',
                    'support.chat : invalid value (foo:bar), must be an http/https URL',
                ),
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'require' => array(
                        'foo/baz' => '*',
                        'bar/baz' => '>=1.0',
                        'bar/hacked' => '@stable',
                        'bar/woo' => '1.0.0',
                    ),
                ),
                array(
                    'require.foo/baz : unbound version constraints (*) should be avoided',
                    'require.bar/baz : unbound version constraints (>=1.0) should be avoided',
                    'require.bar/hacked : unbound version constraints (@stable) should be avoided',
                    'require.bar/woo : exact version constraints (1.0.0) should be avoided if the package follows semantic versioning',
                ),
                false,
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'require' => array(
                        'bar/unstable' => '0.3.0',
                    ),
                ),
                array(
                    // using an exact version constraint for an unstable version should not trigger a warning
                ),
                false,
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'extra' => array(
                        'branch-alias' => array(
                            '5.x-dev' => '3.1.x-dev',
                        ),
                    ),
                ),
                array(
                    'extra.branch-alias.5.x-dev : the target branch (3.1.x-dev) is not a valid numeric alias for this version',
                ),
                false,
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'extra' => array(
                        'branch-alias' => array(
                            '5.x-dev' => '3.1-dev',
                        ),
                    ),
                ),
                array(
                    'extra.branch-alias.5.x-dev : the target branch (3.1-dev) is not a valid numeric alias for this version',
                ),
                false,
            ),
            array(
                array(
                    'name' => 'foo/bar',
                    'require' => array(
                        'Foo/Baz' => '^1.0',
                    ),
                ),
                array(
                    'require.Foo/Baz is invalid, it should not contain uppercase characters. Please use foo/baz instead.',
                ),
                false,
            ),
        );
    }
}
