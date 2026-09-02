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
use Composer\Util\Platform;
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
     * @var Filesystem
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
        self::assertEquals('', $proc->getErrorOutput());
        self::assertEquals('success arg', $output);
    }

    /**
     * @requires function symlink
     */
    public function testInstallBinaryRejectsSymlinkEscapingPackageDir(): void
    {
        $package = $this->createPackageMock();
        $package->expects($this->any())
            ->method('getBinaries')
            ->willReturn(['bin/pwn']);

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

        self::assertFileDoesNotExist($this->binDir.'/pwn', 'No vendor/bin proxy must be created for an escaping symlink bin');
        clearstatcache();
        self::assertSame($modeBefore, fileperms($victim), 'A bin symlink escaping the package dir must not be chmod\'d');
    }

    public function testInstallBinaryRejectsTraversingBinPath(): void
    {
        // ".." bin metadata can reach BinaryInstaller without passing through the solver-time
        // ValidatingArrayLoader::validatePackage() check, e.g. via the ensureBinariesPresence()
        // re-generation loop which reads packages straight from installed.json.
        $package = $this->createPackageMock();
        $package->expects($this->any())
            ->method('getBinaries')
            ->willReturn(['../../../victim.sh']);

        $victim = $this->rootDir.'/victim.sh';
        file_put_contents($victim, "#!/bin/sh\necho pwned\n");
        chmod($victim, 0600);
        clearstatcache();
        $modeBefore = fileperms($victim);

        $installPath = $this->vendorDir.'/attacker/pkg';
        $this->ensureDirectoryExistsAndClear($installPath);

        $installer = new BinaryInstaller($this->io, $this->binDir, 'full', $this->fs);
        $installer->installBinaries($package, $installPath);

        self::assertFileDoesNotExist($this->binDir.'/victim.sh', 'No vendor/bin proxy must be created for a traversing bin');
        clearstatcache();
        self::assertSame($modeBefore, fileperms($victim), 'A bin escaping the package dir via ".." must not be chmod\'d');
    }

    public function testPhpProxyDoesNotRunCodeInjectedViaTheBinPath(): void
    {
        if (Platform::isWindows()) {
            $this->markTestSkipped('The bin path of this test cannot be created on Windows');
        }

        // every segment of a bin path is package controlled, incl. the directory names below
        $bin = "a*/ namespace Injected; echo 'PWNED'; /* x/binary";
        $package = $this->createPackageMock();
        $package->expects($this->any())
            ->method('getBinaries')
            ->willReturn([$bin]);

        $installPath = $this->vendorDir.'/attacker/pkg';
        $this->fs->ensureDirectoryExists($installPath.'/'.dirname($bin));
        file_put_contents($installPath.'/'.$bin, "<?php\n\necho 'success '.\$_SERVER['argv'][1];");

        $installer = new BinaryInstaller($this->io, $this->binDir, 'proxy', $this->fs);
        $installer->installBinaries($package, $installPath);

        // the first comment terminator of the proxy must be the one ending its own docblock
        $proxy = (string) file_get_contents($this->binDir.'/binary');
        $docblockEnd = strpos($proxy, '*/');
        self::assertNotFalse($docblockEnd);
        self::assertStringContainsString('@generated', substr($proxy, 0, $docblockEnd), 'The bin path must not be able to close the docblock');

        $proc = new ProcessExecutor();
        $proc->execute(ProcessExecutor::escape($this->binDir.'/binary').' arg', $output);
        self::assertSame('', $proc->getErrorOutput());
        self::assertSame('success arg', $output, 'The proxy must run the bin and nothing else');
    }

    /**
     * @dataProvider injectedBinFilenameProvider
     */
    public function testShProxyDoesNotRunCodeInjectedViaTheBinFilename(string $binFile): void
    {
        if (Platform::isWindows()) {
            $this->markTestSkipped('The bin filenames of this test cannot be created on Windows');
        }

        $package = $this->createPackageMock();
        $package->expects($this->any())
            ->method('getBinaries')
            ->willReturn([$binFile]);

        $installPath = $this->vendorDir.'/attacker/pkg';
        self::ensureDirectoryExistsAndClear($installPath);
        file_put_contents($installPath.'/'.$binFile, "#!/bin/sh\nprintf 'success %s' \"\$1\"\n");

        $installer = new BinaryInstaller($this->io, $this->binDir, 'proxy', $this->fs);
        $installer->installBinaries($package, $installPath);

        $proc = new ProcessExecutor();
        $proc->execute(ProcessExecutor::escape($this->binDir.'/'.$binFile).' arg', $output);
        self::assertSame('', $proc->getErrorOutput());
        self::assertSame('success arg', $output, 'The proxy must run the bin and nothing else');
    }

    /**
     * @dataProvider unsafeShebangProvider
     */
    public function testPhpProxyDoesNotCarryOverAnUnsafeShebang(string $shebang): void
    {
        $package = $this->createPackageMock();
        $package->expects($this->any())
            ->method('getBinaries')
            ->willReturn(['binary']);

        $installPath = $this->vendorDir.'/attacker/pkg';
        self::ensureDirectoryExistsAndClear($installPath);
        file_put_contents($installPath.'/binary', $shebang."\n<?php\n\necho 'success';");

        $installer = new BinaryInstaller($this->io, $this->binDir, 'proxy', $this->fs);
        $installer->installBinaries($package, $installPath);

        $proxy = (string) file_get_contents($this->binDir.'/binary');
        self::assertStringStartsWith("#!/usr/bin/env php\n", $proxy, 'An unsafe shebang must not be carried over');
        self::assertStringNotContainsString($shebang, $proxy);
    }

    public function testWindowsProxyEscapesTheTargetPath(): void
    {
        $installPath = $this->vendorDir.'/foo/bar/a&b';
        $this->fs->ensureDirectoryExists($installPath);
        file_put_contents($installPath.'/bin%x', "#!/bin/sh\nprintf hi\n");

        $installer = new BinaryInstaller($this->io, $this->binDir, 'full', $this->fs);
        $method = new \ReflectionMethod($installer, 'generateWindowsProxyCode');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $bat = $method->invoke($installer, $installPath.'/bin%x', $this->binDir.'/bin%x.bat');

        // the quoted SET keeps cmd.exe from acting on the & of the path, and the % is doubled as a
        // batch file collapses %% back to a single %
        self::assertStringContainsString("SET \"BIN_TARGET=%~dp0/../vendor/foo/bar/a&b/bin%%x\"\r\n", $bat);
        self::assertStringContainsString("SET \"COMPOSER_RUNTIME_BIN_DIR=%~dp0\"\r\n", $bat);
    }

    public function testWindowsProxyFallsBackToPhpForAnUnsafeShebang(): void
    {
        $installPath = $this->vendorDir.'/attacker/pkg';
        self::ensureDirectoryExistsAndClear($installPath);
        file_put_contents($installPath.'/binary', "#!/usr/bin/env php\" \" & calc.exe\n<?php\n\necho 'success';");

        $installer = new BinaryInstaller($this->io, $this->binDir, 'full', $this->fs);
        $method = new \ReflectionMethod($installer, 'generateWindowsProxyCode');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $bat = $method->invoke($installer, $installPath.'/binary', $this->binDir.'/binary.bat');

        self::assertStringNotContainsString('calc.exe', $bat);
        self::assertStringEndsWith("php \"%BIN_TARGET%\" %*\r\n", $bat);
    }

    public function testFullCompatSkipsABinWhosePathCannotBeRepresentedInABatProxy(): void
    {
        if (Platform::isWindows()) {
            $this->markTestSkipped('The bin filename of this test cannot be created on Windows');
        }

        $package = $this->createPackageMock();
        $package->expects($this->any())
            ->method('getBinaries')
            ->willReturn(['pw"n']);

        $this->io->expects($this->atLeastOnce())
            ->method('writeError')
            ->with($this->stringContains('cannot be used in a Windows bin proxy'));

        $installPath = $this->vendorDir.'/attacker/pkg';
        self::ensureDirectoryExistsAndClear($installPath);
        file_put_contents($installPath.'/pw"n', "#!/bin/sh\nprintf hi\n");

        $installer = new BinaryInstaller($this->io, $this->binDir, 'full', $this->fs);
        $installer->installBinaries($package, $installPath);

        self::assertFileDoesNotExist($this->binDir.'/pw"n');
        self::assertFileDoesNotExist($this->binDir.'/pw"n.bat');
    }

    /**
     * @dataProvider binaryCallerProvider
     */
    public function testDetermineBinaryCaller(string $contents, string $expected): void
    {
        $bin = $this->rootDir.'/caller-bin';
        file_put_contents($bin, $contents);

        self::assertSame($expected, BinaryInstaller::determineBinaryCaller($bin));
    }

    public function testDetermineBinaryCallerForBatFiles(): void
    {
        self::assertSame('call', BinaryInstaller::determineBinaryCaller($this->rootDir.'/does-not-exist.bat'));
        self::assertSame('call', BinaryInstaller::determineBinaryCaller($this->rootDir.'/does-not-exist.exe'));
    }

    public static function binaryCallerProvider(): array
    {
        return [
            'env php' => ["#!/usr/bin/env php\n<?php", 'php'],
            'sh' => ["#!/bin/sh\n", 'sh'],
            'sh with argument' => ["#!/bin/sh -e\n", 'sh -e'],
            'php with options' => ["#!/usr/bin/env php -d memory_limit=-1\n", 'php -d memory_limit=-1'],
            'versioned interpreter' => ["#!/usr/bin/php7.4\n", 'php7.4'],
            'env with split string' => ["#!/usr/bin/env -S php -d x=1\n", '-S php -d x=1'],
            'crlf line ending' => ["#!/usr/bin/env php\r\n<?php", 'php'],
            'trailing whitespace' => ["#!/usr/bin/env php  \n", 'php'],
            'no shebang' => ["<?php\n", 'php'],
            'cmd metacharacters' => ["#!/usr/bin/env php\" \" & calc.exe\n", 'php'],
            'command substitution' => ["#!/usr/bin/env php\$(id)\n", 'php'],
            'php open tag' => ["#!<?php echo 'PWNED'; ?>\n<?php", 'php'],
        ];
    }

    public static function unsafeShebangProvider(): array
    {
        return [
            'php open tag' => ["#!<?php echo 'PWNED'; ?>"],
            'cmd metacharacters' => ['#!/usr/bin/env php" " & calc.exe'],
            'command substitution' => ['#!/usr/bin/env php$(id)'],
        ];
    }

    public static function injectedBinFilenameProvider(): array
    {
        return [
            'command substitution' => ['pwn$(echo INJECTED >&2)'],
            'backticks' => ['pwn`echo INJECTED >&2`'],
            'double quote' => ['pwn";echo INJECTED >&2;"'],
            'single quote' => ["pwn'quote"],
        ];
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
            'php file with crlf shebang' => ["#!/usr/bin/env php\r\n<?php\n\necho 'success '.\$_SERVER['argv'][1];"],
            'shell script' => ["#!/bin/sh\nprintf 'success %s' \"\$1\"\n"],
        ];
    }

    /**
     * @return \Composer\Package\PackageInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    protected function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\Package')
            ->setConstructorArgs([bin2hex(random_bytes(5)), '1.0.0.0', '1.0.0'])
            ->getMock();
    }
}
