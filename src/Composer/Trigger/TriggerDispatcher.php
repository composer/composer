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

namespace Composer\Trigger;

use Composer\Json\JsonFile;
use Composer\Repository\FilesystemRepository;
use Composer\Autoload\ClassLoader;
use Composer\Package\PackageInterface;
use Composer\IO\IOInterface;
use Composer\Composer;

/**
 * The Trigger Dispatcher.
 *
 * Example in command:
 *     $dispatcher = new TriggerDispatcher($this->getComposer(), $this->getApplication()->getIO());
 *     // ...
 *     $dispatcher->dispatch(TriggerEvents::POST_INSTALL);
 *     // ...
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
class TriggerDispatcher
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
        $this->loader = new ClassLoader();
    }

    /**
     * Dispatch the event.
     *
     * @param string $eventName The constant in TriggerEvents
     */
    public function dispatch($eventName)
    {
        $event = new TriggerEvent();

        $event->setName($eventName);
        $event->setComposer($this->composer);
        $event->setIO($this->io);

        $this->doDispatch($event);
    }

    /**
     * Triggers the listeners of an event.
     *
     * @param TriggerEvent $event The event object to pass to the event handlers/listeners.
     */
    protected function doDispatch(TriggerEvent $event)
    {
        $listeners = $this->getListeners($event);

        foreach ($listeners as $method) {
            $className = substr($method, 0, strpos($method, '::'));
            $methodName = substr($method, strpos($method, '::') + 2);

            try {
                $refMethod = new \ReflectionMethod($className, $methodName);

                // execute only if all conditions are validates
                if ($refMethod->isPublic()
                        && $refMethod->isStatic()
                        && !$refMethod->isAbstract()
                        && 1 === $refMethod->getNumberOfParameters()) {
                    $className::$methodName($event);
                }

            } catch (\ReflectionException $ex) {}//silent execpetion
        }
    }

    /**
     * Register namespaces in ClassLoader.
     *
     * @param TriggerEvent $event The event object
     *
     * @return array The listener classes with event type
     */
    protected function getListeners(TriggerEvent $event)
    {
        $package = $this->composer->getPackage();
        $ex = $package->getExtra();
        $al = $package->getAutoload();
        $searchListeners = array();
        $searchNamespaces = array();
        $listeners = array();
        $namespaces = array();

        // get classes
        if (isset($ex['triggers'][$event->getName()])) {
            foreach ($ex['triggers'][$event->getName()] as $method) {
                $searchListeners[] = $method;
            }
        }

        // get namespaces
        if (isset($al['psr-0'])) {
            foreach ($al['psr-0'] as $ns => $path) {
                $path = trim(realpath('.') . '/' . $path, '/');
                $searchNamespaces[$ns] = $path;
            }
        }

        // filter class::method have not a namespace registered
        foreach ($searchNamespaces as $ns => $path) {
            foreach ($searchListeners as $method) {
                if (0 === strpos($method, $ns)) {
                    $listeners[] = $method;

                    if (!in_array($ns, array_keys($namespaces))) {
                        $namespaces[$ns] = $path;
                    }
                }
            }
        }

        // register namespaces in class loader
        foreach ($namespaces as $ns => $path) {
            $this->loader->add($ns, $path);
        }

        $this->loader->register();

        return $listeners;
    }
}
