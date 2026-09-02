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
use Composer\Util\Platform;
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

    public function testPhpProxyDoesNotRunCodeInjectedViaTheBinPath()
    {
        if (Platform::isWindows()) {
            $this->markTestSkipped('The bin path of this test cannot be created on Windows');
        }

        // every segment of a bin path is package controlled, incl. the directory names below.
        // installBinaries() refuses this path outright, so go through the generator directly to
        // keep the escaping itself covered.
        $bin = "a*/ namespace Injected; echo 'PWNED'; /* x/binary";
        $installPath = $this->vendorDir.'/attacker/pkg';
        $this->fs->ensureDirectoryExists($installPath.'/'.dirname($bin));
        file_put_contents($installPath.'/'.$bin, "<?php\n\necho 'success '.\$_SERVER['argv'][1];");

        $proxy = $this->generateProxy($installPath.'/'.$bin, $this->binDir.'/binary');

        // the first comment terminator of the proxy must be the one ending its own docblock
        $docblockEnd = strpos($proxy, '*/');
        $this->assertNotFalse($docblockEnd);
        $this->assertStringContainsString('@generated', substr($proxy, 0, $docblockEnd), 'The bin path must not be able to close the docblock');

        $proc = new ProcessExecutor();
        $proc->execute(ProcessExecutor::escape($this->binDir.'/binary').' arg', $output);
        $this->assertSame('', $proc->getErrorOutput());
        $this->assertSame('success arg', $output, 'The proxy must run the bin and nothing else');
    }

    /**
     * @dataProvider injectedBinFilenameProvider
     * @param string $binFile
     */
    public function testShProxyDoesNotRunCodeInjectedViaTheBinFilename($binFile)
    {
        if (Platform::isWindows()) {
            $this->markTestSkipped('The bin filenames of this test cannot be created on Windows');
        }

        // installBinaries() refuses these filenames outright, so go through the generator directly
        // to keep the escaping itself covered
        $installPath = $this->vendorDir.'/attacker/pkg';
        $this->ensureDirectoryExistsAndClear($installPath);
        file_put_contents($installPath.'/'.$binFile, "#!/bin/sh\nprintf 'success %s' \"\$1\"\n");

        $this->generateProxy($installPath.'/'.$binFile, $this->binDir.'/'.$binFile);

        $proc = new ProcessExecutor();
        $proc->execute(ProcessExecutor::escape($this->binDir.'/'.$binFile).' arg', $output);
        $this->assertSame('', $proc->getErrorOutput());
        $this->assertSame('success arg', $output, 'The proxy must run the bin and nothing else');
    }

    /**
     * Writes out the unixy proxy the installer would generate, bypassing installBinaries()
     *
     * @param string $bin
     * @param string $link
     *
     * @return string
     */
    private function generateProxy($bin, $link)
    {
        $installer = new BinaryInstaller($this->io, $this->binDir, 'proxy', $this->fs);
        $method = new \ReflectionMethod($installer, 'generateUnixyProxyCode');
        $method->setAccessible(true);
        $proxy = $method->invoke($installer, $bin, $link);

        file_put_contents($link, $proxy);
        chmod($link, 0755);
        chmod($bin, 0755);

        return $proxy;
    }

    /**
     * @dataProvider unsafeShebangProvider
     * @param string $shebang
     */
    public function testPhpProxyDoesNotCarryOverAnUnsafeShebang($shebang)
    {
        $package = $this->createPackageMock();
        $package->expects($this->any())
            ->method('getBinaries')
            ->willReturn(array('binary'));

        $installPath = $this->vendorDir.'/attacker/pkg';
        $this->ensureDirectoryExistsAndClear($installPath);
        file_put_contents($installPath.'/binary', $shebang."\n<?php\n\necho 'success';");

        $installer = new BinaryInstaller($this->io, $this->binDir, 'proxy', $this->fs);
        $installer->installBinaries($package, $installPath);

        $proxy = file_get_contents($this->binDir.'/binary');
        $this->assertStringStartsWith("#!/usr/bin/env php\n", $proxy, 'An unsafe shebang must not be carried over');
        $this->assertStringNotContainsString($shebang, $proxy);
    }

    public function testWindowsProxyEscapesTheTargetPath()
    {
        $installPath = $this->vendorDir.'/foo/bar/a&b';
        $this->fs->ensureDirectoryExists($installPath);
        file_put_contents($installPath.'/bin%x', "#!/bin/sh\nprintf hi\n");

        $installer = new BinaryInstaller($this->io, $this->binDir, 'full', $this->fs);
        $method = new \ReflectionMethod($installer, 'generateWindowsProxyCode');
        $method->setAccessible(true);
        $bat = $method->invoke($installer, $installPath.'/bin%x', $this->binDir.'/bin%x.bat');

        // the quoted SET keeps cmd.exe from acting on the & of the path, and the % is doubled as a
        // batch file collapses %% back to a single %
        $this->assertStringContainsString("SET \"BIN_TARGET=%~dp0/../vendor/foo/bar/a&b/bin%%x\"\r\n", $bat);
        $this->assertStringContainsString("SET \"COMPOSER_RUNTIME_BIN_DIR=%~dp0\"\r\n", $bat);
    }

    public function testWindowsProxyFallsBackToPhpForAnUnsafeShebang()
    {
        $installPath = $this->vendorDir.'/attacker/pkg';
        $this->ensureDirectoryExistsAndClear($installPath);
        file_put_contents($installPath.'/binary', "#!/usr/bin/env php\" \" & calc.exe\n<?php\n\necho 'success';");

        $installer = new BinaryInstaller($this->io, $this->binDir, 'full', $this->fs);
        $method = new \ReflectionMethod($installer, 'generateWindowsProxyCode');
        $method->setAccessible(true);
        $bat = $method->invoke($installer, $installPath.'/binary', $this->binDir.'/binary.bat');

        $this->assertStringNotContainsString('calc.exe', $bat);
        $this->assertStringEndsWith("php \"%BIN_TARGET%\" %*\r\n", $bat);
    }

    public function testFullCompatSkipsABinWhosePathCannotBeRepresentedInABatProxy()
    {
        if (Platform::isWindows()) {
            $this->markTestSkipped('The bin dir of this test cannot be created on Windows');
        }

        // a double quote is unrepresentable in the .bat proxy, and the bin dir comes from the
        // user's own config rather than from the package, so isSafeBinPath() does not cover it
        $binDir = $this->rootDir.'/bin"dir';
        $this->fs->ensureDirectoryExists($binDir);

        $package = $this->createPackageMock();
        $package->expects($this->any())
            ->method('getBinaries')
            ->willReturn(array('binary'));

        $this->io->expects($this->atLeastOnce())
            ->method('writeError')
            ->with($this->stringContains('cannot be used in a Windows bin proxy'));

        $installPath = $this->vendorDir.'/foo/bar';
        $this->ensureDirectoryExistsAndClear($installPath);
        file_put_contents($installPath.'/binary', "#!/bin/sh\nprintf hi\n");

        $installer = new BinaryInstaller($this->io, $binDir, 'full', $this->fs);
        $installer->installBinaries($package, $installPath);

        $this->assertFileDoesNotExist($binDir.'/binary');
        $this->assertFileDoesNotExist($binDir.'/binary.bat');
    }

    public function testInstallBinaryRejectsBinPathWithMetacharacters()
    {
        if (Platform::isWindows()) {
            $this->markTestSkipped('The bin filename of this test cannot be created on Windows');
        }

        // bins reach BinaryInstaller from sources ValidatingArrayLoader never sees, e.g. path
        // repositories or the ensureBinariesPresence() loop reading installed.json
        $package = $this->createPackageMock();
        $package->expects($this->any())
            ->method('getBinaries')
            ->willReturn(array('pwn$(id)'));

        $installPath = $this->vendorDir.'/attacker/pkg';
        $this->ensureDirectoryExistsAndClear($installPath);
        file_put_contents($installPath.'/pwn$(id)', "#!/bin/sh\nprintf hi\n");

        $installer = new BinaryInstaller($this->io, $this->binDir, 'proxy', $this->fs);
        $installer->installBinaries($package, $installPath);

        $this->assertFileDoesNotExist($this->binDir.'/pwn$(id)', 'No proxy must be created for a bin path with metacharacters');
    }

    /**
     * @dataProvider safeBinPathProvider
     * @param string $bin
     */
    public function testIsSafeBinPathAcceptsPathsPublishedPackagesUse($bin)
    {
        $this->assertTrue(BinaryInstaller::isSafeBinPath($bin));
    }

    public function safeBinPathProvider()
    {
        return array(
            'plain' => array('bin/console'),
            'space in directory' => array('bin/32 bit/wkhtmltopdf.exe'),
            'backslash separator' => array('src\yii'),
            'single quote' => array("bin/it's"),
            'dashes and dots' => array('bin/foo-bar.php'),
        );
    }

    /**
     * @dataProvider binaryCallerProvider
     * @param string $contents
     * @param string $expected
     */
    public function testDetermineBinaryCaller($contents, $expected)
    {
        $bin = $this->rootDir.'/caller-bin';
        file_put_contents($bin, $contents);

        $this->assertSame($expected, BinaryInstaller::determineBinaryCaller($bin));
    }

    public function testDetermineBinaryCallerForBatFiles()
    {
        $this->assertSame('call', BinaryInstaller::determineBinaryCaller($this->rootDir.'/does-not-exist.bat'));
        $this->assertSame('call', BinaryInstaller::determineBinaryCaller($this->rootDir.'/does-not-exist.exe'));
    }

    public function binaryCallerProvider()
    {
        return array(
            'env php' => array("#!/usr/bin/env php\n<?php", 'php'),
            'sh' => array("#!/bin/sh\n", 'sh'),
            'sh with argument' => array("#!/bin/sh -e\n", 'sh -e'),
            'php with options' => array("#!/usr/bin/env php -d memory_limit=-1\n", 'php -d memory_limit=-1'),
            'versioned interpreter' => array("#!/usr/bin/php7.4\n", 'php7.4'),
            'env with split string' => array("#!/usr/bin/env -S php -d x=1\n", '-S php -d x=1'),
            'crlf line ending' => array("#!/usr/bin/env php\r\n<?php", 'php'),
            'trailing whitespace' => array("#!/usr/bin/env php  \n", 'php'),
            'no shebang' => array("<?php\n", 'php'),
            'cmd metacharacters' => array("#!/usr/bin/env php\" \" & calc.exe\n", 'php'),
            'command substitution' => array("#!/usr/bin/env php\$(id)\n", 'php'),
            'php open tag' => array("#!<?php echo 'PWNED'; ?>\n<?php", 'php'),
        );
    }

    public function unsafeShebangProvider()
    {
        return array(
            'php open tag' => array("#!<?php echo 'PWNED'; ?>"),
            'cmd metacharacters' => array('#!/usr/bin/env php" " & calc.exe'),
            'command substitution' => array('#!/usr/bin/env php$(id)'),
        );
    }

    public function injectedBinFilenameProvider()
    {
        // no whitespace in these: the sh proxy passes $selfArg to realpath unquoted, so a bin
        // filename containing a space breaks its own path resolution before the quoting under
        // test here even comes into play
        return array(
            'command substitution' => array('pwn$(id)'),
            'backticks' => array('pwn`id`'),
            'double quote' => array('pwn";id;"'),
            'single quote' => array("pwn'quote"),
        );
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
            'php file with crlf shebang' => array("#!/usr/bin/env php\r\n<?php\n\necho 'success '.\$_SERVER['argv'][1];"),
            'shell script' => array("#!/bin/sh\nprintf 'success %s' \"\$1\"\n"),
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
