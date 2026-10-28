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

use Composer\Installer\BinaryInstaller;
use Composer\IO\NullIO;
use Composer\Test\TestCase;
use Symfony\Component\Process\Process;

class ExecCommandTest extends TestCase
{
    public function testExecFindsInstalledBinaryOnPath(): void
    {
        $directory = $this->initTempComposer();
        $package = self::getPackage('vendor/tool');
        $package->setBinaries(['fixture-tool']);
        $this->createInstalledJson([$package]);
        file_put_contents('vendor/vendor/tool/fixture-tool', '<?php file_put_contents("binary-result.txt", "executed");');
        $installer = new BinaryInstaller(new NullIO(), 'vendor/bin', 'auto');
        $installer->installBinaries($package, 'vendor/vendor/tool');
        $process = new Process([
            PHP_BINARY, dirname(__DIR__, 4).'/bin/composer', 'exec', 'fixture-tool', '--no-interaction',
        ], $directory);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('executed', file_get_contents('binary-result.txt'));
    }

    public function testListThrowsIfNoBinariesExist(): void
    {
        $composerDir = $this->initTempComposer();

        $composerBinDir = "$composerDir/vendor/bin";
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            "No binaries found in composer.json or in bin-dir ($composerBinDir)"
        );

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'exec', '--list' => true]);
    }

    public function testList(): void
    {
        $composerDir = $this->initTempComposer([
            'bin' => [
                'a',
            ],
        ]);

        $composerBinDir = "$composerDir/vendor/bin";
        mkdir($composerBinDir, 0777, true);
        touch($composerBinDir . '/b');
        touch($composerBinDir . '/b.bat');
        touch($composerBinDir . '/c');

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'exec', '--list' => true]);

        $output = $appTester->getDisplay(true);

        self::assertSame(
            'Available binaries:
- b
- c
- a (local)',
            trim($output)
        );
    }
}
