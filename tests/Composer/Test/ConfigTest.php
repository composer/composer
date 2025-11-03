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

namespace Composer\Test;

use Composer\Advisory\Auditor;
use Composer\Config;
use Composer\Util\Platform;

class ConfigTest extends TestCase
{
    /**
     * @dataProvider dataAddPackagistRepository
     * @param mixed[] $expected
     * @param mixed[] $localConfig
     * @param ?array<mixed> $systemConfig
     */
    public function testAddPackagistRepository(array $expected, array $localConfig, ?array $systemConfig = null): void
    {
        $config = new Config(false);
        if ($systemConfig) {
            $config->merge(['repositories' => $systemConfig]);
        }
        $config->merge(['repositories' => $localConfig]);

        self::assertEquals($expected, $config->getRepositories());
    }

    public static function dataAddPackagistRepository(): array
    {
        $data = [];
        $data['local config inherits system defaults'] = [
            [
                'packagist.org' => ['type' => 'composer', 'url' => 'https://repo.packagist.org'],
            ],
            [],
        ];

        $data['local config can disable system config by name'] = [
            [],
            [
                ['packagist.org' => false],
            ],
        ];

        $data['local config can disable system config by name bc'] = [
            [],
            [
                ['packagist' => false],
            ],
        ];

        $data['local config adds above defaults'] = [
            [
                0 => ['type' => 'vcs', 'url' => 'git://github.com/composer/composer.git'],
                1 => ['type' => 'pear', 'url' => 'http://pear.composer.org'],
                'packagist.org' => ['type' => 'composer', 'url' => 'https://repo.packagist.org'],
            ],
            [
                ['type' => 'vcs', 'url' => 'git://github.com/composer/composer.git'],
                ['type' => 'pear', 'url' => 'http://pear.composer.org'],
            ],
        ];

        $data['system config adds above core defaults'] = [
            [
                'example.com' => ['type' => 'composer', 'url' => 'http://example.com'],
                'packagist.org' => ['type' => 'composer', 'url' => 'https://repo.packagist.org'],
            ],
            [],
            [
                'example.com' => ['type' => 'composer', 'url' => 'http://example.com'],
            ],
        ];

        $data['local config can disable repos by name and re-add them anonymously to bring them above system config'] = [
            [
                1 => ['type' => 'composer', 'url' => 'http://packagist.org'],
                'example.com' => ['type' => 'composer', 'url' => 'http://example.com'],
            ],
            [
                ['packagist.org' => false],
                ['type' => 'composer', 'url' => 'http://packagist.org'],
            ],
            [
                'example.com' => ['type' => 'composer', 'url' => 'http://example.com'],
            ],
        ];

        $data['local config can override by name to bring a repo above system config'] = [
            [
                'packagist.org' => ['type' => 'composer', 'url' => 'http://packagistnew.org'],
                'example.com' => ['type' => 'composer', 'url' => 'http://example.com'],
            ],
            [
                'packagist.org' => ['type' => 'composer', 'url' => 'http://packagistnew.org'],
            ],
            [
                'example.com' => ['type' => 'composer', 'url' => 'http://example.com'],
            ],
        ];

        $data['local config redefining packagist.org by URL override it if no named keys are used'] = [
            [
                ['type' => 'composer', 'url' => 'https://repo.packagist.org'],
            ],
            [
                ['type' => 'composer', 'url' => 'https://repo.packagist.org'],
            ],
        ];

        $data['local config redefining packagist.org by URL override it also with named keys'] = [
            [
                'example' => ['type' => 'composer', 'url' => 'https://repo.packagist.org'],
            ],
            [
                'example' => ['type' => 'composer', 'url' => 'https://repo.packagist.org'],
            ],
        ];

        $data['incorrect local config does not cause ErrorException'] = [
            [
                'packagist.org' => ['type' => 'composer', 'url' => 'https://repo.packagist.org'],
                'type' => 'vcs',
                'url' => 'http://example.com',
            ],
            [
                'type' => 'vcs',
                'url' => 'http://example.com',
            ],
        ];

        return $data;
    }

    public function testPreferredInstallAsString(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['preferred-install' => 'source']]);
        $config->merge(['config' => ['preferred-install' => 'dist']]);

        self::assertEquals('dist', $config->get('preferred-install'));
    }

    public function testMergePreferredInstall(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['preferred-install' => 'dist']]);
        $config->merge(['config' => ['preferred-install' => ['foo/*' => 'source']]]);

        // This assertion needs to make sure full wildcard preferences are placed last
        // Handled by composer because we convert string preferences for BC, all other
        // care for ordering and collision prevention is up to the user
        self::assertEquals(['foo/*' => 'source', '*' => 'dist'], $config->get('preferred-install'));
    }

    public function testMergeGithubOauth(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['github-oauth' => ['foo' => 'bar']]]);
        $config->merge(['config' => ['github-oauth' => ['bar' => 'baz']]]);

        self::assertEquals(['foo' => 'bar', 'bar' => 'baz'], $config->get('github-oauth'));
    }

    public function testVarReplacement(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['a' => 'b', 'c' => '{$a}']]);
        $config->merge(['config' => ['bin-dir' => '$HOME', 'cache-dir' => '~/foo/']]);

        $home = rtrim(getenv('HOME') ?: getenv('USERPROFILE'), '\\/');
        self::assertEquals('b', $config->get('c'));
        self::assertEquals($home, $config->get('bin-dir'));
        self::assertEquals($home.'/foo', $config->get('cache-dir'));
    }

    public function testRealpathReplacement(): void
    {
        $config = new Config(false, '/foo/bar');
        $config->merge(['config' => [
            'bin-dir' => '$HOME/foo',
            'cache-dir' => '/baz/',
            'vendor-dir' => 'vendor',
        ]]);

        $home = rtrim(getenv('HOME') ?: getenv('USERPROFILE'), '\\/');
        self::assertEquals('/foo/bar/vendor', $config->get('vendor-dir'));
        self::assertEquals($home.'/foo', $config->get('bin-dir'));
        self::assertEquals('/baz', $config->get('cache-dir'));
    }

    public function testStreamWrapperDirs(): void
    {
        $config = new Config(false, '/foo/bar');
        $config->merge(['config' => [
            'cache-dir' => 's3://baz/',
        ]]);

        self::assertEquals('s3://baz', $config->get('cache-dir'));
    }

    public function testFetchingRelativePaths(): void
    {
        $config = new Config(false, '/foo/bar');
        $config->merge(['config' => [
            'bin-dir' => '{$vendor-dir}/foo',
            'vendor-dir' => 'vendor',
        ]]);

        self::assertEquals('/foo/bar/vendor', $config->get('vendor-dir'));
        self::assertEquals('/foo/bar/vendor/foo', $config->get('bin-dir'));
        self::assertEquals('vendor', $config->get('vendor-dir', Config::RELATIVE_PATHS));
        self::assertEquals('vendor/foo', $config->get('bin-dir', Config::RELATIVE_PATHS));
    }

    public function testOverrideGithubProtocols(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['github-protocols' => ['https', 'ssh']]]);
        $config->merge(['config' => ['github-protocols' => ['https']]]);

        self::assertEquals(['https'], $config->get('github-protocols'));
    }

    public function testGitDisabledByDefaultInGithubProtocols(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['github-protocols' => ['https', 'git']]]);
        self::assertEquals(['https'], $config->get('github-protocols'));

        $config->merge(['config' => ['secure-http' => false]]);
        self::assertEquals(['https', 'git'], $config->get('github-protocols'));
    }

    /**
     * @dataProvider allowedUrlProvider
     * @doesNotPerformAssertions
     */
    public function testAllowedUrlsPass(string $url): void
    {
        $config = new Config(false);
        $config->prohibitUrlByConfig($url);
    }

    /**
     * @dataProvider prohibitedUrlProvider
     */
    public function testProhibitedUrlsThrowException(string $url): void
    {
        self::expectException('Composer\Downloader\TransportException');
        self::expectExceptionMessage('Your configuration does not allow connections to ' . $url);
        $config = new Config(false);
        $config->prohibitUrlByConfig($url);
    }

    /**
     * @return string[][] List of test URLs that should pass strict security
     */
    public static function allowedUrlProvider(): array
    {
        $urls = [
            'https://packagist.org',
            'git@github.com:composer/composer.git',
            'hg://user:pass@my.satis/satis',
            '\\myserver\myplace.git',
            'file://myserver.localhost/mygit.git',
            'file://example.org/mygit.git',
            'git:Department/Repo.git',
            'ssh://[user@]host.xz[:port]/path/to/repo.git/',
        ];

        return array_combine($urls, array_map(static function ($e): array {
            return [$e];
        }, $urls));
    }

    /**
     * @return string[][] List of test URLs that should not pass strict security
     */
    public static function prohibitedUrlProvider(): array
    {
        $urls = [
            'http://packagist.org',
            'http://10.1.0.1/satis',
            'http://127.0.0.1/satis',
            'http://💛@example.org',
            'svn://localhost/trunk',
            'svn://will.not.resolve/trunk',
            'svn://192.168.0.1/trunk',
            'svn://1.2.3.4/trunk',
            'git://5.6.7.8/git.git',
        ];

        return array_combine($urls, array_map(static function ($e): array {
            return [$e];
        }, $urls));
    }

    public function testProhibitedUrlsWarningVerifyPeer(): void
    {
        $io = $this->getIOMock();

        $io->expects([['text' => '<warning>Warning: Accessing example.org with verify_peer and verify_peer_name disabled.</warning>']], true);

        $config = new Config(false);
        $config->prohibitUrlByConfig('https://example.org', $io, [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
    }

    /**
     * @group TLS
     */
    public function testDisableTlsCanBeOverridden(): void
    {
        $config = new Config;
        $config->merge(
            ['config' => ['disable-tls' => 'false']]
        );
        self::assertFalse($config->get('disable-tls'));
        $config->merge(
            ['config' => ['disable-tls' => 'true']]
        );
        self::assertTrue($config->get('disable-tls'));
    }

    public function testProcessTimeout(): void
    {
        Platform::putEnv('COMPOSER_PROCESS_TIMEOUT', '0');
        $config = new Config(true);
        $result = $config->get('process-timeout');
        Platform::clearEnv('COMPOSER_PROCESS_TIMEOUT');

        self::assertEquals(0, $result);
    }

    public function testHtaccessProtect(): void
    {
        Platform::putEnv('COMPOSER_HTACCESS_PROTECT', '0');
        $config = new Config(true);
        $result = $config->get('htaccess-protect');
        Platform::clearEnv('COMPOSER_HTACCESS_PROTECT');

        self::assertEquals(0, $result);
    }

    public function testGetSourceOfValue(): void
    {
        Platform::clearEnv('COMPOSER_PROCESS_TIMEOUT');

        $config = new Config;

        self::assertSame(Config::SOURCE_DEFAULT, $config->getSourceOfValue('process-timeout'));

        $config->merge(
            ['config' => ['process-timeout' => 1]],
            'phpunit-test'
        );

        self::assertSame('phpunit-test', $config->getSourceOfValue('process-timeout'));
    }

    public function testGetSourceOfValueEnvVariables(): void
    {
        Platform::putEnv('COMPOSER_HTACCESS_PROTECT', '0');
        $config = new Config;
        $result = $config->getSourceOfValue('htaccess-protect');
        Platform::clearEnv('COMPOSER_HTACCESS_PROTECT');

        self::assertEquals('COMPOSER_HTACCESS_PROTECT', $result);
    }

    public function testAudit(): void
    {
        $config = new Config(true);
        $result = $config->get('audit');
        self::assertArrayHasKey('abandoned', $result);
        self::assertArrayHasKey('ignore', $result);
        self::assertSame(Auditor::ABANDONED_FAIL, $result['abandoned']);
        self::assertSame([], $result['ignore']);

        Platform::putEnv('COMPOSER_AUDIT_ABANDONED', Auditor::ABANDONED_IGNORE);
        $result = $config->get('audit');
        Platform::clearEnv('COMPOSER_AUDIT_ABANDONED');
        self::assertArrayHasKey('abandoned', $result);
        self::assertArrayHasKey('ignore', $result);
        self::assertSame(Auditor::ABANDONED_IGNORE, $result['abandoned']);
        self::assertSame([], $result['ignore']);

        $config->merge(['config' => ['audit' => ['ignore' => ['A', 'B']]]]);
        $config->merge(['config' => ['audit' => ['ignore' => ['A', 'C']]]]);
        $result = $config->get('audit');
        self::assertArrayHasKey('ignore', $result);
        self::assertSame(['A', 'B', 'A', 'C'], $result['ignore']);
    }

    public function testGetDefaultsToAnEmptyArray(): void
    {
        $config = new Config;
        $keys = [
            'bitbucket-oauth',
            'github-oauth',
            'gitlab-oauth',
            'gitlab-token',
            'forgejo-token',
            'http-basic',
            'bearer',
        ];
        foreach ($keys as $key) {
            $value = $config->get($key);
            self::assertIsArray($value);
            self::assertCount(0, $value);
        }
    }

    public function testMergesPluginConfig(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['allow-plugins' => ['some/plugin' => true]]]);
        self::assertEquals(['some/plugin' => true], $config->get('allow-plugins'));

        $config->merge(['config' => ['allow-plugins' => ['another/plugin' => true]]]);
        self::assertEquals(['some/plugin' => true, 'another/plugin' => true], $config->get('allow-plugins'));
    }

    public function testOverridesGlobalBooleanPluginsConfig(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['allow-plugins' => true]]);
        self::assertEquals(true, $config->get('allow-plugins'));

        $config->merge(['config' => ['allow-plugins' => ['another/plugin' => true]]]);
        self::assertEquals(['another/plugin' => true], $config->get('allow-plugins'));
    }

    public function testAllowsAllPluginsFromLocalBoolean(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['allow-plugins' => ['some/plugin' => true]]]);
        self::assertEquals(['some/plugin' => true], $config->get('allow-plugins'));

        $config->merge(['config' => ['allow-plugins' => true]]);
        self::assertEquals(true, $config->get('allow-plugins'));
    }
}
