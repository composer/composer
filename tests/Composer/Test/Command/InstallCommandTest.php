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

class InstallCommandTest extends TestCase
{
    /**
     * @dataProvider errorCaseProvider
     * @param array<mixed> $composerJson
     * @param array<mixed> $command
     */
    public function testInstallCommandErrors(
        array $composerJson,
        array $command,
        string $expected
    ): void {
        $this->initTempComposer($composerJson);

        $packages = [
            self::getPackage('vendor/package', '1.2.3'),
        ];
        $devPackages = [
            self::getPackage('vendor/devpackage', '2.3.4'),
        ];

        $this->createComposerLock($packages, $devPackages);
        $this->createInstalledJson($packages, $devPackages);

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'install'], $command));

        self::assertSame(trim($expected), trim($appTester->getDisplay(true)));
    }

    public function errorCaseProvider(): Generator
    {
        yield 'it writes an error when the dev flag is passed' => [
            [
                'repositories' => [],
            ],
            ['--dev' => true],
            <<<OUTPUT
<warning>You are using the deprecated option "--dev". It has no effect and will break in Composer 3.</warning>
Installing dependencies from lock file (including require-dev)
Verifying lock file contents can be installed on current platform.
Nothing to install, update or remove
Generating autoload files
OUTPUT
        ];

        yield 'it writes an error when no-suggest flag passed' => [
            [
                'repositories' => [],
            ],
            ['--no-suggest' => true],
            <<<OUTPUT
<warning>You are using the deprecated option "--no-suggest". It has no effect and will break in Composer 3.</warning>
Installing dependencies from lock file (including require-dev)
Verifying lock file contents can be installed on current platform.
Nothing to install, update or remove
Generating autoload files
OUTPUT
        ];

        yield 'it writes an error when packages passed' => [
            [
                'repositories' => [],
            ],
            ['packages' => ['vendor/package']],
            <<<OUTPUT
Invalid argument vendor/package. Use "composer require vendor/package" instead to add packages to your composer.json.
OUTPUT
        ];

        yield 'it writes an error when no-install flag is passed' => [
            [
                'repositories' => [],
            ],
            ['--no-install' => true],
            <<<OUTPUT
Invalid option "--no-install". Use "composer update --no-install" instead if you are trying to update the composer.lock file.
OUTPUT
        ];
    }

    public function testInstallFromEmptyVendor(): void
    {
        $this->initTempComposer([
            'require' => [
                'root/req' => '1.*',
            ],
            'require-dev' => [
                'root/another' => '1.*',
            ],
        ]);

        $rootReqPackage = self::getPackage('root/req');
        $anotherPackage = self::getPackage('root/another');
        // Set as a metapackage so that we can do the whole post-remove update & install process without Composer trying to download them (DownloadManager::getDownloaderForPackage).
        $rootReqPackage->setType('metapackage');
        $anotherPackage->setType('metapackage');

        $this->createComposerLock([$rootReqPackage], [$anotherPackage]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'install', '--no-progress' => true]);

        self::assertSame('Installing dependencies from lock file (including require-dev)
Verifying lock file contents can be installed on current platform.
Package operations: 2 installs, 0 updates, 0 removals
  - Installing root/another (1.0.0)
  - Installing root/req (1.0.0)
Generating autoload files', trim($appTester->getDisplay(true)));
    }

    public function testInstallFromEmptyVendorNoDev(): void
    {
        $this->initTempComposer([
            'require' => [
                'root/req' => '1.*',
            ],
            'require-dev' => [
                'root/another' => '1.*',
            ],
        ]);

        $rootReqPackage = self::getPackage('root/req');
        $anotherPackage = self::getPackage('root/another');
        // Set as a metapackage so that we can do the whole post-remove update & install process without Composer trying to download them (DownloadManager::getDownloaderForPackage).
        $rootReqPackage->setType('metapackage');
        $anotherPackage->setType('metapackage');

        $this->createComposerLock([$rootReqPackage], [$anotherPackage]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'install', '--no-progress' => true, '--no-dev' => true]);

        self::assertSame('Installing dependencies from lock file
Verifying lock file contents can be installed on current platform.
Package operations: 1 install, 0 updates, 0 removals
  - Installing root/req (1.0.0)
Generating autoload files', trim($appTester->getDisplay(true)));
    }

    public function testInstallNewPackagesWithExistingPartialVendor(): void
    {
        $this->initTempComposer([
            'require' => [
                'root/req' => '1.*',
                'root/another' => '1.*',
            ],
        ]);
        $rootReqPackage = self::getPackage('root/req');
        $anotherPackage = self::getPackage('root/another');
        // Set as a metapackage so that we can do the whole post-remove update & install process without Composer trying to download them (DownloadManager::getDownloaderForPackage).
        $rootReqPackage->setType('metapackage');
        $anotherPackage->setType('metapackage');

        $this->createComposerLock([$rootReqPackage, $anotherPackage], []);
        $this->createInstalledJson([$rootReqPackage], []);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'install', '--no-progress' => true]);

        self::assertSame('Installing dependencies from lock file (including require-dev)
Verifying lock file contents can be installed on current platform.
Package operations: 1 install, 0 updates, 0 removals
  - Installing root/another (1.0.0)
Generating autoload files', trim($appTester->getDisplay(true)));
    }
}
