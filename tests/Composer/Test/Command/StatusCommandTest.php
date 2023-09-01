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
use Generator;

class StatusCommandTest extends TestCase
{
    public function testNoLocalChanges(): void
    {
        $this->initTempComposer(['require' => ['root/req' => '1.*']]);

        $package = self::getPackage('root/req');
        $package->setType('metapackage');

        $this->createComposerLock([$package], []);
        $this->createInstalledJson([$package], []);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'status']);

        $this->assertSame('No local changes', trim($appTester->getDisplay(true)));
    }

    /**
     * @dataProvider locallyModifiedPackagesUseCaseProvider
     * @param array<mixed> $composerJson
     * @param array<mixed> $commandFlags
     * @param array<mixed> $packageData
     */
    public function testLocallyModifiedPackages(
        array $composerJson,
        array $commandFlags,
        array $packageData
    ): void {
        $this->initTempComposer($composerJson);

        $package = self::getPackage($packageData['name'], $packageData['version']);
        $package->setInstallationSource($packageData['installation_source']);

        if ($packageData['installation_source'] === 'source') {
            $package->setSourceType($packageData['type']);
            $package->setSourceUrl($packageData['url']);
            $package->setSourceReference($packageData['reference']);
        }

        if ($packageData['installation_source'] === 'dist') {
            $package->setDistType($packageData['type']);
            $package->setDistUrl($packageData['url']);
            $package->setDistReference($packageData['reference']);
        }

        $this->createComposerLock([$package], []);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'install']);

        file_put_contents(getcwd() . '/vendor/' . $packageData['name'] . '/composer.json', '{}');

        $appTester->run(array_merge(['command' => 'status'], $commandFlags));

        $expected = 'You have changes in the following dependencies:';
        $actual = trim($appTester->getDisplay(true));

        $this->assertStringContainsString($expected, $actual);
        $this->assertStringContainsString($packageData['name'], $actual);
    }

    public static function locallyModifiedPackagesUseCaseProvider(): Generator
    {
        yield 'locally modified package from source' => [
            ['require' => ['composer/class-map-generator' => '^1.0']],
            [],
            [
                'name' => 'composer/class-map-generator' ,
                'version' => '1.1',
                'installation_source' => 'source',
                'type' => 'git',
                'url' => 'https://github.com/composer/class-map-generator.git',
                'reference' => '953cc4ea32e0c31f2185549c7d216d7921f03da9'
            ]
        ];

        yield 'locally modified package from dist' => [
            ['require' => ['smarty/smarty' => '^3.1']],
            ['--verbose' => true],
            [
                'name' => 'smarty/smarty',
                'version' => '3.1.7',
                'installation_source' => 'dist',
                'type' => 'zip',
                'url' => 'https://www.smarty.net/files/Smarty-3.1.7.zip',
                'reference' => null
            ]
        ];
    }
}
