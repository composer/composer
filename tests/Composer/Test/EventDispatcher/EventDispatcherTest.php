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

namespace Composer\Test\EventDispatcher;

use Composer\Autoload\AutoloadGenerator;
use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\EventDispatcher;
use Composer\EventDispatcher\ScriptExecutionException;
use Composer\Installer\InstallerEvents;
use Composer\Config;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Repository\ArrayRepository;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Test\Mock\HttpDownloaderMock;
use Composer\Test\Mock\InstallationManagerMock;
use Composer\Test\TestCase;
use Composer\IO\BufferIO;
use Composer\Script\ScriptEvents;
use Composer\Script\Event as ScriptEvent;
use Composer\Util\ProcessExecutor;
use Composer\Util\Platform;
use Symfony\Component\Console\Output\OutputInterface;

class EventDispatcherTest extends TestCase
{
    public function tearDown(): void
    {
        parent::tearDown();
        Platform::clearEnv('COMPOSER_SKIP_SCRIPTS');
    }

    public function testListenerExceptionsAreCaught(): void
    {
        self::expectException('RuntimeException');

        $io = $this->getIOMock(IOInterface::NORMAL);
        $dispatcher = $this->getDispatcherStubForListenersTest([
            'Composer\Test\EventDispatcher\EventDispatcherTest::call',
        ], $io);

        $io->expects([
            ['text' => '> Composer\Test\EventDispatcher\EventDispatcherTest::call'],
            ['text' => 'Script Composer\Test\EventDispatcher\EventDispatcherTest::call handling the post-install-cmd event terminated with an exception'],
        ], true);

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);
    }

    /**
     * @dataProvider provideValidCommands
     */
    public function testDispatcherCanExecuteSingleCommandLineScript(string $command): void
    {
        $process = $this->getProcessExecutorMock();
        $process->expects([
            $command,
        ], true);

        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs([
                $this->createComposerInstance(),
                $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
                $process,
            ])
            ->onlyMethods(['getListeners'])
            ->getMock();

        $listener = [$command];
        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listener));

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);
    }

    /**
     * @dataProvider provideDevModes
     */
    public function testDispatcherPassDevModeToAutoloadGeneratorForScriptEvents(bool $devMode): void
    {
        $composer = $this->createComposerInstance();

        $generator = $this->getGeneratorMockForDevModePassingTest();
        $generator->expects($this->atLeastOnce())
            ->method('setDevMode')
            ->with($devMode);

        $composer->setAutoloadGenerator($generator);

        $package = $this->getMockBuilder('Composer\Package\RootPackageInterface')->getMock();
        $package->method('getScripts')->will($this->returnValue(['scriptName' => ['ClassName::testMethod']]));
        $composer->setPackage($package);

        $composer->setRepositoryManager($this->getRepositoryManagerMockForDevModePassingTest());
        $composer->setInstallationManager($this->getMockBuilder('Composer\Installer\InstallationManager')->disableOriginalConstructor()->getMock());

        $dispatcher = new EventDispatcher(
            $composer,
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $this->getProcessExecutorMock()
        );

        $event = $this->getMockBuilder('Composer\Script\Event')
            ->disableOriginalConstructor()
            ->getMock();
        $event->method('getName')->will($this->returnValue('scriptName'));
        $event->expects($this->atLeastOnce())
            ->method('isDevMode')
            ->will($this->returnValue($devMode));

        $dispatcher->dispatch('scriptName', $event);
    }

    public static function provideDevModes(): array
    {
        return [
            [true],
            [false],
        ];
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\Autoload\AutoloadGenerator
     */
    private function getGeneratorMockForDevModePassingTest()
    {
        $generator = $this->getMockBuilder('Composer\Autoload\AutoloadGenerator')
            ->disableOriginalConstructor()
            ->onlyMethods([
                'buildPackageMap',
                'parseAutoloads',
                'createLoader',
                'setDevMode',
            ])
            ->getMock();
        $generator
            ->method('buildPackageMap')
            ->will($this->returnValue([]));
        $generator
            ->method('parseAutoloads')
            ->will($this->returnValue(['psr-0' => [], 'psr-4' => [], 'classmap' => [], 'files' => [], 'exclude-from-classmap' => []]));
        $generator
            ->method('createLoader')
            ->will($this->returnValue($this->getMockBuilder('Composer\Autoload\ClassLoader')->getMock()));

        return $generator;
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\Repository\RepositoryManager
     */
    private function getRepositoryManagerMockForDevModePassingTest()
    {
        $rm = $this->getMockBuilder('Composer\Repository\RepositoryManager')
            ->disableOriginalConstructor()
            ->onlyMethods(['getLocalRepository'])
            ->getMock();

        $repo = $this->getMockBuilder('Composer\Repository\InstalledRepositoryInterface')->getMock();
        $repo
            ->method('getCanonicalPackages')
            ->will($this->returnValue([]));

        $rm
            ->method('getLocalRepository')
            ->will($this->returnValue($repo));

        return $rm;
    }

    public function testDispatcherRemoveListener(): void
    {
        $composer = $this->createComposerInstance();

        $composer->setRepositoryManager($this->getRepositoryManagerMockForDevModePassingTest());
        $composer->setInstallationManager($this->getMockBuilder('Composer\Installer\InstallationManager')->disableOriginalConstructor()->getMock());

        $dispatcher = new EventDispatcher(
            $composer,
            $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
            $this->getProcessExecutorMock()
        );

        $listener = [$this, 'someMethod'];
        $listener2 = [$this, 'someMethod2'];
        $listener3 = 'Composer\\Test\\EventDispatcher\\EventDispatcherTest::someMethod';

        $dispatcher->addListener('ev1', $listener, 0);
        $dispatcher->addListener('ev1', $listener, 1);
        $dispatcher->addListener('ev1', $listener2, 1);
        $dispatcher->addListener('ev1', $listener3);
        $dispatcher->addListener('ev2', $listener3);
        $dispatcher->addListener('ev2', $listener);
        $dispatcher->dispatch('ev1');
        $dispatcher->dispatch('ev2');

        $expected = '> ev1: Composer\Test\EventDispatcher\EventDispatcherTest->someMethod'.PHP_EOL
            .'> ev1: Composer\Test\EventDispatcher\EventDispatcherTest->someMethod2'.PHP_EOL
            .'> ev1: Composer\Test\EventDispatcher\EventDispatcherTest->someMethod'.PHP_EOL
            .'> ev1: Composer\Test\EventDispatcher\EventDispatcherTest::someMethod'.PHP_EOL
            .'> ev2: Composer\Test\EventDispatcher\EventDispatcherTest::someMethod'.PHP_EOL
            .'> ev2: Composer\Test\EventDispatcher\EventDispatcherTest->someMethod'.PHP_EOL;
        self::assertEquals($expected, $io->getOutput());

        $dispatcher->removeListener($this);
        $dispatcher->dispatch('ev1');
        $dispatcher->dispatch('ev2');

        $expected .= '> ev1: Composer\Test\EventDispatcher\EventDispatcherTest::someMethod'.PHP_EOL
            .'> ev2: Composer\Test\EventDispatcher\EventDispatcherTest::someMethod'.PHP_EOL;
        self::assertEquals($expected, $io->getOutput());
    }

    public function testDispatcherCanExecuteCliAndPhpInSameEventScriptStack(): void
    {
        $process = $this->getProcessExecutorMock();
        $process->expects([
            'echo -n foo',
            'echo -n bar',
        ], true);

        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs([
                $this->createComposerInstance(),
                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
                $process,
            ])
            ->onlyMethods([
                'getListeners',
            ])
            ->getMock();

        $listeners = [
            'echo -n foo',
            'Composer\\Test\\EventDispatcher\\EventDispatcherTest::someMethod',
            'echo -n bar',
        ];

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listeners));

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);

        $expected = '> post-install-cmd: echo -n foo'.PHP_EOL.
            '> post-install-cmd: Composer\Test\EventDispatcher\EventDispatcherTest::someMethod'.PHP_EOL.
            '> post-install-cmd: echo -n bar'.PHP_EOL;
        self::assertEquals($expected, $io->getOutput());
    }

    public function testDispatcherCanPutEnv(): void
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs([
                $this->createComposerInstance(),
                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
                $this->getProcessExecutorMock(),
            ])
            ->onlyMethods([
                'getListeners',
            ])
            ->getMock();

        $listeners = [
            '@putenv ABC=123',
            'Composer\\Test\\EventDispatcher\\EventDispatcherTest::getTestEnv',
        ];

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listeners));

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);

        $expected = '> post-install-cmd: @putenv ABC=123'.PHP_EOL.
            '> post-install-cmd: Composer\Test\EventDispatcher\EventDispatcherTest::getTestEnv'.PHP_EOL;
        self::assertEquals($expected, $io->getOutput());
    }

    public function testDispatcherAppendsDirBinOnPathForEveryListener(): void
    {
        $currentDirectoryBkp = Platform::getCwd();
        $composerBinDirBkp = Platform::getEnv('COMPOSER_BIN_DIR');
        chdir(__DIR__);
        Platform::putEnv('COMPOSER_BIN_DIR', __DIR__ . '/vendor/bin');

        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->setConstructorArgs([
                $this->createComposerInstance(),
                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
                $this->getProcessExecutorMock(),
            ])->onlyMethods([
                'getListeners',
            ])->getMock();

        $listeners = [
            'Composer\\Test\\EventDispatcher\\EventDispatcherTest::createsVendorBinFolderChecksEnvDoesNotContainsBin',
            'Composer\\Test\\EventDispatcher\\EventDispatcherTest::createsVendorBinFolderChecksEnvContainsBin',
        ];

        $dispatcher->expects($this->atLeastOnce())->method('getListeners')->will($this->returnValue($listeners));

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);
        rmdir(__DIR__ . '/vendor/bin');
        rmdir(__DIR__ . '/vendor');

        chdir($currentDirectoryBkp);
        if ($composerBinDirBkp) {
            Platform::putEnv('COMPOSER_BIN_DIR', $composerBinDirBkp);
        } else {
            Platform::clearEnv('COMPOSER_BIN_DIR');
        }
    }

    public function testDispatcherSupportForAdditionalArgs(): void
    {
        $process = $this->getProcessExecutorMock();
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs([
                $this->createComposerInstance(),
                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
                $process,
            ])
            ->onlyMethods([
                'getListeners',
            ])
            ->getMock();

        $reflMethod = new \ReflectionMethod($dispatcher, 'getPhpExecCommand');
        if (PHP_VERSION_ID < 80100) {
            $reflMethod->setAccessible(true);
        }
        $phpCmd = $reflMethod->invoke($dispatcher);

        $args = ProcessExecutor::escape('ARG').' '.ProcessExecutor::escape('ARG2').' '.ProcessExecutor::escape('--arg');
        $process->expects([
            'echo -n foo',
            $phpCmd.' foo.php '.$args.' then the rest',
            'echo -n bar '.$args,
        ], true);

        $listeners = [
            'echo -n foo @no_additional_args',
            '@php foo.php @additional_args then the rest',
            'echo -n bar',
        ];

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listeners));

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false, ['ARG', 'ARG2', '--arg']);

        $expected = '> post-install-cmd: echo -n foo'.PHP_EOL.
            '> post-install-cmd: @php foo.php '.$args.' then the rest'.PHP_EOL.
            '> post-install-cmd: echo -n bar '.$args.PHP_EOL;
        self::assertEquals($expected, $io->getOutput());
    }

    public static function createsVendorBinFolderChecksEnvDoesNotContainsBin(): void
    {
        mkdir(__DIR__ . '/vendor/bin', 0700, true);
        $val = getenv('PATH');

        if (!$val) {
            $val = getenv('Path');
        }

        self::assertStringNotContainsString(__DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin', $val);
    }

    public static function createsVendorBinFolderChecksEnvContainsBin(): void
    {
        $val = getenv('PATH');

        if (!$val) {
            $val = getenv('Path');
        }

        self::assertStringContainsString(__DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin', $val);
    }

    public static function getTestEnv(): void
    {
        $val = getenv('ABC');
        if ($val !== '123') {
            throw new \Exception('getenv() did not return the expected value. expected 123 got '. var_export($val, true));
        }
    }

    public function testDispatcherCanExecuteComposerScriptGroups(): void
    {
        $process = $this->getProcessExecutorMock();
        $process->expects([
            'echo -n foo',
            'echo -n baz',
            'echo -n bar',
        ], true);

        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs([
                $composer = $this->createComposerInstance(),
                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
                $process,
            ])
            ->onlyMethods([
                'getListeners',
            ])
            ->getMock();

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnCallback(static function (Event $event): array {
                if ($event->getName() === 'root') {
                    return ['@group'];
                }

                if ($event->getName() === 'group') {
                    return ['echo -n foo', '@subgroup', 'echo -n bar'];
                }

                if ($event->getName() === 'subgroup') {
                    return ['echo -n baz'];
                }

                return [];
            }));

        $dispatcher->dispatch('root', new ScriptEvent('root', $composer, $io));
        $expected = '> root: @group'.PHP_EOL.
            '> group: echo -n foo'.PHP_EOL.
            '> group: @subgroup'.PHP_EOL.
            '> subgroup: echo -n baz'.PHP_EOL.
            '> group: echo -n bar'.PHP_EOL;
        self::assertEquals($expected, $io->getOutput());
    }

    public function testRecursionInScriptsNames(): void
    {
        $process = $this->getProcessExecutorMock();
        $process->expects([
            'echo Hello '.ProcessExecutor::escape('World'),
        ], true);

        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs([
                $composer = $this->createComposerInstance(),
                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
                $process,
            ])
            ->onlyMethods([
                'getListeners',
            ])
            ->getMock();

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnCallback(static function (Event $event): array {
                if ($event->getName() === 'hello') {
                    return ['echo Hello'];
                }

                if ($event->getName() === 'helloWorld') {
                    return ['@hello World'];
                }

                return [];
            }));

        $dispatcher->dispatch('helloWorld', new ScriptEvent('helloWorld', $composer, $io));
        $expected = "> helloWorld: @hello World".PHP_EOL.
            "> hello: echo Hello " .self::getCmd("'World'").PHP_EOL;

        self::assertEquals($expected, $io->getOutput());
    }

    public function testDispatcherDetectInfiniteRecursion(): void
    {
        self::expectException('RuntimeException');

        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
        ->setConstructorArgs([
            $composer = $this->createComposerInstance(),
            $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $this->getProcessExecutorMock(),
        ])
        ->onlyMethods([
            'getListeners',
        ])
        ->getMock();

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnCallback(static function (Event $event): array {
                if ($event->getName() === 'root') {
                    return ['@recurse'];
                }

                if ($event->getName() === 'recurse') {
                    return ['@root'];
                }

                return [];
            }));

        $dispatcher->dispatch('root', new ScriptEvent('root', $composer, $io));
    }

    /**
     * @param array<callable|string> $listeners
     *
     * @return \PHPUnit\Framework\MockObject\MockObject&EventDispatcher
     */
    private function getDispatcherStubForListenersTest(array $listeners, IOInterface $io)
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs([
                $this->createComposerInstance(),
                $io,
            ])
            ->onlyMethods(['getListeners'])
            ->getMock();

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listeners));

        return $dispatcher;
    }

    public static function provideValidCommands(): array
    {
        return [
            ['phpunit'],
            ['echo foo'],
            ['echo -n foo'],
        ];
    }

    public function testDispatcherOutputsCommand(): void
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs([
                $this->createComposerInstance(),
                $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
                new ProcessExecutor($io),
            ])
            ->onlyMethods(['getListeners'])
            ->getMock();

        $listener = ['echo foo'];
        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listener));

        $io->expects($this->once())
            ->method('writeError')
            ->with($this->equalTo('> echo foo'));

        $io->expects($this->once())
            ->method('writeRaw')
            ->with($this->equalTo('foo'.PHP_EOL), false);

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);
    }

    public function testDispatcherOutputsErrorOnFailedCommand(): void
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs([
                $this->createComposerInstance(),
                $io = $this->getIOMock(IOInterface::NORMAL),
                new ProcessExecutor,
            ])
            ->onlyMethods(['getListeners'])
            ->getMock();

        $code = 'exit 1';
        $listener = [$code];
        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listener));

        $io->expects([
            ['text' => '> exit 1'],
            ['text' => 'Script '.$code.' handling the post-install-cmd event returned with error code 1'],
        ], true);

        self::expectException(ScriptExecutionException::class);
        self::expectExceptionMessage('Error Output: ');
        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);
    }

    public function testDispatcherInstallerEvents(): void
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs([
                    $this->createComposerInstance(),
                    $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
                    $this->getProcessExecutorMock(),
                ])
            ->onlyMethods(['getListeners'])
            ->getMock();

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue([]));

        $transaction = $this->getMockBuilder('Composer\DependencyResolver\LockTransaction')->disableOriginalConstructor()->getMock();

        $dispatcher->dispatchInstallerEvent(InstallerEvents::PRE_OPERATIONS_EXEC, true, true, $transaction);
    }

    public function testDispatcherDoesntReturnSkippedScripts(): void
    {
        Platform::putEnv('COMPOSER_SKIP_SCRIPTS', 'scriptName');
        $composer = $this->createComposerInstance();

        $package = $this->getMockBuilder('Composer\Package\RootPackageInterface')->getMock();
        $package->method('getScripts')->will($this->returnValue(['scriptName' => ['scriptName']]));
        $composer->setPackage($package);

        $dispatcher = new EventDispatcher(
            $composer,
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $this->getProcessExecutorMock()
        );

        $event = $this->getMockBuilder('Composer\Script\Event')
            ->disableOriginalConstructor()
            ->getMock();
        $event->method('getName')->will($this->returnValue('scriptName'));

        $this->assertFalse($dispatcher->hasEventListeners($event));
    }

    public static function call(): void
    {
        throw new \RuntimeException();
    }

    /**
     * @return true
     */
    public static function someMethod(): bool
    {
        return true;
    }

    /**
     * @return true
     */
    public static function someMethod2(): bool
    {
        return true;
    }

    private function createComposerInstance(): Composer
    {
        $composer = new Composer;
        $config = new Config();
        $composer->setConfig($config);
        $package = $this->getMockBuilder('Composer\Package\RootPackageInterface')->getMock();
        $composer->setPackage($package);
        $composer->setRepositoryManager($rm = new RepositoryManager(new NullIO(), $config, new HttpDownloaderMock()));
        $rm->setLocalRepository(new InstalledArrayRepository([]));
        $composer->setAutoloadGenerator(new AutoloadGenerator(new EventDispatcher($composer, new NullIO())));
        $composer->setInstallationManager(new InstallationManagerMock());

        return $composer;
    }
}
