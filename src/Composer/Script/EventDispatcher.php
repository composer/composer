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

namespace Composer\Script;

use Composer\IO\IOInterface;
use Composer\Composer;
use Composer\DependencyResolver\Operation\OperationInterface;
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
 */
class EventDispatcher
{
    protected $composer;
    protected $io;
    protected $loader;
    protected $process;

    /**
     * The subscribers to certain events
     * @var array Keyed by the Event name, value = callback
     */
    protected $subscribers = array();

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
        $this->process = $process ?: new ProcessExecutor();
    }

    /**
     * Bind to an event with a callback.
     * @param string $eventName A constant from the ScriptEvents class
     * @param string|Callback $callback The callback.
     * @throws \RuntimeException When incorrect callback is provided
     */
    public function bind($eventName, $callback)
    {
        if(!is_callable($callback)) {
            throw new \RuntimeException('Someone tried to subscribe to ' . $eventName .
                ', but didn\'t provide a callable');
        }
        $this->subscribers[$eventName][] = $callback;
    }

    /**
     * Dispatch a script event.
     *
     * @param string $eventName The constant in ScriptEvents
     * @param Event  $event
     */
    public function dispatch($eventName, Event $event = null)
    {
        if (null == $event) {
            $event = new Event($eventName, $this->composer, $this->io);
        }

        $this->doDispatch($event);
    }

    /**
     * Dispatch a package event.
     *
     * @param string             $eventName The constant in ScriptEvents
     * @param boolean            $devMode   Whether or not we are in dev mode
     * @param OperationInterface $operation The package being installed/updated/removed
     */
    public function dispatchPackageEvent($eventName, $devMode, OperationInterface $operation)
    {
        $this->doDispatch(new PackageEvent($eventName, $this->composer, $this->io, $devMode, $operation));
    }

    /**
     * Dispatch a command event.
     *
     * @param string  $eventName The constant in ScriptEvents
     * @param boolean $devMode   Whether or not we are in dev mode
     */
    public function dispatchCommandEvent($eventName, $devMode)
    {
        $this->doDispatch(new CommandEvent($eventName, $this->composer, $this->io, $devMode));
    }

    /**
     * Triggers the listeners of an event.
     *
     * @param  Event             $event The event object to pass to the event handlers/listeners.
     * @throws \RuntimeException
     * @throws \Exception
     */
    protected function doDispatch(Event $event)
    {
        $listeners = $this->getListeners($event);

        foreach ($listeners as $callable) {
            if (is_callable($callable)) {
                try {
                  $this->executeCallable($callable, $event);
                } catch (\Exception $e) {
                    if($this->isStaticMethodCall($callable)) {
                        $message = "Script %s handling the %s event terminated with an exception";
                        $this->io->write('<error>'.sprintf($message, $callable, $event->getName()).'</error>');
                    } else {
                        $message = "Callable handling the %s event terminated with an exception";
                        $this->io->write('<error>'.sprintf($message, $event->getName()).'</error>');
                    }
                    throw $e;
                }
            } else {
                if (0 !== ($exitCode = $this->process->execute($callable))) {
                    $event->getIO()->write(sprintf('<error>Script %s handling the %s event returned with an error</error>', $callable, $event->getName()));

                    throw new \RuntimeException('Error Output: '.$this->process->getErrorOutput(), $exitCode);
                }
            }
        }
    }

    /**
     * @param callable $callable
     * @param Event  $event      Event invoking the PHP callable
     */
    protected function executeCallable($callable, Event $event)
    {
        call_user_func($callable, $event);
    }

    /**
     * @param  Event $event Event object
     * @return array Listeners
     */
    protected function getListeners(Event $event)
    {
        $listeners = isset($this->subscribers[$event->getName()]) ?
            $this->subscribers[$event->getName()] : array();
        $package = $this->composer->getPackage();
        $scripts = $package->getScripts();

        if (empty($scripts[$event->getName()])) {
            return $listeners;
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

        return array_merge($listeners, $scripts[$event->getName()]);
    }

    /**
     * Checks if string given references a class path and method
     *
     * @param  string  $callable
     * @return boolean
     */
    protected function isStaticMethodCall($callable)
    {
        return is_string($callable) && false === strpos($callable, ' ') && false !== strpos($callable, '::');
    }

}
