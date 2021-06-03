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

namespace Composer\EventDispatcher;

use Composer\DependencyResolver\Transaction;
use Composer\Installer\InstallerEvent;
use Composer\IO\IOInterface;
use Composer\Composer;
use Composer\Util\Platform;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Script;
use Composer\Installer\PackageEvent;
use Composer\Installer\BinaryInstaller;
use Composer\Util\ProcessExecutor;
use Composer\Script\Event as ScriptEvent;
use Composer\Autoload\ClassLoader;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The Event Dispatcher.
 *
 * Example in command:
 *     $dispatcher = new EventDispatcher($this->getComposer(), $this->getApplication()->getIO());
 *     // ...
 *     $dispatcher->dispatch(ScriptEvents::POST_INSTALL_CMD);
 *     // ...
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Nils Adermann <naderman@naderman.de>
 */
class EventDispatcher
{
    /** @var Composer */
    protected $composer;
    /** @var IOInterface */
    protected $io;
    /** @var ?ClassLoader */
    protected $loader;
    /** @var ProcessExecutor */
    protected $process;
    /** @var array<string, array<int, array<callable|string>>> */
    protected $listeners = array();
    /** @var list<string> */
    private $eventStack;

    /**
     * Constructor.
     *
     * @param Composer        $composer The composer instance
     * @param IOInterface     $io       The IOInterface instance
     * @param ProcessExecutor $process
     */
    public function __construct(Composer $composer, IOInterface $io, ProcessExecutor $process = null)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->process = $process ?: new ProcessExecutor($io);
        $this->eventStack = array();
    }

    /**
     * Dispatch an event
     *
     * @param  string $eventName An event name
     * @param  Event  $event
     * @return int    return code of the executed script if any, for php scripts a false return
     *                          value is changed to 1, anything else to 0
     */
    public function dispatch($eventName, Event $event = null)
    {
        if (null === $event) {
            $event = new Event($eventName);
        }

        return $this->doDispatch($event);
    }

    /**
     * Dispatch a script event.
     *
     * @param  string $eventName      The constant in ScriptEvents
     * @param  bool   $devMode
     * @param  array  $additionalArgs Arguments passed by the user
     * @param  array  $flags          Optional flags to pass data not as argument
     * @return int    return code of the executed script if any, for php scripts a false return
     *                               value is changed to 1, anything else to 0
     */
    public function dispatchScript($eventName, $devMode = false, $additionalArgs = array(), $flags = array())
    {
        return $this->doDispatch(new Script\Event($eventName, $this->composer, $this->io, $devMode, $additionalArgs, $flags));
    }

    /**
     * Dispatch a package event.
     *
     * @param string              $eventName  The constant in PackageEvents
     * @param bool                $devMode    Whether or not we are in dev mode
     * @param RepositoryInterface $localRepo  The installed repository
     * @param array               $operations The list of operations
     * @param OperationInterface  $operation  The package being installed/updated/removed
     *
     * @return int return code of the executed script if any, for php scripts a false return
     *             value is changed to 1, anything else to 0
     */
    public function dispatchPackageEvent($eventName, $devMode, RepositoryInterface $localRepo, array $operations, OperationInterface $operation)
    {
        return $this->doDispatch(new PackageEvent($eventName, $this->composer, $this->io, $devMode, $localRepo, $operations, $operation));
    }

    /**
     * Dispatch a installer event.
     *
     * @param string      $eventName         The constant in InstallerEvents
     * @param bool        $devMode           Whether or not we are in dev mode
     * @param bool        $executeOperations True if operations will be executed, false in --dry-run
     * @param Transaction $transaction       The transaction contains the list of operations
     *
     * @return int return code of the executed script if any, for php scripts a false return
     *             value is changed to 1, anything else to 0
     */
    public function dispatchInstallerEvent($eventName, $devMode, $executeOperations, Transaction $transaction)
    {
        return $this->doDispatch(new InstallerEvent($eventName, $this->composer, $this->io, $devMode, $executeOperations, $transaction));
    }

    /**
     * Triggers the listeners of an event.
     *
     * @param  Event                        $event The event object to pass to the event handlers/listeners.
     * @throws \RuntimeException|\Exception
     * @return int                          return code of the executed script if any, for php scripts a false return
     *                                            value is changed to 1, anything else to 0
     */
    protected function doDispatch(Event $event)
    {
        if (getenv('COMPOSER_DEBUG_EVENTS')) {
            $details = null;
            if ($event instanceof PackageEvent) {
                $details = (string) $event->getOperation();
            }
            $this->io->writeError('Dispatching <info>'.$event->getName().'</info>'.($details ? ' ('.$details.')' : '').' event');
        }

        $listeners = $this->getListeners($event);

        $this->pushEvent($event);

        try {
            $returnMax = 0;
            foreach ($listeners as $callable) {
                $return = 0;
                $this->ensureBinDirIsInPath();

                if (!is_string($callable)) {
                    if (!is_callable($callable)) {
                        $className = is_object($callable[0]) ? get_class($callable[0]) : $callable[0];

                        throw new \RuntimeException('Subscriber '.$className.'::'.$callable[1].' for event '.$event->getName().' is not callable, make sure the function is defined and public');
                    }
                    if (is_array($callable) && (is_string($callable[0]) || is_object($callable[0])) && is_string($callable[1])) {
                        $this->io->writeError(sprintf('> %s: %s', $event->getName(), (is_object($callable[0]) ? get_class($callable[0]) : $callable[0]).'->'.$callable[1]), true, IOInterface::VERBOSE);
                    }
                    $return = false === call_user_func($callable, $event) ? 1 : 0;
                } elseif ($this->isComposerScript($callable)) {
                    $this->io->writeError(sprintf('> %s: %s', $event->getName(), $callable), true, IOInterface::VERBOSE);

                    $script = explode(' ', substr($callable, 1));
                    $scriptName = $script[0];
                    unset($script[0]);

                    $args = array_merge($script, $event->getArguments());
                    $flags = $event->getFlags();
                    if (strpos($callable, '@composer ') === 0) {
                        $exec = $this->getPhpExecCommand() . ' ' . ProcessExecutor::escape(getenv('COMPOSER_BINARY')) . ' ' . implode(' ', $args);
                        if (0 !== ($exitCode = $this->executeTty($exec))) {
                            $this->io->writeError(sprintf('<error>Script %s handling the %s event returned with error code '.$exitCode.'</error>', $callable, $event->getName()), true, IOInterface::QUIET);

                            throw new ScriptExecutionException('Error Output: '.$this->process->getErrorOutput(), $exitCode);
                        }
                    } else {
                        if (!$this->getListeners(new Event($scriptName))) {
                            $this->io->writeError(sprintf('<warning>You made a reference to a non-existent script %s</warning>', $callable), true, IOInterface::QUIET);
                        }

                        try {
                            /** @var InstallerEvent $event */
                            $scriptEvent = new Script\Event($scriptName, $event->getComposer(), $event->getIO(), $event->isDevMode(), $args, $flags);
                            $scriptEvent->setOriginatingEvent($event);
                            $return = $this->dispatch($scriptName, $scriptEvent);
                        } catch (ScriptExecutionException $e) {
                            $this->io->writeError(sprintf('<error>Script %s was called via %s</error>', $callable, $event->getName()), true, IOInterface::QUIET);
                            throw $e;
                        }
                    }
                } elseif ($this->isPhpScript($callable)) {
                    $className = substr($callable, 0, strpos($callable, '::'));
                    $methodName = substr($callable, strpos($callable, '::') + 2);

                    if (!class_exists($className)) {
                        $this->io->writeError('<warning>Class '.$className.' is not autoloadable, can not call '.$event->getName().' script</warning>', true, IOInterface::QUIET);
                        continue;
                    }
                    if (!is_callable($callable)) {
                        $this->io->writeError('<warning>Method '.$callable.' is not callable, can not call '.$event->getName().' script</warning>', true, IOInterface::QUIET);
                        continue;
                    }

                    try {
                        $return = false === $this->executeEventPhpScript($className, $methodName, $event) ? 1 : 0;
                    } catch (\Exception $e) {
                        $message = "Script %s handling the %s event terminated with an exception";
                        $this->io->writeError('<error>'.sprintf($message, $callable, $event->getName()).'</error>', true, IOInterface::QUIET);
                        throw $e;
                    }
                } else {
                    $args = implode(' ', array_map(array('Composer\Util\ProcessExecutor', 'escape'), $event->getArguments()));
                    $exec = $callable . ($args === '' ? '' : ' '.$args);
                    if ($this->io->isVerbose()) {
                        $this->io->writeError(sprintf('> %s: %s', $event->getName(), $exec));
                    } elseif ($event->getName() !== '__exec_command') {
                        // do not output the command being run when using `composer exec` as it is fairly obvious the user is running it
                        $this->io->writeError(sprintf('> %s', $exec));
                    }

                    $possibleLocalBinaries = $this->composer->getPackage()->getBinaries();
                    if ($possibleLocalBinaries) {
                        foreach ($possibleLocalBinaries as $localExec) {
                            if (preg_match('{\b'.preg_quote($callable).'$}', $localExec)) {
                                $caller = BinaryInstaller::determineBinaryCaller($localExec);
                                $exec = preg_replace('{^'.preg_quote($callable).'}', $caller . ' ' . $localExec, $exec);
                                break;
                            }
                        }
                    }

                    if (strpos($exec, '@putenv ') === 0) {
                        if (false === strpos($exec, '=')) {
                            Platform::clearEnv(substr($exec, 8));
                        } else {
                            list($var, $value) = explode('=', substr($exec, 8), 2);
                            Platform::putEnv($var, $value);
                        }

                        continue;
                    }
                    if (strpos($exec, '@php ') === 0) {
                        $pathAndArgs = substr($exec, 5);
                        if (Platform::isWindows()) {
                            $pathAndArgs = preg_replace_callback('{^\S+}', function ($path) {
                                return str_replace('/', '\\', $path[0]);
                            }, $pathAndArgs);
                        }
                        // match somename (not in quote, and not a qualified path) and if it is not a valid path from CWD then try to find it
                        // in $PATH. This allows support for `@php foo` where foo is a binary name found in PATH but not an actual relative path
                        preg_match('{^[^\'"\s/\\\\]+}', $pathAndArgs, $match);
                        if (!file_exists($match[0])) {
                            $finder = new ExecutableFinder;
                            if ($pathToExec = $finder->find($match[0])) {
                                $pathAndArgs = $pathToExec . substr($pathAndArgs, strlen($match[0]));
                            }
                        }
                        $exec = $this->getPhpExecCommand() . ' ' . $pathAndArgs;
                    } else {
                        $finder = new PhpExecutableFinder();
                        $phpPath = $finder->find(false);
                        if ($phpPath) {
                            Platform::putEnv('PHP_BINARY', $phpPath);
                        }

                        if (Platform::isWindows()) {
                            $exec = preg_replace_callback('{^\S+}', function ($path) {
                                return str_replace('/', '\\', $path[0]);
                            }, $exec);
                        }
                    }

                    // if composer is being executed, make sure it runs the expected composer from current path
                    // resolution, even if bin-dir contains composer too because the project requires composer/composer
                    // see https://github.com/composer/composer/issues/8748
                    if (strpos($exec, 'composer ') === 0) {
                        $exec = $this->getPhpExecCommand() . ' ' . ProcessExecutor::escape(getenv('COMPOSER_BINARY')) . substr($exec, 8);
                    }

                    if (0 !== ($exitCode = $this->executeTty($exec))) {
                        $this->io->writeError(sprintf('<error>Script %s handling the %s event returned with error code '.$exitCode.'</error>', $callable, $event->getName()), true, IOInterface::QUIET);

                        throw new ScriptExecutionException('Error Output: '.$this->process->getErrorOutput(), $exitCode);
                    }
                }

                $returnMax = max($returnMax, $return);

                if ($event->isPropagationStopped()) {
                    break;
                }
            }
        } catch (\Exception $e) { // TODO Composer 2.2 turn all this into a finally
            $this->popEvent();

            throw $e;
        } catch (\Throwable $e) {
            $this->popEvent();

            throw $e;
        }

        $this->popEvent();

        return $returnMax;
    }

    protected function executeTty($exec)
    {
        if ($this->io->isInteractive()) {
            return $this->process->executeTty($exec);
        }

        return $this->process->execute($exec);
    }

    protected function getPhpExecCommand()
    {
        $finder = new PhpExecutableFinder();
        $phpPath = $finder->find(false);
        if (!$phpPath) {
            throw new \RuntimeException('Failed to locate PHP binary to execute '.$phpPath);
        }
        $phpArgs = $finder->findArguments();
        $phpArgs = $phpArgs ? ' ' . implode(' ', $phpArgs) : '';
        $allowUrlFOpenFlag = ' -d allow_url_fopen=' . ProcessExecutor::escape(ini_get('allow_url_fopen'));
        $disableFunctionsFlag = ' -d disable_functions=' . ProcessExecutor::escape(ini_get('disable_functions'));
        $memoryLimitFlag = ' -d memory_limit=' . ProcessExecutor::escape(ini_get('memory_limit'));

        return ProcessExecutor::escape($phpPath) . $phpArgs . $allowUrlFOpenFlag . $disableFunctionsFlag . $memoryLimitFlag;
    }

    /**
     * @param string $className
     * @param string $methodName
     * @param Event  $event      Event invoking the PHP callable
     */
    protected function executeEventPhpScript($className, $methodName, Event $event)
    {
        if ($this->io->isVerbose()) {
            $this->io->writeError(sprintf('> %s: %s::%s', $event->getName(), $className, $methodName));
        } else {
            $this->io->writeError(sprintf('> %s::%s', $className, $methodName));
        }

        return $className::$methodName($event);
    }

    /**
     * Add a listener for a particular event
     *
     * @param string   $eventName The event name - typically a constant
     * @param callable $listener  A callable expecting an event argument
     * @param int      $priority  A higher value represents a higher priority
     */
    public function addListener($eventName, $listener, $priority = 0)
    {
        $this->listeners[$eventName][$priority][] = $listener;
    }

    /**
     * @param callable|object $listener A callable or an object instance for which all listeners should be removed
     */
    public function removeListener($listener)
    {
        foreach ($this->listeners as $eventName => $priorities) {
            foreach ($priorities as $priority => $listeners) {
                foreach ($listeners as $index => $candidate) {
                    if ($listener === $candidate || (is_array($candidate) && is_object($listener) && $candidate[0] === $listener)) {
                        unset($this->listeners[$eventName][$priority][$index]);
                    }
                }
            }
        }
    }

    /**
     * Adds object methods as listeners for the events in getSubscribedEvents
     *
     * @see EventSubscriberInterface
     *
     * @param EventSubscriberInterface $subscriber
     */
    public function addSubscriber(EventSubscriberInterface $subscriber)
    {
        foreach ($subscriber->getSubscribedEvents() as $eventName => $params) {
            if (is_string($params)) {
                $this->addListener($eventName, array($subscriber, $params));
            } elseif (is_string($params[0])) {
                $this->addListener($eventName, array($subscriber, $params[0]), isset($params[1]) ? $params[1] : 0);
            } else {
                foreach ($params as $listener) {
                    $this->addListener($eventName, array($subscriber, $listener[0]), isset($listener[1]) ? $listener[1] : 0);
                }
            }
        }
    }

    /**
     * Retrieves all listeners for a given event
     *
     * @param  Event $event
     * @return array All listeners: callables and scripts
     */
    protected function getListeners(Event $event)
    {
        $scriptListeners = $this->getScriptListeners($event);

        if (!isset($this->listeners[$event->getName()][0])) {
            $this->listeners[$event->getName()][0] = array();
        }
        krsort($this->listeners[$event->getName()]);

        $listeners = $this->listeners;
        $listeners[$event->getName()][0] = array_merge($listeners[$event->getName()][0], $scriptListeners);

        return call_user_func_array('array_merge', $listeners[$event->getName()]);
    }

    /**
     * Checks if an event has listeners registered
     *
     * @param  Event $event
     * @return bool
     */
    public function hasEventListeners(Event $event)
    {
        $listeners = $this->getListeners($event);

        return count($listeners) > 0;
    }

    /**
     * Finds all listeners defined as scripts in the package
     *
     * @param  Event $event Event object
     * @return array Listeners
     */
    protected function getScriptListeners(Event $event)
    {
        $package = $this->composer->getPackage();
        $scripts = $package->getScripts();

        if (empty($scripts[$event->getName()])) {
            return array();
        }

        if ($this->loader) {
            $this->loader->unregister();
        }

        $generator = $this->composer->getAutoloadGenerator();
        if ($event instanceof ScriptEvent) {
            $generator->setDevMode($event->isDevMode());
        }

        $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();
        $packageMap = $generator->buildPackageMap($this->composer->getInstallationManager(), $package, $packages);
        $map = $generator->parseAutoloads($packageMap, $package);
        $this->loader = $generator->createLoader($map, $this->composer->getConfig()->get('vendor-dir'));
        $this->loader->register(false);

        return $scripts[$event->getName()];
    }

    /**
     * Checks if string given references a class path and method
     *
     * @param  string $callable
     * @return bool
     */
    protected function isPhpScript($callable)
    {
        return false === strpos($callable, ' ') && false !== strpos($callable, '::');
    }

    /**
     * Checks if string given references a composer run-script
     *
     * @param  string $callable
     * @return bool
     */
    protected function isComposerScript($callable)
    {
        return strpos($callable, '@') === 0 && strpos($callable, '@php ') !== 0 && strpos($callable, '@putenv ') !== 0;
    }

    /**
     * Push an event to the stack of active event
     *
     * @param  Event             $event
     * @throws \RuntimeException
     * @return int
     */
    protected function pushEvent(Event $event)
    {
        $eventName = $event->getName();
        if (in_array($eventName, $this->eventStack)) {
            throw new \RuntimeException(sprintf("Circular call to script handler '%s' detected", $eventName));
        }

        return array_push($this->eventStack, $eventName);
    }

    /**
     * Pops the active event from the stack
     *
     * @return mixed
     */
    protected function popEvent()
    {
        return array_pop($this->eventStack);
    }

    private function ensureBinDirIsInPath()
    {
        $pathStr = 'PATH';
        if (!isset($_SERVER[$pathStr]) && isset($_SERVER['Path'])) {
            $pathStr = 'Path';
        }

        // add the bin dir to the PATH to make local binaries of deps usable in scripts
        $binDir = $this->composer->getConfig()->get('bin-dir');
        if (is_dir($binDir)) {
            $binDir = realpath($binDir);
            if (isset($_SERVER[$pathStr]) && !preg_match('{(^|'.PATH_SEPARATOR.')'.preg_quote($binDir).'($|'.PATH_SEPARATOR.')}', $_SERVER[$pathStr])) {
                Platform::putEnv($pathStr, $binDir.PATH_SEPARATOR.getenv($pathStr));
            }
        }
    }
}
