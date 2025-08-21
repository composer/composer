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

namespace Composer\Test\Platform;

use Composer\Platform\HhvmDetector;
use Composer\Test\TestCase;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Process\ExecutableFinder;

class HhvmDetectorTest extends TestCase
{
    /**
     * @var HhvmDetector
     */
    private $hhvmDetector;

    protected function setUp(): void
    {
        $this->hhvmDetector = new HhvmDetector();
        $this->hhvmDetector->reset();
    }

    public function testHHVMVersionWhenExecutingInHHVM(): void
    {
        if (!defined('HHVM_VERSION_ID')) {
            self::markTestSkipped('Not running with HHVM');
        }
        $version = $this->hhvmDetector->getVersion();
        self::assertSame(self::versionIdToVersion(), $version);
    }

    public function testHHVMVersionWhenExecutingInPHP(): void
    {
        if (defined('HHVM_VERSION_ID')) {
            self::markTestSkipped('Running with HHVM');
        }
        if (Platform::isWindows()) {
            self::markTestSkipped('Test does not run on Windows');
        }
        $finder = new ExecutableFinder();
        $hhvm = $finder->find('hhvm');
        if ($hhvm === null) {
            self::markTestSkipped('HHVM is not installed');
        }

        $detectedVersion = $this->hhvmDetector->getVersion();
        self::assertNotNull($detectedVersion, 'Failed to detect HHVM version');

        $process = new ProcessExecutor();
        $exitCode = $process->execute(
            ProcessExecutor::escape($hhvm).
            ' --php -d hhvm.jit=0 -r "echo HHVM_VERSION;" 2>/dev/null',
            $version
        );
        self::assertSame(0, $exitCode);

        self::assertSame(self::getVersionParser()->normalize($version), self::getVersionParser()->normalize($detectedVersion));
    }

    private static function versionIdToVersion(): ?string
    {
        if (!defined('HHVM_VERSION_ID')) {
            return null;
        }

        return sprintf(
            '%d.%d.%d',
            HHVM_VERSION_ID / 10000,
            (HHVM_VERSION_ID / 100) % 100,
            HHVM_VERSION_ID % 100
        );
    }
}
