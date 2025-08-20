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

use Composer\Config;
use Composer\IO\NullIO;
use Composer\Util\Svn;
use Composer\Test\TestCase;

class SvnTest extends TestCase
{
    /**
     * Test the credential string.
     *
     * @param string $url    The SVN url.
     * @param non-empty-list<string> $expect The expectation for the test.
     *
     * @dataProvider urlProvider
     */
    public function testCredentials(string $url, array $expect): void
    {
        $svn = new Svn($url, new NullIO, new Config());
        $reflMethod = new \ReflectionMethod('Composer\\Util\\Svn', 'getCredentialArgs');
        (\PHP_VERSION_ID < 80100) and $reflMethod->setAccessible(true);

        self::assertEquals($expect, $reflMethod->invoke($svn));
    }

    public static function urlProvider(): array
    {
        return [
            ['http://till:test@svn.example.org/', ['--username', 'till', '--password', 'test']],
            ['http://svn.apache.org/', []],
            ['svn://johndoe@example.org', ['--username', 'johndoe', '--password', '']],
        ];
    }

    public function testInteractiveString(): void
    {
        $url = 'http://svn.example.org';

        $svn = new Svn($url, new NullIO(), new Config());
        $reflMethod = new \ReflectionMethod('Composer\\Util\\Svn', 'getCommand');
        (\PHP_VERSION_ID < 80100) and $reflMethod->setAccessible(true);

        self::assertEquals(
            ['svn', 'ls', '--non-interactive', '--', 'http://svn.example.org'],
            $reflMethod->invokeArgs($svn, [['svn', 'ls'], $url])
        );
    }

    public function testCredentialsFromConfig(): void
    {
        $url = 'http://svn.apache.org';

        $config = new Config();
        $config->merge([
            'config' => [
                'http-basic' => [
                    'svn.apache.org' => ['username' => 'foo', 'password' => 'bar'],
                ],
            ],
        ]);

        $svn = new Svn($url, new NullIO, $config);
        $reflMethod = new \ReflectionMethod('Composer\\Util\\Svn', 'getCredentialArgs');
        (\PHP_VERSION_ID < 80100) and $reflMethod->setAccessible(true);

        self::assertEquals(['--username', 'foo', '--password', 'bar'], $reflMethod->invoke($svn));
    }

    public function testCredentialsFromConfigWithCacheCredentialsTrue(): void
    {
        $url = 'http://svn.apache.org';

        $config = new Config();
        $config->merge(
            [
                'config' => [
                    'http-basic' => [
                        'svn.apache.org' => ['username' => 'foo', 'password' => 'bar'],
                    ],
                ],
            ]
        );

        $svn = new Svn($url, new NullIO, $config);
        $svn->setCacheCredentials(true);
        $reflMethod = new \ReflectionMethod('Composer\\Util\\Svn', 'getCredentialArgs');
        (\PHP_VERSION_ID < 80100) and $reflMethod->setAccessible(true);

        self::assertEquals(['--username', 'foo', '--password', 'bar'], $reflMethod->invoke($svn));
    }

    public function testCredentialsFromConfigWithCacheCredentialsFalse(): void
    {
        $url = 'http://svn.apache.org';

        $config = new Config();
        $config->merge(
            [
                'config' => [
                    'http-basic' => [
                        'svn.apache.org' => ['username' => 'foo', 'password' => 'bar'],
                    ],
                ],
            ]
        );

        $svn = new Svn($url, new NullIO, $config);
        $svn->setCacheCredentials(false);
        $reflMethod = new \ReflectionMethod('Composer\\Util\\Svn', 'getCredentialArgs');
        (\PHP_VERSION_ID < 80100) and $reflMethod->setAccessible(true);

        self::assertEquals(['--no-auth-cache', '--username', 'foo', '--password', 'bar'], $reflMethod->invoke($svn));
    }
}
