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
use Composer\Console\Application;
use Composer\Composer;

/**
 * The Trigger Dispatcher.
 *
 * Example in command:
 *     $dispatcher = new TriggerDispatcher($this->getApplication());
 *     // ...
 *     $dispatcher->dispatch(TriggerEvents::PRE_INSTALL);
 *     // ...
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
class TriggerDispatcher
{
    protected $application;
    protected $loader;

    /**
     * Constructor.
     *
     * @param Application $application
     */
    public function __construct(Application $application)
    {
        $this->application = $application;
        $this->loader = new ClassLoader();
    }

    /**
     * Dispatch the event.
     *
     * @param string $eventName The constant in TriggerEvents
     */
    public function dispatch($eventName)
    {
        $event = new GetTriggerEvent();

        $event->setDispatcher($this);
        $event->setName($eventName);
        $event->setApplication($this->application);

        $this->doDispatch($event);
    }

    /**
     * Triggers the listeners of an event.
     *
     * @param GetTriggerEvent $event The event object to pass to the event handlers/listeners.
     */
    protected function doDispatch(GetTriggerEvent $event)
    {
        $listeners = $this->getListeners($event);

        foreach ($listeners as $method => $eventType) {
            if ($eventType === $event->getName()) {
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
    }

    /**
     * Register namespaces in ClassLoader.
     *
     * @param GetTriggerEvent $event The event object
     *
     * @return array The listener classes with event type
     */
    protected function getListeners(GetTriggerEvent $event)
    {
        $listeners = array();
        $composer = $this->application->getComposer();
        $vendorDir = $composer->getInstallationManager()->getVendorPath(true);
        $installedFile = $vendorDir . '/.composer/installed.json';

        // get the list of package installed
        // $composer->getRepositoryManager()->getLocalRepository() not used
        // because the list is not refreshed for the post event
        $fsr = new FilesystemRepository(new JsonFile($installedFile));
        $packages = $fsr->getPackages();

        foreach ($packages as $package) {
            $listeners = array_merge_recursive($listeners, $this->getListenerClasses($package));
        }

        // add root package
        $listeners = array_merge_recursive($listeners, $this->getListenerClasses($composer->getPackage(), true));

        return $listeners;
    }

    /**
     * Get listeners and register the namespace on Classloader.
     *
     * @param PackageInterface $package The package objet
     * @param boolean $root             For root composer
     *
     * @return array The listener classes with event type
     */
    private function getListenerClasses(PackageInterface $package, $root = false)
    {
        $composer = $this->application->getComposer();
        $installDir = $composer->getInstallationManager()->getVendorPath(true)
                        . '/' . $package->getName();
        $ex = $package->getExtra();
        $al = $package->getAutoload();
        $searchListeners = array();
        $searchNamespaces = array();
        $listeners = array();
        $namespaces = array();

        // get classes
        if (isset($ex['triggers'])) {
            foreach ($ex['triggers'] as $method => $event) {
                $searchListeners[$method] = $event;
            }
        }

        // get namespaces
        if (isset($al['psr-0'])) {
            foreach ($al['psr-0'] as $ns => $path) {
                $dir = $root ? realpath('.') : $installDir;

                $path = trim($dir . '/' . $path, '/');
                $searchNamespaces[$ns] = $path;
            }
        }

        // filter class::method have not a namespace registered
        foreach ($searchNamespaces as $ns => $path) {
            foreach ($searchListeners as $method => $event) {
                if (0 === strpos($method, $ns)) {
                    $listeners[$method] = $event;

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
