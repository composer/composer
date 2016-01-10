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

use Composer\DependencyResolver\PolicyInterface;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\Installer\InstallerEvent;
use Composer\IO\IOInterface;
use Composer\Composer;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\Repository\CompositeRepository;
use Composer\Script;
use Composer\Script\CommandEvent;
use Composer\Script\PackageEvent;
use Composer\Util\ProcessExecutor;

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
    protected $composer;
    protected $io;
    protected $loader;
    protected $process;
    protected $listeners;
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
        if (null == $event) {
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
     * @param string              $eventName     The constant in PackageEvents
     * @param bool                $devMode       Whether or not we are in dev mode
     * @param PolicyInterface     $policy        The policy
     * @param Pool                $pool          The pool
     * @param CompositeRepository $installedRepo The installed repository
     * @param Request             $request       The request
     * @param array               $operations    The list of operations
     * @param OperationInterface  $operation     The package being installed/updated/removed
     *
     * @return int return code of the executed script if any, for php scripts a false return
     *             value is changed to 1, anything else to 0
     */
    public function dispatchPackageEvent($eventName, $devMode, PolicyInterface $policy, Pool $pool, CompositeRepository $installedRepo, Request $request, array $operations, OperationInterface $operation)
    {
        return $this->doDispatch(new PackageEvent($eventName, $this->composer, $this->io, $devMode, $policy, $pool, $installedRepo, $request, $operations, $operation));
    }

    /**
     * Dispatch a installer event.
     *
     * @param string              $eventName     The constant in InstallerEvents
     * @param bool                $devMode       Whether or not we are in dev mode
     * @param PolicyInterface     $policy        The policy
     * @param Pool                $pool          The pool
     * @param CompositeRepository $installedRepo The installed repository
     * @param Request             $request       The request
     * @param array               $operations    The list of operations
     *
     * @return int return code of the executed script if any, for php scripts a false return
     *             value is changed to 1, anything else to 0
     */
    public function dispatchInstallerEvent($eventName, $devMode, PolicyInterface $policy, Pool $pool, CompositeRepository $installedRepo, Request $request, array $operations = array())
    {
        return $this->doDispatch(new InstallerEvent($eventName, $this->composer, $this->io, $devMode, $policy, $pool, $installedRepo, $request, $operations));
    }

    /**
     * Triggers the listeners of an event.
     *
     * @param  Event             $event          The event object to pass to the event handlers/listeners.
     * @param  string            $additionalArgs
     * @throws \RuntimeException
     * @throws \Exception
     * @return int               return code of the executed script if any, for php scripts a false return
     *                                          value is changed to 1, anything else to 0
     */
    protected function doDispatch(Event $event)
    {
        $listeners = $this->getListeners($event);

        $this->pushEvent($event);

        $return = 0;
        foreach ($listeners as $callable) {
            if (!is_string($callable) && is_callable($callable)) {
                $event = $this->checkListenerExpectedEvent($callable, $event);
                $return = false === call_user_func($callable, $event) ? 1 : 0;
            } elseif ($this->isComposerScript($callable)) {
                if ($this->io->isVerbose()) {
                    $this->io->writeError(sprintf('> %s: %s', $event->getName(), $callable));
                }
                $scriptName = substr($callable, 1);
                $args = $event->getArguments();
                $flags = $event->getFlags();
                $return = $this->dispatch($scriptName, new Script\Event($scriptName, $event->getComposer(), $event->getIO(), $event->isDevMode(), $args, $flags));
            } elseif ($this->isPhpScript($callable)) {
                $className = substr($callable, 0, strpos($callable, '::'));
                $methodName = substr($callable, strpos($callable, '::') + 2);

                if (!class_exists($className)) {
                    $this->io->writeError('<warning>Class '.$className.' is not autoloadable, can not call '.$event->getName().' script</warning>');
                    continue;
                }
                if (!is_callable($callable)) {
                    $this->io->writeError('<warning>Method '.$callable.' is not callable, can not call '.$event->getName().' script</warning>');
                    continue;
                }

                try {
                    $return = false === $this->executeEventPhpScript($className, $methodName, $event) ? 1 : 0;
                } catch (\Exception $e) {
                    $message = "Script %s handling the %s event terminated with an exception";
                    $this->io->writeError('<error>'.sprintf($message, $callable, $event->getName()).'</error>');
                    throw $e;
                }
            } else {
                $args = implode(' ', array_map(array('Composer\Util\ProcessExecutor', 'escape'), $event->getArguments()));
                $exec = $callable . ($args === '' ? '' : ' '.$args);
                if ($this->io->isVerbose()) {
                    $this->io->writeError(sprintf('> %s: %s', $event->getName(), $exec));
                } else {
                    $this->io->writeError(sprintf('> %s', $exec));
                }
                if (0 !== ($exitCode = $this->process->execute($exec))) {
                    $this->io->writeError(sprintf('<error>Script %s handling the %s event returned with an error</error>', $callable, $event->getName()));

                    throw new \RuntimeException('Error Output: '.$this->process->getErrorOutput(), $exitCode);
                }
            }

            if ($event->isPropagationStopped()) {
                break;
            }
        }

        $this->popEvent();

        return $return;
    }

    /**
     * @param string $className
     * @param string $methodName
     * @param Event  $event      Event invoking the PHP callable
     */
    protected function executeEventPhpScript($className, $methodName, Event $event)
    {
        $event = $this->checkListenerExpectedEvent(array($className, $methodName), $event);

        if ($this->io->isVerbose()) {
            $this->io->writeError(sprintf('> %s: %s::%s', $event->getName(), $className, $methodName));
        } else {
            $this->io->writeError(sprintf('> %s::%s', $className, $methodName));
        }

        return $className::$methodName($event);
    }

    /**
     * @param  mixed              $target
     * @param  Event              $event
     * @return Event|CommandEvent
     */
    protected function checkListenerExpectedEvent($target, Event $event)
    {
        try {
            $reflected = new \ReflectionParameter($target, 0);
        } catch (\Exception $e) {
            return $event;
        }

        $typehint = $reflected->getClass();

        if (!$typehint instanceof \ReflectionClass) {
            return $event;
        }

        $expected = $typehint->getName();

        // BC support
        if (!$event instanceof $expected && $expected === 'Composer\Script\CommandEvent') {
            $event = new \Composer\Script\CommandEvent(
                $event->getName(), $event->getComposer(), $event->getIO(), $event->isDevMode(), $event->getArguments()
            );
        }
        if (!$event instanceof $expected && $expected === 'Composer\Script\PackageEvent') {
            $event = new \Composer\Script\PackageEvent(
                $event->getName(), $event->getComposer(), $event->getIO(), $event->isDevMode(),
                $event->getPolicy(), $event->getPool(), $event->getInstalledRepo(), $event->getRequest(),
                $event->getOperations(), $event->getOperation()
            );
        }
        if (!$event instanceof $expected && $expected === 'Composer\Script\Event') {
            $event = new \Composer\Script\Event(
                $event->getName(), $event->getComposer(), $event->getIO(), $event->isDevMode(),
                $event->getArguments(), $event->getFlags()
            );
        }

        return $event;
    }

    /**
     * Add a listener for a particular event
     *
     * @param string   $eventName The event name - typically a constant
     * @param Callable $listener  A callable expecting an event argument
     * @param int      $priority  A higher value represents a higher priority
     */
    protected function addListener($eventName, $listener, $priority = 0)
    {
        $this->listeners[$eventName][$priority][] = $listener;
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
        $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();
        $packageMap = $generator->buildPackageMap($this->composer->getInstallationManager(), $package, $packages);
        $map = $generator->parseAutoloads($packageMap, $package);
        $this->loader = $generator->createLoader($map);
        $this->loader->register();

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
        return '@' === substr($callable, 0, 1);
    }

    /**
     * Push an event to the stack of active event
     *
     * @param  Event             $event
     * @throws \RuntimeException
     * @return number
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
}
