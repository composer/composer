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
        yield 'unset policy' => [
            ['config' => ['policy' => ['advisories' => false]]],
            ['setting-key' => 'policy', '--unset' => true],
            ['config' => []],
        ];
        yield 'set policy.advisories.block false via 0' => [
            [],
            ['setting-key' => 'policy.advisories.block', 'setting-value' => ['0']],
            ['config' => ['policy' => ['advisories' => ['block' => false]]]],
        ];
        yield 'set policy.advisories.block true via 1' => [
            [],
            ['setting-key' => 'policy.advisories.block', 'setting-value' => ['1']],
            ['config' => ['policy' => ['advisories' => ['block' => true]]]],
        ];
        yield 'set policy.advisories.audit' => [
            [],
            ['setting-key' => 'policy.advisories.audit', 'setting-value' => ['report']],
            ['config' => ['policy' => ['advisories' => ['audit' => 'report']]]],
        ];
        yield 'set policy.malware.block false' => [
            [],
            ['setting-key' => 'policy.malware.block', 'setting-value' => ['false']],
            ['config' => ['policy' => ['malware' => ['block' => false]]]],
        ];
        yield 'set policy.malware.block-scope' => [
            [],
            ['setting-key' => 'policy.malware.block-scope', 'setting-value' => ['install']],
            ['config' => ['policy' => ['malware' => ['block-scope' => 'install']]]],
        ];
        yield 'set policy.malware.audit' => [
            [],
            ['setting-key' => 'policy.malware.audit', 'setting-value' => ['ignore']],
            ['config' => ['policy' => ['malware' => ['audit' => 'ignore']]]],
        ];
        yield 'set policy.abandoned.block true' => [
            [],
            ['setting-key' => 'policy.abandoned.block', 'setting-value' => ['true']],
            ['config' => ['policy' => ['abandoned' => ['block' => true]]]],
        ];
        yield 'set policy.abandoned.audit' => [
            [],
            ['setting-key' => 'policy.abandoned.audit', 'setting-value' => ['fail']],
            ['config' => ['policy' => ['abandoned' => ['audit' => 'fail']]]],
        ];
        yield 'set policy.ignore-unreachable bool true' => [
            [],
            ['setting-key' => 'policy.ignore-unreachable', 'setting-value' => ['true']],
            ['config' => ['policy' => ['ignore-unreachable' => true]]],
        ];
        yield 'set policy.ignore-unreachable bool false' => [
            [],
            ['setting-key' => 'policy.ignore-unreachable', 'setting-value' => ['false']],
            ['config' => ['policy' => ['ignore-unreachable' => false]]],
        ];
        yield 'set policy.advisories.block alongside existing audit setting' => [
            ['config' => ['policy' => ['advisories' => ['audit' => 'report']]]],
            ['setting-key' => 'policy.advisories.block', 'setting-value' => ['false']],
            ['config' => ['policy' => ['advisories' => ['audit' => 'report', 'block' => false]]]],
        ];
        yield 'set policy.malware.block alongside existing advisories' => [
            ['config' => ['policy' => ['advisories' => ['block' => false]]]],
            ['setting-key' => 'policy.malware.block', 'setting-value' => ['true']],
            ['config' => ['policy' => ['advisories' => ['block' => false], 'malware' => ['block' => true]]]],
        ];
        yield 'set custom policy list block' => [
            [],
            ['setting-key' => 'policy.my-list.block', 'setting-value' => ['true']],
            ['config' => ['policy' => ['my-list' => ['block' => true]]]],
        ];
        yield 'set custom policy list audit' => [
            [],
            ['setting-key' => 'policy.my-list.audit', 'setting-value' => ['report']],
            ['config' => ['policy' => ['my-list' => ['audit' => 'report']]]],
        ];
        yield 'unset policy.advisories.block leaves siblings' => [
            ['config' => ['policy' => ['advisories' => ['block' => false, 'audit' => 'fail']]]],
            ['setting-key' => 'policy.advisories.block', '--unset' => true],
            ['config' => ['policy' => ['advisories' => ['audit' => 'fail']]]],
        ];
        yield 'unset policy.ignore-unreachable leaves siblings' => [
            ['config' => ['policy' => ['ignore-unreachable' => true, 'advisories' => ['block' => true]]]],
            ['setting-key' => 'policy.ignore-unreachable', '--unset' => true],
            ['config' => ['policy' => ['advisories' => ['block' => true]]]],
        ];
        yield 'unset last sub-key cascades removal up through empty ancestors' => [
            ['config' => ['policy' => ['advisories' => ['block' => false]]]],
            ['setting-key' => 'policy.advisories.block', '--unset' => true],
            ['config' => []],
        ];
        yield 'unset last sub-key of list keeps sibling lists' => [
            ['config' => ['policy' => ['advisories' => ['block' => false], 'malware' => ['block' => true]]]],
            ['setting-key' => 'policy.advisories.block', '--unset' => true],
            ['config' => ['policy' => ['malware' => ['block' => true]]]],
        ];
        yield 'unset only policy.ignore-unreachable cascades through policy' => [
            ['config' => ['policy' => ['ignore-unreachable' => true]]],
            ['setting-key' => 'policy.ignore-unreachable', '--unset' => true],
            ['config' => []],
        ];
        yield 'set policy.advisories.ignore as array' => [
            [],
            ['setting-key' => 'policy.advisories.ignore', 'setting-value' => ['["CVE-2024-1234"]'], '--json' => true],
            ['config' => ['policy' => ['advisories' => ['ignore' => ['CVE-2024-1234']]]]],
        ];
        yield 'set policy.advisories.ignore as object' => [
            [],
            ['setting-key' => 'policy.advisories.ignore', 'setting-value' => ['{"CVE-2024-1234":"False positive"}'], '--json' => true],
            ['config' => ['policy' => ['advisories' => ['ignore' => ['CVE-2024-1234' => 'False positive']]]]],
        ];
        yield 'merge policy.advisories.ignore array' => [
            ['config' => ['policy' => ['advisories' => ['ignore' => ['CVE-2024-1234']]]]],
            ['setting-key' => 'policy.advisories.ignore', 'setting-value' => ['["CVE-2024-5678"]'], '--json' => true, '--merge' => true],
            ['config' => ['policy' => ['advisories' => ['ignore' => ['CVE-2024-1234', 'CVE-2024-5678']]]]],
        ];
        yield 'merge policy.advisories.ignore object' => [
            ['config' => ['policy' => ['advisories' => ['ignore' => ['CVE-2024-1234' => 'Old reason']]]]],
            ['setting-key' => 'policy.advisories.ignore', 'setting-value' => ['{"CVE-2024-5678":"New advisory"}'], '--json' => true, '--merge' => true],
            ['config' => ['policy' => ['advisories' => ['ignore' => ['CVE-2024-5678' => 'New advisory', 'CVE-2024-1234' => 'Old reason']]]]],
        ];
        yield 'set policy.advisories.ignore-severity' => [
            [],
            ['setting-key' => 'policy.advisories.ignore-severity', 'setting-value' => ['low', 'medium']],
            ['config' => ['policy' => ['advisories' => ['ignore-severity' => ['low', 'medium']]]]],
        ];
        yield 'set policy.advisories.ignore-id as array' => [
            [],
            ['setting-key' => 'policy.advisories.ignore-id', 'setting-value' => ['["CVE-2024-1234","GHSA-xxxx-yyyy"]'], '--json' => true],
            ['config' => ['policy' => ['advisories' => ['ignore-id' => ['CVE-2024-1234', 'GHSA-xxxx-yyyy']]]]],
        ];
        yield 'set policy.malware.ignore as array' => [
            [],
            ['setting-key' => 'policy.malware.ignore', 'setting-value' => ['["vendor/pkg"]'], '--json' => true],
            ['config' => ['policy' => ['malware' => ['ignore' => ['vendor/pkg']]]]],
        ];
        yield 'set policy.malware.ignore-source' => [
            [],
            ['setting-key' => 'policy.malware.ignore-source', 'setting-value' => ['source-a', 'source-b']],
            ['config' => ['policy' => ['malware' => ['ignore-source' => ['source-a', 'source-b']]]]],
        ];
        yield 'set policy.abandoned.ignore as array' => [
            [],
            ['setting-key' => 'policy.abandoned.ignore', 'setting-value' => ['["vendor/pkg"]'], '--json' => true],
            ['config' => ['policy' => ['abandoned' => ['ignore' => ['vendor/pkg']]]]],
        ];
        yield 'set policy.ignore-unreachable as array via json' => [
            [],
            ['setting-key' => 'policy.ignore-unreachable', 'setting-value' => ['["install","update"]'], '--json' => true],
            ['config' => ['policy' => ['ignore-unreachable' => ['install', 'update']]]],
        ];
        yield 'set custom policy list ignore' => [
            [],
            ['setting-key' => 'policy.my-list.ignore', 'setting-value' => ['["vendor/pkg"]'], '--json' => true],
            ['config' => ['policy' => ['my-list' => ['ignore' => ['vendor/pkg']]]]],
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

    public function testConfigThrowsForInvalidPolicyAuditMode(): void
    {
        $this->expectException(RuntimeException::class);

        $this->initTempComposer([]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'config', 'setting-key' => 'policy.advisories.audit', 'setting-value' => ['bogus']]);
    }

    public function testConfigThrowsForInvalidPolicyBlockScope(): void
    {
        $this->expectException(RuntimeException::class);

        $this->initTempComposer([]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'config', 'setting-key' => 'policy.malware.block-scope', 'setting-value' => ['bogus']]);
    }

    public function testConfigThrowsForInvalidPolicyIgnoreSeverity(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('valid severities include: low, medium, high, critical');

        $this->initTempComposer([]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'config', 'setting-key' => 'policy.advisories.ignore-severity', 'setting-value' => ['low', 'bogus']]);
    }

    public function testConfigThrowsForInvalidPolicyIgnoreUnreachableValue(): void
    {
        $this->expectException(RuntimeException::class);

        $this->initTempComposer([]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'config', 'setting-key' => 'policy.ignore-unreachable', 'setting-value' => ['["bogus"]'], '--json' => true]);
    }
}
