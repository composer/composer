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

namespace Composer\Test\Package\Archiver;

use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Composer\Package\CompletePackage;

abstract class ArchiverTestCase extends TestCase
{
    /**
     * @var \Composer\Util\Filesystem
     */
    protected $filesystem;

    /**
     * @var \Composer\Util\ProcessExecutor
     */
    protected $process;

    /**
     * @var string
     */
    protected $testDir;

    public function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->process = new ProcessExecutor();
        $this->testDir = self::getUniqueTmpDirectory();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->filesystem->removeDirectory($this->testDir);
    }

    /**
     * Util method to quickly setup a package using the source path built.
     */
    protected function setupPackage(): CompletePackage
    {
        $package = new CompletePackage('archivertest/archivertest', 'master', 'master');
        $package->setSourceUrl((string) realpath($this->testDir));
        $package->setSourceReference('master');
        $package->setSourceType('git');

        return $package;
    }
}
