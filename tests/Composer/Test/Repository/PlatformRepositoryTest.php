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

use Composer\Package\Package;
use Composer\Repository\PlatformRepository;
use Composer\Test\TestCase;
use Composer\Util\ProcessExecutor;
use Composer\Package\Version\VersionParser;
use Composer\Util\Platform;
use Symfony\Component\Process\ExecutableFinder;

class PlatformRepositoryTest extends TestCase
{
    public function testHHVMVersionWhenExecutingInHHVM()
    {
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

    public function testHHVMVersionWhenExecutingInPHP()
    {
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
        $repository = new PlatformRepository(array(), array());
        $package = $repository->findPackage('hhvm', '*');
        $this->assertNotNull($package, 'failed to find HHVM package');

        $process = new ProcessExecutor();
        $exitCode = $process->execute(
            ProcessExecutor::escape($hhvm).
            ' --php -d hhvm.jit=0 -r "echo HHVM_VERSION;" 2>/dev/null',
            $version
        );
        $parser = new VersionParser;

        $this->assertSame($parser->normalize($version), $package->getVersion());
    }

    public function testICULibraryVersion() {
        if (!defined('INTL_ICU_VERSION')) {
            $this->markTestSkipped('Test only work with ext-intl present');
        }

        if (!class_exists('ResourceBundle', false)) {
            $this->markTestSkipped('Test only work with ResourceBundle class present');
        }

        if (!class_exists('IntlChar', false)) {
            $this->markTestSkipped('Test only work with ResourceBundle class present');
        }

        $platformRepository = new PlatformRepository();
        $packages = $platformRepository->getPackages();

        /** @var Package $icuPackage */
        $icuPackage = null;
        /** @var Package $cldrPackage */
        $cldrPackage = null;
        /** @var Package $unicodePackage */
        $unicodePackage = null;

        foreach ($packages as $package) {
            if ($package->getName() === 'lib-icu') {
                $icuPackage = $package;
            }

            if ($package->getName() === 'lib-icu-cldr') {
                $cldrPackage = $package;
            }

            if ($package->getName() === 'lib-icu-unicode') {
                $unicodePackage = $package;
            }
        }

        self::assertNotNull($icuPackage, 'Expected to find lib-icu in packages');
        self::assertNotNull($cldrPackage, 'Expected to find lib-icu-cldr in packages');
        self::assertNotNull($unicodePackage, 'Expected to find lib-icu-unicode in packages');

        self::assertSame(3, substr_count($icuPackage->getVersion(), '.'), 'Expected to find real ICU version');
        self::assertSame(3, substr_count($cldrPackage->getVersion(), '.'), 'Expected to find real CLDR version');
        self::assertSame(3, substr_count($unicodePackage->getVersion(), '.'), 'Expected to find real unicode version');
    }
}
