<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *         Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Repository;

use Composer\Repository\PlatformRepository;
use Composer\Test\TestCase;
use Composer\Util\Platform;
use Symfony\Component\Process\ExecutableFinder;

class PlatformRepositoryTest extends TestCase {
    public function testHHVMVersionWhenExecutingInHHVM() {
        if (!defined('HHVM_VERSION_ID')) {
            $this->markTestSkipped('Not running with HHVM');
            return;
        }
        $repository = new PlatformRepository();
        $package = $repository->findPackage('hhvm', '*');
        $this->assertNotNull($package, 'failed to find HHVM package');
        $this->assertSame(
            sprintf('%d.%d.%d',
                HHVM_VERSION_ID / 10000,
                (HHVM_VERSION_ID / 100) % 100,
                HHVM_VERSION_ID % 100
            ),
            $package->getPrettyVersion()
        );
    }

    public function testHHVMVersionWhenExecutingInPHP() {
        if (defined('HHVM_VERSION_ID')) {
            $this->markTestSkipped('Running with HHVM');
            return;
        }
        if (PHP_VERSION_ID < 50400) {
            $this->markTestSkipped('Test only works on PHP 5.4+');
            return;
        }
        if (Platform::isWindows()) {
            $this->markTestSkipped('Test does not run on Windows');
            return;
        }
        $finder = new ExecutableFinder();
        $hhvm = $finder->find('hhvm');
        if ($hhvm === null) {
            $this->markTestSkipped('HHVM is not installed');
        }
        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $process->expects($this->once())->method('execute')->will($this->returnCallback(
            function($command, &$out) {
                $this->assertContains('HHVM_VERSION', $command);
                $out = '4.0.1-dev';
                return 0;
            }
        ));
        $repository = new PlatformRepository(array(), array(), $process);
        $package = $repository->findPackage('hhvm', '*');
        $this->assertNotNull($package, 'failed to find HHVM package');
        $this->assertSame('4.0.1.0-dev', $package->getVersion());
    }
}
