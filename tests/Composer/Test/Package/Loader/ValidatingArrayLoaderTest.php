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

    public static function successProvider(): array
    {
        return [
            [ // minimal
                [
                    'name' => 'foo/bar',
                ],
            ],
            [ // complete
                [
                    'name' => 'foo/bar',
                    'description' => 'Foo bar',
                    'version' => '1.0.0',
                    'type' => 'library',
                    'keywords' => ['a', 'b_c', 'D E', 'éîüø', '微信'],
                    'homepage' => 'https://foo.com',
                    'time' => '2010-10-10T10:10:10+00:00',
                    'license' => 'MIT',
                    'authors' => [
                        [
                            'name' => 'Alice',
                            'email' => 'alice@example.org',
                            'role' => 'Lead',
                            'homepage' => 'http://example.org',
                        ],
                        [
                            'name' => 'Bob',
                            'homepage' => '',
                        ],
                    ],
                    'support' => [
                        'email' => 'mail@example.org',
                        'issues' => 'http://example.org/',
                        'forum' => 'http://example.org/',
                        'wiki' => 'http://example.org/',
                        'source' => 'http://example.org/',
                        'irc' => 'irc://example.org/example',
                        'rss' => 'http://example.org/rss',
                        'chat' => 'http://example.org/chat',
                        'security' => 'https://example.org/security',
                    ],
                    'funding' => [
                        [
                            'type' => 'example',
                            'url' => 'https://example.org/fund',
                        ],
                        [
                            'url' => 'https://example.org/fund',
                        ],
                    ],
                    'require' => [
                        'a/b' => '1.*',
                        'b/c' => '~2',
                        'example/pkg' => '>2.0-dev,<2.4-dev',
                        'composer-runtime-api' => '*',
                    ],
                    'require-dev' => [
                        'a/b' => '1.*',
                        'b/c' => '*',
                        'example/pkg' => '>2.0-dev,<2.4-dev',
                    ],
                    'conflict' => [
                        'a/bx' => '1.*',
                        'b/cx' => '>2.7',
                        'example/pkgx' => '>2.0-dev,<2.4-dev',
                    ],
                    'replace' => [
                        'a/b' => '1.*',
                        'example/pkg' => '>2.0-dev,<2.4-dev',
                    ],
                    'provide' => [
                        'a/b' => '1.*',
                        'example/pkg' => '>2.0-dev,<2.4-dev',
                    ],
                    'suggest' => [
                        'foo/bar' => 'Foo bar is very useful',
                    ],
                    'autoload' => [
                        'psr-0' => [
                            'Foo\\Bar' => 'src/',
                            '' => 'fallback/libs/',
                        ],
                        'classmap' => [
                            'dir/',
                            'dir2/file.php',
                        ],
                        'files' => [
                            'functions.php',
                        ],
                    ],
                    'include-path' => [
                        'lib/',
                    ],
                    'target-dir' => 'Foo/Bar',
                    'minimum-stability' => 'dev',
                    'repositories' => [
                        [
                            'type' => 'composer',
                            'url' => 'https://repo.packagist.org/',
                        ],
                    ],
                    'config' => [
                        'bin-dir' => 'bin',
                        'vendor-dir' => 'vendor',
                        'process-timeout' => 10000,
                    ],
                    'archive' => [
                        'exclude' => ['/foo/bar', 'baz', '!/foo/bar/baz'],
                    ],
                    'scripts' => [
                        'post-update-cmd' => 'Foo\\Bar\\Baz::doSomething',
                        'post-install-cmd' => [
                            'Foo\\Bar\\Baz::doSomething',
                        ],
                    ],
                    'extra' => [
                        'random' => ['stuff' => ['deeply' => 'nested']],
                        'branch-alias' => [
                            'dev-master' => '2.0-dev',
                            'dev-old' => '1.0.x-dev',
                            '3.x-dev' => '3.1.x-dev',
                        ],
                    ],
                    'bin' => [
                        'bin/foo',
                        'bin/bar',
                    ],
                    'transport-options' => ['ssl' => ['local_cert' => '/opt/certs/test.pem']],
                ],
            ],
            [ // test licenses as array
                [
                    'name' => 'foo/bar',
                    'license' => ['MIT', 'WTFPL'],
                ],
            ],
            [ // test bin as string
                [
                    'name' => 'foo/bar',
                    'bin' => 'bin1',
                ],
            ],
            [ // package name with dashes
                [
                    'name' => 'foo/bar-baz',
                ],
            ],
            [ // package name with dashes
                [
                    'name' => 'foo/bar--baz',
                ],
            ],
            [ // package name with dashes
                [
                    'name' => 'foo/b-ar--ba-z',
                ],
            ],
            [ // package name with dashes
                [
                    'name' => 'npm-asset/angular--core',
                ],
            ],
            [ // refs as int or string
                [
                    'name' => 'foo/bar',
                    'source' => ['url' => 'https://example.org', 'reference' => 1234, 'type' => 'baz'],
                    'dist' => ['url' => 'https://example.org', 'reference' => 'foobar', 'type' => 'baz'],
                ],
            ],
        ];
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
            ->with(['name' => 'a/b']);

        $loader = new ValidatingArrayLoader($internalLoader, true, null, ValidatingArrayLoader::CHECK_ALL);
        $config['name'] = 'a/b';
        $loader->load($config);
    }

    public static function errorProvider(): array
    {
        $invalidNames = [
            'foo',
            'foo/-bar-',
            'foo/-bar',
        ];
        $invalidNaming = [];
        foreach ($invalidNames as $invalidName) {
            $invalidNaming[] = [
                [
                    'name' => $invalidName,
                ],
                [
                    "name : $invalidName is invalid, it should have a vendor name, a forward slash, and a package name. The vendor and package name can be words separated by -, . or _. The complete name should match \"^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$\".",
                ],
            ];
        }

        $invalidNames = [
            'fo--oo/bar',
            'fo-oo/bar__baz',
            'fo-oo/bar_.baz',
            'foo/bar---baz',
        ];
        foreach ($invalidNames as $invalidName) {
            $invalidNaming[] = [
                [
                    'name' => $invalidName,
                ],
                [
                    "name : $invalidName is invalid, it should have a vendor name, a forward slash, and a package name. The vendor and package name can be words separated by -, . or _. The complete name should match \"^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$\".",
                ],
                false,
            ];
        }

        return array_merge($invalidNaming, [
            [
                [
                    'name' => 'foo/bar',
                    'homepage' => 43,
                ],
                [
                    'homepage : should be a string, integer given',
                ],
            ],
            [
                [
                    'name' => 'foo/bar',
                    'support' => [
                        'source' => [],
                    ],
                ],
                [
                    'support.source : invalid value, must be a string',
                ],
            ],
            [
                [
                    'name' => 'foo/bar.json',
                ],
                [
                    'name : foo/bar.json is invalid, package names can not end in .json, consider renaming it or perhaps using a -json suffix instead.',
                ],
            ],
            [
                [
                    'name' => 'com1/foo',
                ],
                [
                    'name : com1/foo is reserved, package and vendor names can not match any of: nul, con, prn, aux, com1, com2, com3, com4, com5, com6, com7, com8, com9, lpt1, lpt2, lpt3, lpt4, lpt5, lpt6, lpt7, lpt8, lpt9.',
                ],
            ],
            [
                [
                    'name' => 'Foo/Bar',
                ],
                [
                    'name : Foo/Bar is invalid, it should not contain uppercase characters. We suggest using foo/bar instead.',
                ],
            ],
            [
                [
                    'name' => 'foo/bar',
                    'autoload' => 'strings',
                ],
                [
                    'autoload : should be an array, string given',
                ],
            ],
            [
                [
                    'name' => 'foo/bar',
                    'autoload' => [
                        'psr0' => [
                            'foo' => 'src',
                        ],
                    ],
                ],
                [
                    'autoload : invalid value (psr0), must be one of psr-0, psr-4, classmap, files, exclude-from-classmap',
                ],
            ],
            [
                [
                    'name' => 'foo/bar',
                    'transport-options' => 'test',
                ],
                [
                    'transport-options : should be an array, string given',
                ],
            ],
            [
                [
                    'name' => 'foo/bar',
                    'source' => ['url' => '--foo', 'reference' => ' --bar', 'type' => 'baz'],
                    'dist' => ['url' => ' --foox', 'reference' => '--barx', 'type' => 'baz'],
                ],
                [
                    'dist.reference : must not start with a "-", "--barx" given',
                    'dist.url : must not start with a "-", " --foox" given',
                    'source.reference : must not start with a "-", " --bar" given',
                    'source.url : must not start with a "-", "--foo" given',
                ],
            ],
            [
                [
                    'name' => 'foo/bar',
                    'require' => ['foo/Bar' => '1.*'],
                ],
                [
                    'require.foo/Bar : a package cannot set a require on itself',
                ],
            ],
            [
                [
                    'name' => 'foo/bar',
                    'source' => ['url' => 1],
                    'dist' => ['url' => null],
                ],
                [
                    'source.type : must be present',
                    'source.url : should be a string, integer given',
                    'source.reference : must be present',
                    'dist.type : must be present',
                    'dist.url : must be present',
                ],
            ],
            [
                [
                    'name' => 'foo/bar',
                    'replace' => ['acme/bar'],
                ],
                ['replace.0 : invalid version constraint (Could not parse version constraint acme/bar: Invalid version string "acme/bar")'],
            ],
            [
                [
                    'require' => ['acme/bar' => '^1.0']
                ],
                ['name : must be present'],
            ]
        ]);
    }

    public static function warningProvider(): array
    {
        return [
            [
                [
                    'name' => 'foo/bar',
                    'homepage' => 'foo:bar',
                ],
                [
                    'homepage : invalid value (foo:bar), must be an http/https URL',
                ],
            ],
            [
                [
                    'name' => 'foo/bar',
                    'support' => [
                        'source' => 'foo:bar',
                        'forum' => 'foo:bar',
                        'issues' => 'foo:bar',
                        'wiki' => 'foo:bar',
                        'chat' => 'foo:bar',
                        'security' => 'foo:bar',
                    ],
                ],
                [
                    'support.source : invalid value (foo:bar), must be an http/https URL',
                    'support.forum : invalid value (foo:bar), must be an http/https URL',
                    'support.issues : invalid value (foo:bar), must be an http/https URL',
                    'support.wiki : invalid value (foo:bar), must be an http/https URL',
                    'support.chat : invalid value (foo:bar), must be an http/https URL',
                    'support.security : invalid value (foo:bar), must be an http/https URL',
                ],
            ],
            [
                [
                    'name' => 'foo/bar',
                    'require' => [
                        'foo/baz' => '*',
                        'bar/baz' => '>=1.0',
                        'bar/hacked' => '@stable',
                        'bar/woo' => '1.0.0',
                    ],
                ],
                [
                    'require.foo/baz : unbound version constraints (*) should be avoided',
                    'require.bar/baz : unbound version constraints (>=1.0) should be avoided',
                    'require.bar/hacked : unbound version constraints (@stable) should be avoided',
                    'require.bar/woo : exact version constraints (1.0.0) should be avoided if the package follows semantic versioning',
                ],
                false,
            ],
            [
                [
                    'name' => 'foo/bar',
                    'require' => [
                        'foo/baz' => '>1, <0.5',
                        'bar/baz' => 'dev-main, >0.5',
                    ],
                ],
                [
                    'require.foo/baz : this version constraint cannot possibly match anything (>1, <0.5)',
                    'require.bar/baz : this version constraint cannot possibly match anything (dev-main, >0.5)',
                ],
                false,
            ],
            [
                [
                    'name' => 'foo/bar',
                    'require' => [
                        'bar/unstable' => '0.3.0',
                    ],
                ],
                [
                    // using an exact version constraint for an unstable version should not trigger a warning
                ],
                false,
            ],
            [
                [
                    'name' => 'foo/bar',
                    'extra' => [
                        'branch-alias' => [
                            '5.x-dev' => '3.1.x-dev',
                        ],
                    ],
                ],
                [
                    'extra.branch-alias.5.x-dev : the target branch (3.1.x-dev) is not a valid numeric alias for this version',
                ],
                false,
            ],
            [
                [
                    'name' => 'foo/bar',
                    'extra' => [
                        'branch-alias' => [
                            '5.x-dev' => '3.1-dev',
                        ],
                    ],
                ],
                [
                    'extra.branch-alias.5.x-dev : the target branch (3.1-dev) is not a valid numeric alias for this version',
                ],
                false,
            ],
            [
                [
                    'name' => 'foo/bar',
                    'require' => [
                        'Foo/Baz' => '^1.0',
                    ],
                ],
                [
                    'require.Foo/Baz is invalid, it should not contain uppercase characters. Please use foo/baz instead.',
                ],
                false,
            ],
        ];
    }
}
