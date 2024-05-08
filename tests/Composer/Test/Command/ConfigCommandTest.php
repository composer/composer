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

namespace Composer\Test\Command;

use Composer\Test\TestCase;
use RuntimeException;

class ConfigCommandTest extends TestCase
{
    /**
     * @dataProvider provideConfigUpdates
     * @param array<mixed> $before
     * @param array<mixed> $command
     * @param array<mixed> $expected
     */
    public function testConfigUpdates(array $before, array $command, array $expected): void
    {
        $this->initTempComposer($before);

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'config'], $command));

        $appTester->assertCommandIsSuccessful($appTester->getDisplay());

        $this->assertSame($expected, json_decode((string) file_get_contents('composer.json'), true));
    }

    public static function provideConfigUpdates(): \Generator
    {
        yield 'set scripts' => [
            [],
            ['setting-key' => 'scripts.test', 'setting-value' => ['foo bar']],
            ['scripts' => ['test' => 'foo bar']],
        ];
        yield 'unset scripts' => [
            ['scripts' => ['test' => 'foo bar', 'lala' => 'baz']],
            ['setting-key' => 'scripts.lala', '--unset' => true],
            ['scripts' => ['test' => 'foo bar']],
        ];
        yield 'set single config with bool normalizer' => [
            [],
            ['setting-key' => 'use-github-api', 'setting-value' => ['1']],
            ['config' => ['use-github-api' => true]],
        ];
        yield 'set multi config' => [
            [],
            ['setting-key' => 'github-protocols', 'setting-value' => ['https', 'git']],
            ['config' => ['github-protocols' => ['https', 'git']]],
        ];
        yield 'set version' => [
            [],
            ['setting-key' => 'version', 'setting-value' => ['1.0.0']],
            ['version' => '1.0.0'],
        ];
        yield 'unset version' => [
            ['version' => '1.0.0'],
            ['setting-key' => 'version', '--unset' => true],
            [],
        ];
        yield 'unset arbitrary property' => [
            ['random-prop' => '1.0.0'],
            ['setting-key' => 'random-prop', '--unset' => true],
            [],
        ];
        yield 'set preferred-install' => [
            [],
            ['setting-key' => 'preferred-install.foo/*', 'setting-value' => ['source']],
            ['config' => ['preferred-install' => ['foo/*' => 'source']]],
        ];
        yield 'unset preferred-install' => [
            ['config' => ['preferred-install' => ['foo/*' => 'source']]],
            ['setting-key' => 'preferred-install.foo/*', '--unset' => true],
            ['config' => ['preferred-install' => []]],
        ];
        yield 'unset platform' => [
            ['config' => ['platform' => ['php' => '7.2.5'], 'platform-check' => false]],
            ['setting-key' => 'platform.php', '--unset' => true],
            ['config' => ['platform' => [], 'platform-check' => false]],
        ];
        yield 'set extra with merge' => [
            [],
            ['setting-key' => 'extra.patches.foo/bar', 'setting-value' => ['{"123":"value"}'], '--json' => true, '--merge' => true],
            ['extra' => ['patches' => ['foo/bar' => [123 => 'value']]]],
        ];
        yield 'combine extra with merge' => [
            ['extra' => ['patches' => ['foo/bar' => [5 => 'oldvalue']]]],
            ['setting-key' => 'extra.patches.foo/bar', 'setting-value' => ['{"123":"value"}'], '--json' => true, '--merge' => true],
            ['extra' => ['patches' => ['foo/bar' => [123 => 'value', 5 => 'oldvalue']]]],
        ];
        yield 'combine extra with list' => [
            ['extra' => ['patches' => ['foo/bar' => ['oldvalue']]]],
            ['setting-key' => 'extra.patches.foo/bar', 'setting-value' => ['{"123":"value"}'], '--json' => true, '--merge' => true],
            ['extra' => ['patches' => ['foo/bar' => [123 => 'value', 0 => 'oldvalue']]]],
        ];
        yield 'overwrite extra with merge' => [
            ['extra' => ['patches' => ['foo/bar' => [123 => 'oldvalue']]]],
            ['setting-key' => 'extra.patches.foo/bar', 'setting-value' => ['{"123":"value"}'], '--json' => true, '--merge' => true],
            ['extra' => ['patches' => ['foo/bar' => [123 => 'value']]]],
        ];
        yield 'unset autoload' => [
            ['autoload' => ['psr-4' => ['test'], 'classmap' => ['test']]],
            ['setting-key' => 'autoload.psr-4', '--unset' => true],
            ['autoload' => ['classmap' => ['test']]],
        ];
        yield 'unset autoload-dev' => [
            ['autoload-dev' => ['psr-4' => ['test'], 'classmap' => ['test']]],
            ['setting-key' => 'autoload-dev.psr-4', '--unset' => true],
            ['autoload-dev' => ['classmap' => ['test']]],
        ];
    }

    /**
     * @dataProvider provideConfigReads
     * @param array<mixed> $composerJson
     * @param array<mixed> $command
     */
    public function testConfigReads(array $composerJson, array $command, string $expected): void
    {
        $this->initTempComposer($composerJson);

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'config'], $command));

        $appTester->assertCommandIsSuccessful();

        $this->assertSame($expected, trim($appTester->getDisplay(true)));
        $this->assertSame($composerJson, json_decode((string) file_get_contents('composer.json'), true), 'The composer.json should not be modified by config reads');
    }

    public static function provideConfigReads(): \Generator
    {
        yield 'read description' => [
            ['description' => 'foo bar'],
            ['setting-key' => 'description'],
            'foo bar',
        ];
        yield 'read vendor-dir with source' => [
            ['config' => ['vendor-dir' => 'lala']],
            ['setting-key' => 'vendor-dir', '--source' => true],
            'lala (./composer.json)',
        ];
        yield 'read default vendor-dir' => [
            [],
            ['setting-key' => 'vendor-dir'],
            'vendor',
        ];
        yield 'read repos by named key' => [
            ['repositories' => ['foo' => ['type' => 'vcs', 'url' => 'https://example.org'], 'packagist.org' => ['type' => 'composer', 'url' => 'https://repo.packagist.org']]],
            ['setting-key' => 'repositories.foo'],
            '{"type":"vcs","url":"https://example.org"}',
        ];
        yield 'read repos by numeric index' => [
            ['repositories' => [['type' => 'vcs', 'url' => 'https://example.org'], 'packagist.org' => ['type' => 'composer', 'url' => 'https://repo.packagist.org']]],
            ['setting-key' => 'repos.0'],
            '{"type":"vcs","url":"https://example.org"}',
        ];
        yield 'read all repos includes the default packagist' => [
            ['repositories' => ['foo' => ['type' => 'vcs', 'url' => 'https://example.org'], 'packagist.org' => ['type' => 'composer', 'url' => 'https://repo.packagist.org']]],
            ['setting-key' => 'repos'],
            '{"foo":{"type":"vcs","url":"https://example.org"},"packagist.org":{"type":"composer","url":"https://repo.packagist.org"}}',
        ];
        yield 'read all repos does not include the disabled packagist' => [
            ['repositories' => ['foo' => ['type' => 'vcs', 'url' => 'https://example.org'], 'packagist.org' => false]],
            ['setting-key' => 'repos'],
            '{"foo":{"type":"vcs","url":"https://example.org"}}',
        ];
    }

    public function testConfigThrowsForInvalidArgCombination(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--file and --global can not be combined');

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'config', '--file' => 'alt.composer.json', '--global' => true]);
    }
}
