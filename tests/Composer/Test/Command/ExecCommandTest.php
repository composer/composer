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

class ExecCommandTest extends TestCase
{
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
                'a'
            ]
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
