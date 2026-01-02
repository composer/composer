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
use Composer\Util\Platform;

class GlobalCommandTest extends TestCase
{
    public function tearDown(): void
    {
        parent::tearDown();
        Platform::clearEnv('COMPOSER_HOME');
        Platform::clearEnv('COMPOSER');
    }

    public function testGlobal(): void
    {
        $script = '@php -r "echo \'COMPOSER SCRIPT OUTPUT: \'.getenv(\'COMPOSER\') . PHP_EOL;"';
        $fakeComposer = 'TMP_COMPOSER.JSON';
        $composerHome = $this->initTempComposer(
            [
                "scripts" => [
                    "test-script" => $script,
                ],
            ]
        );

        Platform::putEnv('COMPOSER_HOME', $composerHome);
        Platform::putEnv('COMPOSER', $fakeComposer);

        $dir = self::getUniqueTmpDirectory();
        chdir($dir);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'global',
            'command-name' => 'test-script',
            '--no-interaction' => true,
        ]);

        $display = $appTester->getDisplay(true);

        self::assertSame(
            'Changed current directory to ' . $composerHome . "\n".
            "COMPOSER SCRIPT OUTPUT: \n",
            $display
        );
    }

    public function testCannotCreateHome(): void
    {
        $dir = self::getUniqueTmpDirectory();
        $filename = $dir . '/file';
        file_put_contents($filename, '');

        Platform::putEnv('COMPOSER_HOME', $filename);

        self::expectException(\RuntimeException::class);
        $this->expectExceptionMessage($filename . ' exists and is not a directory.');

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'global',
            'command-name' => 'test-script',
            '--no-interaction' => true,
        ]);
    }

    public function testGlobalShow(): void
    {
        $composerHome = $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor/global-tool', 'version' => '1.0.0'],
                    ],
                ],
            ],
            'require' => [
                'vendor/global-tool' => '1.0.0',
            ],
        ]);

        $pkg = self::getPackage('vendor/global-tool', '1.0.0');
        $pkg->setDescription('A globally installed tool');
        $this->createInstalledJson([$pkg]);

        Platform::putEnv('COMPOSER_HOME', $composerHome);

        $testDir = self::getUniqueTmpDirectory();
        chdir($testDir);

        $appTester = $this->getApplicationTester();
        $appTester->setInputs(['']);
        $appTester->run([
            'command' => 'global',
            'command-name' => 'show',
        ]);

        $output = $appTester->getDisplay(true);
        self::assertStringContainsString('vendor/global-tool', $output);
        self::assertStringContainsString('1.0.0', $output);
    }

    public function testGlobalShowWithoutPackages(): void
    {
        $composerHome = $this->initTempComposer();
        $this->createInstalledJson();

        Platform::putEnv('COMPOSER_HOME', $composerHome);

        $testDir = self::getUniqueTmpDirectory();
        chdir($testDir);

        $appTester = $this->getApplicationTester();
        $appTester->setInputs(['']);
        $appTester->run([
            'command' => 'global',
            'command-name' => 'show',
        ]);

        self::assertSame(0, $appTester->getStatusCode());
    }

    public function testGlobalRequire(): void
    {
        $composerHome = $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        [
                            'name' => 'vendor/required-pkg',
                            'version' => '2.0.0',
                            'dist' => ['type' => 'file', 'url' => __FILE__],
                        ],
                    ],
                ],
            ],
        ]);

        Platform::putEnv('COMPOSER_HOME', $composerHome);

        $testDir = self::getUniqueTmpDirectory();
        chdir($testDir);

        $appTester = $this->getApplicationTester();
        $appTester->setInputs(['']);
        $appTester->run([
            'command' => 'global',
            'command-name' => 'require',
            'packages' => ['vendor/required-pkg:2.0.0'],
        ]);

        self::assertSame(0, $appTester->getStatusCode());
        self::assertStringContainsString('Installing vendor/required-pkg', $appTester->getDisplay(true));
    }

    public function testGlobalUpdate(): void
    {
        $composerHome = $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor/pkg', 'version' => '1.0.0'],
                    ],
                ],
            ],
            'require' => [
                'vendor/pkg' => '1.0.0',
            ],
        ]);

        $pkg = self::getPackage('vendor/pkg', '1.0.0');
        $this->createInstalledJson([$pkg]);
        $this->createComposerLock([$pkg]);

        Platform::putEnv('COMPOSER_HOME', $composerHome);

        $testDir = self::getUniqueTmpDirectory();
        chdir($testDir);

        $appTester = $this->getApplicationTester();
        $appTester->setInputs(['']);
        $appTester->run([
            'command' => 'global',
            'command-name' => 'update',
        ]);

        self::assertSame(0, $appTester->getStatusCode());
    }

    public function testGlobalChangesDirectory(): void
    {
        $composerHome = $this->initTempComposer([
            'name' => 'test/global',
        ]);

        Platform::putEnv('COMPOSER_HOME', $composerHome);

        $testDir = self::getUniqueTmpDirectory();
        chdir($testDir);

        $appTester = $this->getApplicationTester();
        $appTester->setInputs(['']);
        $appTester->run([
            'command' => 'global',
            'command-name' => 'config',
            'setting-key' => 'name',
        ]);

        $output = $appTester->getDisplay(true);
        self::assertStringContainsString('Changed current directory to ' . $composerHome, $output);
    }

    public function testGlobalMissingCommandName(): void
    {
        $composerHome = $this->initTempComposer();

        Platform::putEnv('COMPOSER_HOME', $composerHome);

        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments (missing: "command-name")');

        $appTester = $this->getApplicationTester();
        $appTester->setInputs(['']);
        $appTester->run([
            'command' => 'global',
        ]);
    }
}
