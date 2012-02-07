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

use Composer\Json\JsonFile;
use Composer\Repository\FilesystemRepository;
use Composer\Autoload\AutoloadGenerator;
use Composer\Package\PackageInterface;
use Composer\IO\IOInterface;
use Composer\Composer;
use Composer\DependencyResolver\Operation\OperationInterface;

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

    /**
     * Constructor.
     *
     * @param Composer    $composer The composer instance
     * @param IOInterface $io       The IOInterface instance
     */
    public function __construct(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * Dispatch a package event.
     *
     * @param string $eventName The constant in ScriptEvents
     * @param OperationInterface $operation The package being installed/updated/removed
     */
    public function dispatchPackageEvent($eventName, OperationInterface $operation)
    {
        $this->doDispatch(new PackageEvent($eventName, $this->composer, $this->io, $operation));
    }

    /**
     * Dispatch a command event.
     *
     * @param string $eventName The constant in ScriptEvents
     */
    public function dispatchCommandEvent($eventName)
    {
        $this->doDispatch(new CommandEvent($eventName, $this->composer, $this->io));
    }

    /**
     * Triggers the listeners of an event.
     *
     * @param Event $event The event object to pass to the event handlers/listeners.
     */
    protected function doDispatch(Event $event)
    {
        $listeners = $this->getListeners($event);

        foreach ($listeners as $callable) {
            $className = substr($callable, 0, strpos($callable, '::'));
            $methodName = substr($callable, strpos($callable, '::') + 2);

            if (!class_exists($className)) {
                throw new \UnexpectedValueException('Class '.$className.' is not autoloadable, can not call '.$event->getName().' script');
            }
            if (!is_callable($callable)) {
                throw new \UnexpectedValueException('Method '.$callable.' is not callable, can not call '.$event->getName().' script');
            }

            $className::$methodName($event);
        }
    }

    /**
     * @param Event $event Event object
     * @return array Listeners
     */
    protected function getListeners(Event $event)
    {
        $package = $this->composer->getPackage();
        $scripts = $package->getScripts();

        if (empty($scripts[$event->getName()])) {
            return array();
        }

        if ($this->loader) {
            $this->loader->unregister();
        }

        $generator = new AutoloadGenerator;
        $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
        $packageMap = $generator->buildPackageMap($this->composer->getInstallationManager(), $package, $packages);
        $map = $generator->parseAutoloads($packageMap);
        $this->loader = $generator->createLoader($map);
        $this->loader->register();

        return $scripts[$event->getName()];
    }
}
