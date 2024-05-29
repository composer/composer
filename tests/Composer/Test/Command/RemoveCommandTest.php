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

use Composer\Json\JsonFile;
use Composer\Package\Link;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Test\TestCase;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use UnexpectedValueException;

class RemoveCommandTest extends TestCase
{
    public function testExceptionRunningWithNoRemovePackages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not enough arguments (missing: "packages").');

        $appTester = $this->getApplicationTester();
        self::assertEquals(Command::FAILURE, $appTester->run(['command' => 'remove']));
    }

    public function testExceptionWhenRunningUnusedWithoutLockFile(): void
    {
        $this->initTempComposer();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('A valid composer.lock file is required to run this command with --unused');

        $appTester = $this->getApplicationTester();
        self::assertEquals(Command::FAILURE, $appTester->run(['command' => 'remove', '--unused' => true]));
    }

    public function testWarningWhenRemovingNonExistentPackage(): void
    {
        $this->initTempComposer();
        $this->createInstalledJson();

        $appTester = $this->getApplicationTester();
        self::assertEquals(Command::SUCCESS, $appTester->run(['command' => 'remove', 'packages' => ['vendor1/package1']]));
        self::assertStringStartsWith('<warning>vendor1/package1 is not required in your composer.json and has not been removed</warning>', trim($appTester->getDisplay(true)));
    }

    public function testWarningWhenRemovingPackageFromWrongType(): void
    {
        $this->initTempComposer([
            'require' => [
                'root/req' => '1.*',
            ],
        ]);

        $appTester = $this->getApplicationTester();
        self::assertEquals(Command::SUCCESS, $appTester->run(['command' => 'remove', 'packages' => ['root/req'], '--dev' => true, '--no-update' => true, '--no-interaction' => true]));
        self::assertSame('<warning>root/req could not be found in require-dev but it is present in require</warning>
./composer.json has been updated', trim($appTester->getDisplay(true)));
        self::assertEquals(['require' => ['root/req' => '1.*']], (new JsonFile('./composer.json'))->read());
    }

    public function testWarningWhenRemovingPackageWithDeprecatedDependenciesFlag(): void
    {
        $this->initTempComposer([
            'require' => [
                'root/req' => '1.*',
            ],
        ]);

        $appTester = $this->getApplicationTester();
        self::assertEquals(Command::SUCCESS, $appTester->run(['command' => 'remove', 'packages' => ['root/req'], '--update-with-dependencies' => true, '--no-update' => true, '--no-interaction' => true]));
        self::assertSame('<warning>You are using the deprecated option "update-with-dependencies". This is now default behaviour. The --no-update-with-dependencies option can be used to remove a package without its dependencies.</warning>
./composer.json has been updated', trim($appTester->getDisplay(true)));
        self::assertEmpty((new JsonFile('./composer.json'))->read());
    }

    public function testMessageOutputWhenNoUnusedPackagesToRemove(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'root/req', 'version' => '1.0.0', 'require' => ['nested/req' => '^1']],
                        ['name' => 'nested/req', 'version' => '1.1.0'],
                    ],
                ],
            ],
            'require' => [
                'root/req' => '1.*',
            ],
        ]);

        $requiredPackage = self::getPackage('root/req');
        $requiredPackage->setRequires([
            'nested/req' => new Link(
                'root/req',
                'nested/req',
                new MatchAllConstraint(),
                Link::TYPE_REQUIRE,
                '^1'
            )
        ]);
        $nestedPackage = self::getPackage('nested/req', '1.1.0');

        $this->createInstalledJson([$requiredPackage, $nestedPackage]);
        $this->createComposerLock([$requiredPackage, $nestedPackage]);

        $appTester = $this->getApplicationTester();
        self::assertEquals(Command::SUCCESS, $appTester->run(['command' => 'remove', '--unused' => true, '--no-audit' => true, '--no-interaction' => true]));
        self::assertSame('No unused packages to remove', trim($appTester->getDisplay(true)));
    }

    public function testRemoveUnusedPackage(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'root/req', 'version' => '1.0.0'],
                        ['name' => 'not/req', 'version' => '1.0.0'],
                    ],
                ]
            ],
            'require' => [
                'root/req' => '1.*',
            ],
        ]);

        $requiredPackage = self::getPackage('root/req');
        $extraneousPackage = self::getPackage('not/req');

        $this->createInstalledJson([$requiredPackage]);
        $this->createComposerLock([$requiredPackage, $extraneousPackage]);

        $appTester = $this->getApplicationTester();
        self::assertEquals(Command::SUCCESS, $appTester->run(['command' => 'remove', '--unused' => true, '--no-audit' => true, '--no-interaction' => true]));
        self::assertStringStartsWith('<warning>not/req is not required in your composer.json and has not been removed</warning>', $appTester->getDisplay(true));
        self::assertStringContainsString('Running composer update not/req', $appTester->getDisplay(true));
        self::assertStringContainsString('- Removing not/req (1.0.0)', $appTester->getDisplay(true));
    }

    public function testRemovePackageByName(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'root/req', 'version' => '1.0.0', 'type' => 'metapackage'],
                        ['name' => 'root/another', 'version' => '1.0.0', 'type' => 'metapackage']
                    ],
                ],
            ],
            'require' => [
                'root/req' => '1.*',
                'root/another' => '1.*',
            ],
        ]);
        $rootReqPackage = self::getPackage('root/req');
        $rootAnotherPackage = self::getPackage('root/another');
        // Set as a metapackage so that we can do the whole post-remove update & install process without Composer trying to download them (DownloadManager::getDownloaderForPackage).
        $rootReqPackage->setType('metapackage');
        $rootAnotherPackage->setType('metapackage');

        $this->createInstalledJson([$rootReqPackage, $rootAnotherPackage]);
        $this->createComposerLock([$rootReqPackage, $rootAnotherPackage]);

        $appTester = $this->getApplicationTester();
        self::assertEquals(Command::SUCCESS, $appTester->run(['command' => 'remove', 'packages' => ['root/req'], '--no-audit' => true, '--no-interaction' => true]));
        self::assertStringStartsWith('./composer.json has been updated', trim($appTester->getDisplay(true)));
        self::assertStringContainsString('Running composer update root/req', trim($appTester->getDisplay(true)));
        self::assertStringContainsString('Lock file operations: 0 installs, 0 updates, 1 removal', trim($appTester->getDisplay(true)));
        self::assertStringContainsString('- Removing root/req (1.0.0)', trim($appTester->getDisplay(true)));
        self::assertStringContainsString('Package operations: 0 installs, 0 updates, 1 removal', trim($appTester->getDisplay(true)));
        self::assertEquals(['root/another' => '1.*'], (new JsonFile('./composer.json'))->read()['require']);
        self::assertEquals([['name' => 'root/another', 'version' => '1.0.0', 'type' => 'metapackage']], (new JsonFile('./composer.lock'))->read()['packages']);
    }

    public function testRemovePackageByNameWithDryRun(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'root/req', 'version' => '1.0.0', 'type' => 'metapackage'],
                        ['name' => 'root/another', 'version' => '1.0.0', 'type' => 'metapackage']
                    ],
                ],
            ],
            'require' => [
                'root/req' => '1.*',
                'root/another' => '1.*',
            ],
        ]);
        $rootReqPackage = self::getPackage('root/req');
        $rootAnotherPackage = self::getPackage('root/another');
        // Set as a metapackage so that we can do the whole post-remove update & install process without Composer trying to download them (DownloadManager::getDownloaderForPackage).
        $rootReqPackage->setType('metapackage');
        $rootAnotherPackage->setType('metapackage');

        $this->createInstalledJson([$rootReqPackage, $rootAnotherPackage]);
        $this->createComposerLock([$rootReqPackage, $rootAnotherPackage]);

        $appTester = $this->getApplicationTester();
        self::assertEquals(Command::SUCCESS, $appTester->run(['command' => 'remove', 'packages' => ['root/req'], '--dry-run' => true, '--no-audit' => true, '--no-interaction' => true]));
        self::assertStringContainsString('./composer.json has been updated', trim($appTester->getDisplay(true)));
        self::assertStringContainsString('Running composer update root/req', trim($appTester->getDisplay(true)));
        self::assertStringContainsString('Lock file operations: 0 installs, 0 updates, 1 removal', trim($appTester->getDisplay(true)));
        self::assertStringContainsString('- Removing root/req (1.0.0)', trim($appTester->getDisplay(true)));
        self::assertStringContainsString('Package operations: 0 installs, 0 updates, 1 removal', trim($appTester->getDisplay(true)));
        self::assertEquals(['root/req' => '1.*', 'root/another' => '1.*'], (new JsonFile('./composer.json'))->read()['require']);
        self::assertEquals([['name' => 'root/another', 'version' => '1.0.0', 'type' => 'metapackage'], ['name' => 'root/req', 'version' => '1.0.0', 'type' => 'metapackage']], (new JsonFile('./composer.lock'))->read()['packages']);
    }

    public function testRemoveAllowedPluginPackageWithNoOtherAllowedPlugins(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'root/req', 'version' => '1.0.0', 'type' => 'metapackage'],
                        ['name' => 'root/another', 'version' => '1.0.0', 'type' => 'metapackage']
                    ],
                ],
            ],
            'require' => [
                'root/req' => '1.*',
                'root/another' => '1.*',
            ],
            'config' => [
                'allow-plugins' => [
                    'root/req' => true,
                ],
            ],
        ]);
        $rootReqPackage = self::getPackage('root/req');
        $rootAnotherPackage = self::getPackage('root/another');
        // Set as a metapackage so that we can do the whole post-remove update & install process without Composer trying to download them (DownloadManager::getDownloaderForPackage).
        $rootReqPackage->setType('metapackage');
        $rootAnotherPackage->setType('metapackage');

        $this->createInstalledJson([$rootReqPackage, $rootAnotherPackage]);
        $this->createComposerLock([$rootReqPackage, $rootAnotherPackage]);

        $appTester = $this->getApplicationTester();
        self::assertEquals(Command::SUCCESS, $appTester->run(['command' => 'remove', 'packages' => ['root/req'], '--no-audit' => true, '--no-interaction' => true]));
        self::assertEquals(['root/another' => '1.*'], (new JsonFile('./composer.json'))->read()['require']);
        self::assertEmpty((new JsonFile('./composer.json'))->read()['config']);
    }

    public function testRemoveAllowedPluginPackageWithOtherAllowedPlugins(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'root/req', 'version' => '1.0.0', 'type' => 'metapackage'],
                        ['name' => 'root/another', 'version' => '1.0.0', 'type' => 'metapackage']
                    ],
                ],
            ],
            'require' => [
                'root/req' => '1.*',
                'root/another' => '1.*',
            ],
            'config' => [
                'allow-plugins' => [
                    'root/another' => true,
                    'root/req' => true,
                ],
            ],
        ]);
        $rootReqPackage = self::getPackage('root/req');
        $rootAnotherPackage = self::getPackage('root/another');
        // Set as a metapackage so that we can do the whole post-remove update & install process without Composer trying to download them (DownloadManager::getDownloaderForPackage).
        $rootReqPackage->setType('metapackage');
        $rootAnotherPackage->setType('metapackage');

        $appTester = $this->getApplicationTester();
        self::assertEquals(Command::SUCCESS, $appTester->run(['command' => 'remove', 'packages' => ['root/req'], '--no-audit' => true, '--no-interaction' => true]));
        self::assertEquals(['root/another' => '1.*'], (new JsonFile('./composer.json'))->read()['require']);
        self::assertEquals(['allow-plugins' => ['root/another' => true]], (new JsonFile('./composer.json'))->read()['config']);
    }

    public function testRemovePackagesByVendor(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'root/req', 'version' => '1.0.0'],
                        ['name' => 'root/another', 'version' => '1.0.0'],
                        ['name' => 'another/req', 'version' => '1.0.0'],
                    ],
                ],
            ],
            'require' => [
                'root/req' => '1.*',
                'root/another' => '1.*',
                'another/req' => '1.*',
            ],
        ]);
        $rootReqPackage = self::getPackage('root/req');
        $rootAnotherPackage = self::getPackage('root/another');
        $anotherReqPackage = self::getPackage('another/req');

        $this->createInstalledJson([$rootReqPackage, $rootAnotherPackage, $anotherReqPackage]);
        $this->createComposerLock([$rootReqPackage, $rootAnotherPackage, $anotherReqPackage]);

        $appTester = $this->getApplicationTester();
        self::assertEquals(Command::SUCCESS, $appTester->run(['command' => 'remove', 'packages' => ['root/*'], '--no-install' => true, '--no-audit' => true, '--no-interaction' => true]));
        self::assertStringStartsWith('./composer.json has been updated', trim($appTester->getDisplay(true)));
        self::assertStringContainsString('Running composer update root/*', $appTester->getDisplay(true));
        self::assertStringContainsString('- Removing root/another (1.0.0)', $appTester->getDisplay(true));
        self::assertStringContainsString('- Removing root/req (1.0.0)', $appTester->getDisplay(true));
        self::assertStringContainsString('Writing lock file', $appTester->getDisplay(true));
        self::assertEquals(['another/req' => '1.*'], (new JsonFile('./composer.json'))->read()['require']);
        self::assertEquals([['name' => 'another/req', 'version' => '1.0.0', 'type' => 'library']], (new JsonFile('./composer.lock'))->read()['packages']);
    }

    public function testRemovePackagesByVendorWithDryRun(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'root/req', 'version' => '1.0.0'],
                        ['name' => 'root/another', 'version' => '1.0.0'],
                        ['name' => 'another/req', 'version' => '1.0.0'],
                    ],
                ],
            ],
            'require' => [
                'root/req' => '1.*',
                'root/another' => '1.*',
                'another/req' => '1.*',
            ],
        ]);
        $rootReqPackage = self::getPackage('root/req');
        $rootAnotherPackage = self::getPackage('root/another');
        $anotherReqPackage = self::getPackage('another/req');

        $this->createInstalledJson([$rootReqPackage, $rootAnotherPackage, $anotherReqPackage]);
        $this->createComposerLock([$rootReqPackage, $rootAnotherPackage, $anotherReqPackage]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'remove', 'packages' => ['root/*'], '--dry-run' => true, '--no-install' => true, '--no-audit' => true, '--no-interaction' => true]);
        self::assertEquals(Command::SUCCESS, $appTester->getStatusCode());
        self::assertSame("./composer.json has been updated
Running composer update root/*
Loading composer repositories with package information
Updating dependencies
Lock file operations: 0 installs, 0 updates, 2 removals
  - Removing root/another (1.0.0)
  - Removing root/req (1.0.0)", trim($appTester->getDisplay(true)));
        self::assertStringNotContainsString('Writing lock file', $appTester->getDisplay(true));
        self::assertEquals(['root/req' => '1.*', 'root/another' => '1.*', 'another/req' => '1.*'], (new JsonFile('./composer.json'))->read()['require']);
        self::assertEquals([['name' => 'another/req', 'version' => '1.0.0', 'type' => 'library'], ['name' => 'root/another', 'version' => '1.0.0', 'type' => 'library'], ['name' => 'root/req', 'version' => '1.0.0', 'type' => 'library']], (new JsonFile('./composer.lock'))->read()['packages']);
    }

    public function testWarningWhenRemovingPackagesByVendorFromWrongType(): void
    {
        $this->initTempComposer([
            'require' => [
                'root/req' => '1.*',
                'root/another' => '1.*',
                'another/req' => '1.*',
            ],
        ]);

        $appTester = $this->getApplicationTester();
        self::assertEquals(Command::SUCCESS, $appTester->run(['command' => 'remove', 'packages' => ['root/*'], '--dev' => true, '--no-interaction' => true, '--no-update' => true]));
        self::assertSame("<warning>root/req could not be found in require-dev but it is present in require</warning>
<warning>root/another could not be found in require-dev but it is present in require</warning>
./composer.json has been updated", trim($appTester->getDisplay(true)));
        self::assertEquals(['require' => ['root/req' => '1.*', 'root/another' => '1.*', 'another/req' => '1.*']], (new JsonFile('./composer.json'))->read());
    }

    public function testPackageStillPresentErrorWhenNoInstallFlagUsed(): void
    {
        $this->initTempComposer([
            'require' => [
                'root/req' => '1.*',
            ],
        ]);
        $rootReqPackage = self::getPackage('root/req');

        $this->createInstalledJson([$rootReqPackage]);
        $this->createComposerLock([$rootReqPackage]);

        $appTester = $this->getApplicationTester();
        self::assertEquals(Command::INVALID, $appTester->run(['command' => 'remove', 'packages' => ['root/req'], '--no-install' => true, '--no-audit' => true, '--no-interaction' => true]));
        self::assertStringContainsString('./composer.json has been updated', $appTester->getDisplay(true));
        self::assertStringContainsString('Lock file operations: 0 installs, 0 updates, 1 removal', $appTester->getDisplay(true));
        self::assertStringContainsString('- Removing root/req (1.0.0)', $appTester->getDisplay(true));
        self::assertStringContainsString('Writing lock file', $appTester->getDisplay(true));
        self::assertStringContainsString('Removal failed, root/req is still present, it may be required by another package. See `composer why root/req`', $appTester->getDisplay(true));
        self::assertEmpty((new JsonFile('./composer.json'))->read());
        self::assertEmpty((new JsonFile('./composer.lock'))->read()['packages']);
        self::assertEquals([['name' => 'root/req', 'version' => '1.0.0', 'version_normalized' => '1.0.0.0', 'type' => 'library', 'install-path' => '../root/req']], (new JsonFile('./vendor/composer/installed.json'))->read()['packages']);
    }

    /**
     * @dataProvider provideInheritedDependenciesUpdateFlag
     */
    public function testUpdateInheritedDependenciesFlagIsPassedToPostRemoveInstaller(string $installFlagName, string $expectedComposerUpdateCommand): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'root/req', 'version' => '1.0.0', 'type' => 'metapackage'],
                    ],
                ],
            ],
            'require' => [
                'root/req' => '1.*',
            ],
        ]);
        $rootReqPackage = self::getPackage('root/req');
        $rootReqPackage->setType('metapackage');

        $this->createInstalledJson([$rootReqPackage]);
        $this->createComposerLock([$rootReqPackage]);

        $appTester = $this->getApplicationTester();
        self::assertEquals(Command::SUCCESS, $appTester->run(['command' => 'remove', 'packages' => ['root/req'], $installFlagName => true, '--no-audit' => true, '--no-interaction' => true]));
        self::assertStringContainsString('./composer.json has been updated', $appTester->getDisplay(true));
        self::assertStringContainsString($expectedComposerUpdateCommand, $appTester->getDisplay(true));
        self::assertStringContainsString('Package operations: 0 installs, 0 updates, 1 removal', $appTester->getDisplay(true));
        self::assertStringContainsString('- Removing root/req (1.0.0)', $appTester->getDisplay(true));
        self::assertStringContainsString('Writing lock file', $appTester->getDisplay(true));
        self::assertStringContainsString('Lock file operations: 0 installs, 0 updates, 1 removal', $appTester->getDisplay(true));
        self::assertEmpty((new JsonFile('./composer.lock'))->read()['packages']);
    }

    public static function provideInheritedDependenciesUpdateFlag(): \Generator
    {
        yield 'update with all dependencies' => [
            '--update-with-all-dependencies',
            'Running composer update root/req --with-all-dependencies',
        ];

        yield 'with all dependencies' => [
            '--with-all-dependencies',
            'Running composer update root/req --with-all-dependencies',
        ];

        yield 'no update with dependencies' => [
            '--no-update-with-dependencies',
            'Running composer update root/req --with-dependencies',
        ];
    }
}
