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

namespace Composer\Platform;

use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Process\ExecutableFinder;

class HhvmDetector
{
    /** @var string|false|null */
    private static $hhvmVersion = null;
    /** @var ?ExecutableFinder */
    private $executableFinder;
    /** @var ?ProcessExecutor */
    private $processExecutor;

    public function __construct(?ExecutableFinder $executableFinder = null, ?ProcessExecutor $processExecutor = null)
    {
        $this->executableFinder = $executableFinder;
        $this->processExecutor = $processExecutor;
    }

    /**
     * @return void
     */
    public function reset(): void
    {
        self::$hhvmVersion = null;
    }

    /**
     * @return string|null
     */
    public function getVersion(): ?string
    {
        if (null !== self::$hhvmVersion) {
            return self::$hhvmVersion ?: null;
        }

        self::$hhvmVersion = defined('HHVM_VERSION') ? HHVM_VERSION : null;
        if (self::$hhvmVersion === null && !Platform::isWindows()) {
            self::$hhvmVersion = false;
            $this->executableFinder = $this->executableFinder ?: new ExecutableFinder();
            $hhvmPath = $this->executableFinder->find('hhvm');
            if ($hhvmPath !== null) {
                $this->processExecutor = $this->processExecutor ?? new ProcessExecutor();
                $exitCode = $this->processExecutor->execute(
                    ProcessExecutor::escape($hhvmPath).
                    ' --php -d hhvm.jit=0 -r "echo HHVM_VERSION;" 2>/dev/null',
                    self::$hhvmVersion
                );
                if ($exitCode !== 0) {
                    self::$hhvmVersion = false;
                }
            }
        }

        return self::$hhvmVersion ?: null;
    }
}
