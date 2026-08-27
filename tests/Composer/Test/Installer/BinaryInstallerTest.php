<?php

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
use Composer\Composer;
use Composer\Config;
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

    protected function setUp()
    {
        $this->fs = new Filesystem;

        $this->rootDir = $this->getUniqueTmpDirectory();
        $this->vendorDir = $this->rootDir.DIRECTORY_SEPARATOR.'vendor';
        $this->ensureDirectoryExistsAndClear($this->vendorDir);

        $this->binDir = $this->rootDir.DIRECTORY_SEPARATOR.'bin';
        $this->ensureDirectoryExistsAndClear($this->binDir);

        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
    }

    protected function tearDown()
    {
        $this->fs->removeDirectory($this->rootDir);
    }

    /**
     * @dataProvider executableBinaryProvider
     * @param string $contents
     */
    public function testInstallAndExecBinaryWithFullCompat($contents)
    {
        $package = $this->createPackageMock();
        $package->expects($this->any())
            ->method('getBinaries')
            ->willReturn(array('binary'));

        $this->ensureDirectoryExistsAndClear($this->vendorDir.'/foo/bar');
        file_put_contents($this->vendorDir.'/foo/bar/binary', $contents);

        $installer = new BinaryInstaller($this->io, $this->binDir, 'full', $this->fs);
        $installer->installBinaries($package, $this->vendorDir.'/foo/bar');

        $proc = new ProcessExecutor();
        $proc->execute($this->binDir.'/binary arg', $output);
        $this->assertEquals('', $proc->getErrorOutput());
        $this->assertEquals('success arg', $output);
    }

    /**
     * @requires function symlink
     */
    public function testInstallBinaryRejectsSymlinkEscapingPackageDir()
    {
        $package = $this->createPackageMock();
        $package->expects($this->any())
            ->method('getBinaries')
            ->willReturn(array('bin/pwn'));

        // A file outside the package install directory that must not be touched.
        $victim = $this->rootDir.'/victim.sh';
        file_put_contents($victim, "#!/bin/sh\necho pwned\n");
        chmod($victim, 0644);
        clearstatcache();
        $modeBefore = fileperms($victim);

        $installPath = $this->vendorDir.'/attacker/pkg';
        $this->ensureDirectoryExistsAndClear($installPath.'/bin');
        // bin/pwn is a symlink escaping the package to the victim file (GHSA-96h3-5x6v-m776).
        if (!@symlink('../../../../victim.sh', $installPath.'/bin/pwn')) {
            $this->markTestSkipped('Symbolic links are not supported on this platform');
        }

        $installer = new BinaryInstaller($this->io, $this->binDir, 'full', $this->fs);
        $installer->installBinaries($package, $installPath);

        $this->assertFileDoesNotExist($this->binDir.'/pwn', 'No vendor/bin proxy must be created for an escaping symlink bin');
        clearstatcache();
        $this->assertSame($modeBefore, fileperms($victim), 'A bin symlink escaping the package dir must not be chmod\'d');
    }

    public function testInstallBinaryRejectsTraversingBinPath()
    {
        // ".." bin metadata can reach BinaryInstaller without passing through the solver-time
        // ValidatingArrayLoader::validatePackage() check, e.g. via the ensureBinariesPresence()
        // re-generation loop which reads packages straight from installed.json.
        $package = $this->createPackageMock();
        $package->expects($this->any())
            ->method('getBinaries')
            ->willReturn(array('../../../victim.sh'));

        $victim = $this->rootDir.'/victim.sh';
        file_put_contents($victim, "#!/bin/sh\necho pwned\n");
        chmod($victim, 0600);
        clearstatcache();
        $modeBefore = fileperms($victim);

        $installPath = $this->vendorDir.'/attacker/pkg';
        $this->ensureDirectoryExistsAndClear($installPath);

        $installer = new BinaryInstaller($this->io, $this->binDir, 'full', $this->fs);
        $installer->installBinaries($package, $installPath);

        $this->assertFileDoesNotExist($this->binDir.'/victim.sh', 'No vendor/bin proxy must be created for a traversing bin');
        clearstatcache();
        $this->assertSame($modeBefore, fileperms($victim), 'A bin escaping the package dir via ".." must not be chmod\'d');
    }

    public function executableBinaryProvider()
    {
        $tests = array(
            'simple php file' => array(<<<'EOL'
<?php

echo 'success '.$_SERVER['argv'][1];
EOL
            ),
            'php file with shebang' => array(<<<'EOL'
#!/usr/bin/env php
<?php

echo 'success '.$_SERVER['argv'][1];
EOL
            ),
            'phar file' => array(
                base64_decode('IyEvdXNyL2Jpbi9lbnYgcGhwCjw/cGhwCgpQaGFyOjptYXBQaGFyKCd0ZXN0LnBoYXInKTsKCnJlcXVpcmUgJ3BoYXI6Ly90ZXN0LnBoYXIvcnVuLnBocCc7CgpfX0hBTFRfQ09NUElMRVIoKTsgPz4NCj4AAAABAAAAEQAAAAEACQAAAHRlc3QucGhhcgAAAAAHAAAAcnVuLnBocCoAAADb9n9hKgAAAMUDDWGkAQAAAAAAADw/cGhwIGVjaG8gInN1Y2Nlc3MgIi4kX1NFUlZFUlsiYXJndiJdWzFdO1SOC0IE3+UN0yzrHIwyspp9slhmAgAAAEdCTUI=')
            ),
        );

        if (PHP_VERSION_ID >= 70000) {
            $tests += array(
                'shebang with strict types declare' => array(<<<'EOL'
#!/usr/bin/env php
<?php declare(strict_types=1);

echo 'success '.$_SERVER['argv'][1];
EOL
                ),
            );
        }

        return $tests;
    }

    /**
     * @return \Composer\Package\PackageInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    protected function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\Package')
            ->setConstructorArgs(array(md5((string) mt_rand()), '1.0.0.0', '1.0.0'))
            ->getMock();
    }
}
