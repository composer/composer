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

        self::assertSame($expected, json_decode((string) file_get_contents('composer.json'), true));
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
        yield 'set audit.ignore-unreachable' => [
            [],
            ['setting-key' => 'audit.ignore-unreachable', 'setting-value' => ['true']],
            ['config' => ['audit' => ['ignore-unreachable' => true]]],
        ];
        yield 'set audit.block-insecure' => [
            [],
            ['setting-key' => 'audit.block-insecure', 'setting-value' => ['false']],
            ['config' => ['audit' => ['block-insecure' => false]]],
        ];
        yield 'set audit.block-abandoned' => [
            [],
            ['setting-key' => 'audit.block-abandoned', 'setting-value' => ['true']],
            ['config' => ['audit' => ['block-abandoned' => true]]],
        ];
        yield 'unset audit.ignore-unreachable' => [
            ['config' => ['audit' => ['ignore-unreachable' => true]]],
            ['setting-key' => 'audit.ignore-unreachable', '--unset' => true],
            ['config' => ['audit' => []]],
        ];
        yield 'set audit.ignore-severity' => [
            [],
            ['setting-key' => 'audit.ignore-severity', 'setting-value' => ['low', 'medium']],
            ['config' => ['audit' => ['ignore-severity' => ['low', 'medium']]]],
        ];
        yield 'set audit.ignore as array' => [
            [],
            ['setting-key' => 'audit.ignore', 'setting-value' => ['["CVE-2024-1234","GHSA-xxxx-yyyy"]'], '--json' => true],
            ['config' => ['audit' => ['ignore' => ['CVE-2024-1234', 'GHSA-xxxx-yyyy']]]],
        ];
        yield 'set audit.ignore as object' => [
            [],
            ['setting-key' => 'audit.ignore', 'setting-value' => ['{"CVE-2024-1234":"False positive","GHSA-xxxx-yyyy":"Not applicable"}'], '--json' => true],
            ['config' => ['audit' => ['ignore' => ['CVE-2024-1234' => 'False positive', 'GHSA-xxxx-yyyy' => 'Not applicable']]]],
        ];
        yield 'merge audit.ignore array' => [
            ['config' => ['audit' => ['ignore' => ['CVE-2024-1234']]]],
            ['setting-key' => 'audit.ignore', 'setting-value' => ['["CVE-2024-5678"]'], '--json' => true, '--merge' => true],
            ['config' => ['audit' => ['ignore' => ['CVE-2024-1234', 'CVE-2024-5678']]]],
        ];
        yield 'merge audit.ignore object' => [
            ['config' => ['audit' => ['ignore' => ['CVE-2024-1234' => 'Old reason']]]],
            ['setting-key' => 'audit.ignore', 'setting-value' => ['{"CVE-2024-5678":"New advisory"}'], '--json' => true, '--merge' => true],
            ['config' => ['audit' => ['ignore' => ['CVE-2024-5678' => 'New advisory', 'CVE-2024-1234' => 'Old reason']]]],
        ];
        yield 'overwrite audit.ignore key with merge' => [
            ['config' => ['audit' => ['ignore' => ['CVE-2024-1234' => 'Old reason']]]],
            ['setting-key' => 'audit.ignore', 'setting-value' => ['{"CVE-2024-1234":"New reason"}'], '--json' => true, '--merge' => true],
            ['config' => ['audit' => ['ignore' => ['CVE-2024-1234' => 'New reason']]]],
        ];
        yield 'set audit.ignore-abandoned as array' => [
            [],
            ['setting-key' => 'audit.ignore-abandoned', 'setting-value' => ['["vendor/package1","vendor/package2"]'], '--json' => true],
            ['config' => ['audit' => ['ignore-abandoned' => ['vendor/package1', 'vendor/package2']]]],
        ];
        yield 'set audit.ignore-abandoned as object' => [
            [],
            ['setting-key' => 'audit.ignore-abandoned', 'setting-value' => ['{"vendor/package1":"Still maintained","vendor/package2":"Fork available"}'], '--json' => true],
            ['config' => ['audit' => ['ignore-abandoned' => ['vendor/package1' => 'Still maintained', 'vendor/package2' => 'Fork available']]]],
        ];
        yield 'merge audit.ignore-abandoned array' => [
            ['config' => ['audit' => ['ignore-abandoned' => ['vendor/package1']]]],
            ['setting-key' => 'audit.ignore-abandoned', 'setting-value' => ['["vendor/package2"]'], '--json' => true, '--merge' => true],
            ['config' => ['audit' => ['ignore-abandoned' => ['vendor/package1', 'vendor/package2']]]],
        ];
        yield 'merge audit.ignore-abandoned object' => [
            ['config' => ['audit' => ['ignore-abandoned' => ['vendor/package1' => 'Old reason']]]],
            ['setting-key' => 'audit.ignore-abandoned', 'setting-value' => ['{"vendor/package2":"New reason"}'], '--json' => true, '--merge' => true],
            ['config' => ['audit' => ['ignore-abandoned' => ['vendor/package2' => 'New reason', 'vendor/package1' => 'Old reason']]]],
        ];
        yield 'unset audit.ignore' => [
            ['config' => ['audit' => ['ignore' => ['CVE-2024-1234']]]],
            ['setting-key' => 'audit.ignore', '--unset' => true],
            ['config' => ['audit' => []]],
        ];
        yield 'unset audit.ignore-abandoned' => [
            ['config' => ['audit' => ['ignore-abandoned' => ['vendor/package1']]]],
            ['setting-key' => 'audit.ignore-abandoned', '--unset' => true],
            ['config' => ['audit' => []]],
        ];
        yield 'set minimum-release-age.minimum-age with duration string' => [
            [],
            ['setting-key' => 'minimum-release-age.minimum-age', 'setting-value' => ['7 days']],
            ['config' => ['minimum-release-age' => ['minimum-age' => '7 days']]],
        ];
        yield 'set minimum-release-age.minimum-age with integer' => [
            [],
            ['setting-key' => 'minimum-release-age.minimum-age', 'setting-value' => ['43200']],
            ['config' => ['minimum-release-age' => ['minimum-age' => 43200]]],
        ];
        yield 'set minimum-release-age.minimum-age to null' => [
            ['config' => ['minimum-release-age' => ['minimum-age' => '7 days']]],
            ['setting-key' => 'minimum-release-age.minimum-age', 'setting-value' => ['null']],
            ['config' => ['minimum-release-age' => ['minimum-age' => null]]],
        ];
        yield 'unset minimum-release-age.minimum-age' => [
            ['config' => ['minimum-release-age' => ['minimum-age' => '7 days']]],
            ['setting-key' => 'minimum-release-age.minimum-age', '--unset' => true],
            ['config' => ['minimum-release-age' => []]],
        ];
        yield 'set minimum-release-age.exceptions' => [
            [],
            ['setting-key' => 'minimum-release-age.exceptions', 'setting-value' => ['[{"package":"vendor/*","reason":"trusted"}]'], '--json' => true],
            ['config' => ['minimum-release-age' => ['exceptions' => [['package' => 'vendor/*', 'reason' => 'trusted']]]]],
        ];
        yield 'merge minimum-release-age.exceptions' => [
            ['config' => ['minimum-release-age' => ['exceptions' => [['package' => 'vendor/*', 'reason' => 'trusted']]]]],
            ['setting-key' => 'minimum-release-age.exceptions', 'setting-value' => ['[{"package":"other/*"}]'], '--json' => true, '--merge' => true],
            ['config' => ['minimum-release-age' => ['exceptions' => [['package' => 'vendor/*', 'reason' => 'trusted'], ['package' => 'other/*']]]]],
        ];
        yield 'unset minimum-release-age.exceptions' => [
            ['config' => ['minimum-release-age' => ['exceptions' => [['package' => 'vendor/*']]]]],
            ['setting-key' => 'minimum-release-age.exceptions', '--unset' => true],
            ['config' => ['minimum-release-age' => []]],
        ];
        yield 'unset minimum-release-age' => [
            ['config' => ['minimum-release-age' => ['minimum-age' => '7 days', 'exceptions' => []]]],
            ['setting-key' => 'minimum-release-age', '--unset' => true],
            ['config' => []],
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

        self::assertSame($expected, trim($appTester->getDisplay(true)));
        self::assertSame($composerJson, json_decode((string) file_get_contents('composer.json'), true), 'The composer.json should not be modified by config reads');
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

    public function testConfigThrowsForInvalidSeverity(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('valid severities include: low, medium, high, critical');

        $this->initTempComposer([]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'config', 'setting-key' => 'audit.ignore-severity', 'setting-value' => ['low', 'invalid']]);
    }

    public function testConfigThrowsWhenMergingArrayWithObject(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot merge array and object');

        $this->initTempComposer(['config' => ['audit' => ['ignore' => ['CVE-2024-1234']]]]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'config', 'setting-key' => 'audit.ignore', 'setting-value' => ['{"CVE-2024-5678":"reason"}'], '--json' => true, '--merge' => true]);
    }
}
