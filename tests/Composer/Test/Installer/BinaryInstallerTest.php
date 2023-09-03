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

namespace Composer\Test\Installer;

use Composer\Installer\BinaryInstaller;
use Composer\Util\Filesystem;
use Composer\Test\TestCase;
use Composer\Util\ProcessExecutor;

class BinaryInstallerTest extends TestCase
{
    /**
     * @var string
     */
    protected $rootDir;

    /**
     * @var string
     */
    protected $vendorDir;

    /**
     * @var string
     */
    protected $binDir;

    /**
     * @var \Composer\IO\IOInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    protected $io;

    /**
     * @var \Composer\Util\Filesystem
     */
    protected $fs;

    protected function setUp(): void
    {
        $this->fs = new Filesystem;

        $this->rootDir = self::getUniqueTmpDirectory();
        $this->vendorDir = $this->rootDir.DIRECTORY_SEPARATOR.'vendor';
        $this->ensureDirectoryExistsAndClear($this->vendorDir);

        $this->binDir = $this->rootDir.DIRECTORY_SEPARATOR.'bin';
        $this->ensureDirectoryExistsAndClear($this->binDir);

        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->fs->removeDirectory($this->rootDir);
    }

    /**
     * @dataProvider executableBinaryProvider
     */
    public function testInstallAndExecBinaryWithFullCompat(string $contents): void
    {
        $package = $this->createPackageMock();
        $package->expects($this->any())
            ->method('getBinaries')
            ->willReturn(['binary']);

        $this->ensureDirectoryExistsAndClear($this->vendorDir.'/foo/bar');
        file_put_contents($this->vendorDir.'/foo/bar/binary', $contents);

        $installer = new BinaryInstaller($this->io, $this->binDir, 'full', $this->fs);
        $installer->installBinaries($package, $this->vendorDir.'/foo/bar');

        $proc = new ProcessExecutor();
        $proc->execute($this->binDir.'/binary arg', $output);
        $this->assertEquals('', $proc->getErrorOutput());
        $this->assertEquals('success arg', $output);
    }

    public static function executableBinaryProvider(): array
    {
        return [
            'simple php file' => [<<<'EOL'
<?php

echo 'success '.$_SERVER['argv'][1];
EOL
            ],
            'php file with shebang' => [<<<'EOL'
#!/usr/bin/env php
<?php

echo 'success '.$_SERVER['argv'][1];
EOL
            ],
            'phar file' => [
                base64_decode('IyEvdXNyL2Jpbi9lbnYgcGhwCjw/cGhwCgpQaGFyOjptYXBQaGFyKCd0ZXN0LnBoYXInKTsKCnJlcXVpcmUgJ3BoYXI6Ly90ZXN0LnBoYXIvcnVuLnBocCc7CgpfX0hBTFRfQ09NUElMRVIoKTsgPz4NCj4AAAABAAAAEQAAAAEACQAAAHRlc3QucGhhcgAAAAAHAAAAcnVuLnBocCoAAADb9n9hKgAAAMUDDWGkAQAAAAAAADw/cGhwIGVjaG8gInN1Y2Nlc3MgIi4kX1NFUlZFUlsiYXJndiJdWzFdO1SOC0IE3+UN0yzrHIwyspp9slhmAgAAAEdCTUI='),
            ],
            'shebang with strict types declare' => [<<<'EOL'
#!/usr/bin/env php
<?php declare(strict_types=1);

echo 'success '.$_SERVER['argv'][1];
EOL
            ],
        ];
    }

    /**
     * @return \Composer\Package\PackageInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    protected function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\Package')
            ->setConstructorArgs([md5((string) mt_rand()), '1.0.0.0', '1.0.0'])
            ->getMock();
    }
}
