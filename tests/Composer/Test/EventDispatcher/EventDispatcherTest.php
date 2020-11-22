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

namespace Composer\Test\EventDispatcher;

use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Installer\InstallerEvents;
use Composer\Config;
use Composer\Composer;
use Composer\Test\TestCase;
use Composer\IO\BufferIO;
use Composer\Script\ScriptEvents;
use Composer\Script\Event as ScriptEvent;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Console\Output\OutputInterface;

class EventDispatcherTest extends TestCase
{
    public function testListenerExceptionsAreCaught()
    {
        $this->setExpectedException('RuntimeException');

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $dispatcher = $this->getDispatcherStubForListenersTest(array(
            'Composer\Test\EventDispatcher\EventDispatcherTest::call',
        ), $io);

        $io->expects($this->at(0))
            ->method('isVerbose')
            ->willReturn(0);

        $io->expects($this->at(1))
            ->method('writeError')
            ->with('> Composer\Test\EventDispatcher\EventDispatcherTest::call');

        $io->expects($this->at(2))
            ->method('writeError')
            ->with('<error>Script Composer\Test\EventDispatcher\EventDispatcherTest::call handling the post-install-cmd event terminated with an exception</error>');

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);
    }

    /**
     * @dataProvider getValidCommands
     * @param string $command
     */
    public function testDispatcherCanExecuteSingleCommandLineScript($command)
    {
        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $this->createComposerInstance(),
                $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
                $process,
            ))
            ->setMethods(array('getListeners'))
            ->getMock();

        $listener = array($command);
        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listener));

        $process->expects($this->once())
            ->method('execute')
            ->with($command)
            ->will($this->returnValue(0));

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);
    }

    /**
     * @dataProvider getDevModes
     * @param bool $devMode
     */
    public function testDispatcherPassDevModeToAutoloadGeneratorForScriptEvents($devMode)
    {
        $composer = $this->createComposerInstance();

        $generator = $this->getGeneratorMockForDevModePassingTest();
        $generator->expects($this->atLeastOnce())
            ->method('setDevMode')
            ->with($devMode);

        $composer->setAutoloadGenerator($generator);

        $package = $this->getMockBuilder('Composer\Package\RootPackageInterface')->getMock();
        $package->method('getScripts')->will($this->returnValue(array('scriptName' => array('scriptName'))));
        $composer->setPackage($package);

        $composer->setRepositoryManager($this->getRepositoryManagerMockForDevModePassingTest());
        $composer->setInstallationManager($this->getMockBuilder('Composer\Installer\InstallationManager')->disableOriginalConstructor()->getMock());

        $dispatcher = new EventDispatcher(
            $composer,
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock()
        );

        $event = $this->getMockBuilder('Composer\Script\Event')
            ->disableOriginalConstructor()
            ->getMock();
        $event->method('getName')->will($this->returnValue('scriptName'));
        $event->expects($this->atLeastOnce())
            ->method('isDevMode')
            ->will($this->returnValue($devMode));

        $dispatcher->hasEventListeners($event);
    }

    public function getDevModes()
    {
        return array(
            array(true),
            array(false),
        );
    }

    private function getGeneratorMockForDevModePassingTest()
    {
        $generator = $this->getMockBuilder('Composer\Autoload\AutoloadGenerator')
            ->disableOriginalConstructor()
            ->setMethods(array(
                'buildPackageMap',
                'parseAutoloads',
                'createLoader',
                'setDevMode',
            ))
            ->getMock();
        $generator
            ->method('buildPackageMap')
            ->will($this->returnValue(array()));
        $generator
            ->method('parseAutoloads')
            ->will($this->returnValue(array()));
        $generator
            ->method('createLoader')
            ->will($this->returnValue($this->getMockBuilder('Composer\Autoload\ClassLoader')->getMock()));

        return $generator;
    }

    private function getRepositoryManagerMockForDevModePassingTest()
    {
        $rm = $this->getMockBuilder('Composer\Repository\RepositoryManager')
            ->disableOriginalConstructor()
            ->setMethods(array('getLocalRepository'))
            ->getMock();

        $repo = $this->getMockBuilder('Composer\Repository\InstalledRepositoryInterface')->getMock();
        $repo
            ->method('getCanonicalPackages')
            ->will($this->returnValue(array()));

        $rm
            ->method('getLocalRepository')
            ->will($this->returnValue($repo));

        return $rm;
    }

    public function testDispatcherRemoveListener()
    {
        $composer = $this->createComposerInstance();

        $composer->setRepositoryManager($this->getRepositoryManagerMockForDevModePassingTest());
        $composer->setInstallationManager($this->getMockBuilder('Composer\Installer\InstallationManager')->disableOriginalConstructor()->getMock());

        $dispatcher = new EventDispatcher(
            $composer,
            $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
            $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock()
        );

        $listener = array($this, 'someMethod');
        $listener2 = array($this, 'someMethod2');
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
        $this->assertEquals($expected, $io->getOutput());

        $dispatcher->removeListener($this);
        $dispatcher->dispatch('ev1');
        $dispatcher->dispatch('ev2');

        $expected .= '> ev1: Composer\Test\EventDispatcher\EventDispatcherTest::someMethod'.PHP_EOL
            .'> ev2: Composer\Test\EventDispatcher\EventDispatcherTest::someMethod'.PHP_EOL;
        $this->assertEquals($expected, $io->getOutput());
    }

    public function testDispatcherCanExecuteCliAndPhpInSameEventScriptStack()
    {
        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $this->createComposerInstance(),
                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
                $process,
            ))
            ->setMethods(array(
                'getListeners',
            ))
            ->getMock();

        $process->expects($this->exactly(2))
            ->method('execute')
            ->will($this->returnValue(0));

        $listeners = array(
            'echo -n foo',
            'Composer\\Test\\EventDispatcher\\EventDispatcherTest::someMethod',
            'echo -n bar',
        );

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listeners));

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);

        $expected = '> post-install-cmd: echo -n foo'.PHP_EOL.
            '> post-install-cmd: Composer\Test\EventDispatcher\EventDispatcherTest::someMethod'.PHP_EOL.
            '> post-install-cmd: echo -n bar'.PHP_EOL;
        $this->assertEquals($expected, $io->getOutput());
    }

    public function testDispatcherCanPutEnv()
    {
        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $this->createComposerInstance(),
                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
                $process,
            ))
            ->setMethods(array(
                'getListeners',
            ))
            ->getMock();

        $listeners = array(
            '@putenv ABC=123',
            'Composer\\Test\\EventDispatcher\\EventDispatcherTest::getTestEnv',
        );

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listeners));

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);

        $expected = '> post-install-cmd: @putenv ABC=123'.PHP_EOL.
            '> post-install-cmd: Composer\Test\EventDispatcher\EventDispatcherTest::getTestEnv'.PHP_EOL;
        $this->assertEquals($expected, $io->getOutput());
    }

    public function testDispatcherAppendsDirBinOnPathForEveryListener()
    {
        $currentDirectoryBkp = getcwd();
        $composerBinDirBkp = getenv('COMPOSER_BIN_DIR');
        chdir(__DIR__);
        putenv('COMPOSER_BIN_DIR=' . __DIR__ . sprintf('%svendor%sbin', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR));

        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->setConstructorArgs(array(
                $this->createComposerInstance(),
                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
                $process,
            ))->setMethods(array(
                'getListeners',
            ))->getMock();

        $listeners = array(
            'Composer\\Test\\EventDispatcher\\EventDispatcherTest::createsVendorBinFolderChecksEnvDoesNotContainsBin',
            'Composer\\Test\\EventDispatcher\\EventDispatcherTest::createsVendorBinFolderChecksEnvContainsBin',
        );

        $dispatcher->expects($this->atLeastOnce())->method('getListeners')->will($this->returnValue($listeners));

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);
        rmdir(__DIR__ . sprintf('%svendor%sbin', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR));
        rmdir(__DIR__ . sprintf('%svendor', DIRECTORY_SEPARATOR));

        chdir($currentDirectoryBkp);
        putenv('COMPOSER_BIN_DIR' . ($composerBinDirBkp === false ? '' : '=' . $composerBinDirBkp));
    }

    public static function createsVendorBinFolderChecksEnvDoesNotContainsBin()
    {
        mkdir(__DIR__ . sprintf('%svendor%sbin', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR), 0700, true);
        $val = getenv('PATH');

        if (!$val) {
            $val = getenv('Path');
        }

        self::assertFalse(strpos($val, __DIR__ . sprintf('%svendor%sbin', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR)));
    }

    public static function createsVendorBinFolderChecksEnvContainsBin()
    {
        $val = getenv('PATH');

        if (!$val) {
            $val = getenv('Path');
        }

        self::assertNotFalse(strpos($val, __DIR__ . sprintf('%svendor%sbin', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR)));
    }

    public static function getTestEnv()
    {
        $val = getenv('ABC');
        if ($val !== '123') {
            throw new \Exception('getenv() did not return the expected value. expected 123 got '. var_export($val, true));
        }
    }

    public function testDispatcherCanExecuteComposerScriptGroups()
    {
        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $composer = $this->createComposerInstance(),
                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
                $process,
            ))
            ->setMethods(array(
                'getListeners',
            ))
            ->getMock();

        $process->expects($this->exactly(3))
            ->method('execute')
            ->will($this->returnValue(0));

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnCallback(function (Event $event) {
                if ($event->getName() === 'root') {
                    return array('@group');
                }

                if ($event->getName() === 'group') {
                    return array('echo -n foo', '@subgroup', 'echo -n bar');
                }

                if ($event->getName() === 'subgroup') {
                    return array('echo -n baz');
                }

                return array();
            }));

        $dispatcher->dispatch('root', new ScriptEvent('root', $composer, $io));
        $expected = '> root: @group'.PHP_EOL.
            '> group: echo -n foo'.PHP_EOL.
            '> group: @subgroup'.PHP_EOL.
            '> subgroup: echo -n baz'.PHP_EOL.
            '> group: echo -n bar'.PHP_EOL;
        $this->assertEquals($expected, $io->getOutput());
    }

    public function testRecursionInScriptsNames()
    {
        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $composer = $this->createComposerInstance(),
                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
                $process,
            ))
            ->setMethods(array(
                'getListeners',
            ))
            ->getMock();

        $process->expects($this->exactly(1))
            ->method('execute')
            ->will($this->returnValue(0));

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnCallback(function (Event $event) {
                if ($event->getName() === 'hello') {
                    return array('echo Hello');
                }

                if ($event->getName() === 'helloWorld') {
                    return array('@hello World');
                }

                return array();
            }));

        $dispatcher->dispatch('helloWorld', new ScriptEvent('helloWorld', $composer, $io));
        $expected = "> helloWorld: @hello World".PHP_EOL.
            "> hello: echo Hello " .escapeshellarg('World').PHP_EOL;

        $this->assertEquals($expected, $io->getOutput());
    }

    public function testDispatcherDetectInfiniteRecursion()
    {
        $this->setExpectedException('RuntimeException');

        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
        ->setConstructorArgs(array(
            $composer = $this->createComposerInstance(),
            $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $process,
        ))
        ->setMethods(array(
            'getListeners',
        ))
        ->getMock();

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnCallback(function (Event $event) {
                if ($event->getName() === 'root') {
                    return array('@recurse');
                }

                if ($event->getName() === 'recurse') {
                    return array('@root');
                }

                return array();
            }));

        $dispatcher->dispatch('root', new ScriptEvent('root', $composer, $io));
    }

    private function getDispatcherStubForListenersTest($listeners, $io)
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $this->createComposerInstance(),
                $io,
            ))
            ->setMethods(array('getListeners'))
            ->getMock();

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listeners));

        return $dispatcher;
    }

    public function getValidCommands()
    {
        return array(
            array('phpunit'),
            array('echo foo'),
            array('echo -n foo'),
        );
    }

    public function testDispatcherOutputsCommand()
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $this->createComposerInstance(),
                $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
                new ProcessExecutor($io),
            ))
            ->setMethods(array('getListeners'))
            ->getMock();

        $listener = array('echo foo');
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

    public function testDispatcherOutputsErrorOnFailedCommand()
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $this->createComposerInstance(),
                $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
                new ProcessExecutor,
            ))
            ->setMethods(array('getListeners'))
            ->getMock();

        $code = 'exit 1';
        $listener = array($code);
        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listener));

        $io->expects($this->at(0))
            ->method('isVerbose')
            ->willReturn(0);

        $io->expects($this->at(1))
            ->method('writeError')
            ->willReturn('> exit 1');

        $io->expects($this->at(2))
            ->method('isInteractive')
            ->willReturn(1);

        $io->expects($this->at(3))
            ->method('writeError')
            ->with($this->equalTo('<error>Script '.$code.' handling the post-install-cmd event returned with error code 1</error>'));

        $this->setExpectedException('RuntimeException');
        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);
    }

    public function testDispatcherInstallerEvents()
    {
        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                    $this->createComposerInstance(),
                    $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
                    $process,
                ))
            ->setMethods(array('getListeners'))
            ->getMock();

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue(array()));

        $transaction = $this->getMockBuilder('Composer\DependencyResolver\LockTransaction')->disableOriginalConstructor()->getMock();

        $dispatcher->dispatchInstallerEvent(InstallerEvents::PRE_OPERATIONS_EXEC, true, true, $transaction);
    }

    public static function call()
    {
        throw new \RuntimeException();
    }

    public static function someMethod()
    {
        return true;
    }

    public static function someMethod2()
    {
        return true;
    }

    private function createComposerInstance()
    {
        $composer = new Composer;
        $config = new Config;
        $composer->setConfig($config);
        $package = $this->getMockBuilder('Composer\Package\RootPackageInterface')->getMock();
        $composer->setPackage($package);

        return $composer;
    }
}
