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

use Composer\Console\Application;

/**
 * The Trigger Event.
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
class GetTriggerEvent
{
    /**
     * @var TriggerDispatcher Dispatcher that dispatched this event
     */
    private $dispatcher;

    /**
     * @var string This event's name
     */
    private $name;

    /**
     * @var Application The application instance
     */
    private $application;

    /**
     * Returns the TriggerDispatcher that dispatches this Event
     *
     * @return TriggerDispatcher
     */
    public function getDispatcher()
    {
        return $this->dispatcher;
    }

    /**
     * Stores the TriggerDispatcher that dispatches this Event
     *
     * @param TriggerDispatcher $dispatcher
     */
    public function setDispatcher(TriggerDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Returns the event's name.
     *
     * @return string The event name
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Stores the event's name.
     *
     * @param string $name The event name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * Returns the application instance.
     *
     * @return Application
     */
    public function getApplication()
    {
        return $this->application;
    }

    /**
     * Stores the application instance.
     *
     * @param Application $application
     */
    public function setApplication(Application $application)
    {
        $this->application = $application;
    }

    /**
     * Returns the composer instance.
     *
     * @return Composer
     */
    public function getComposer()
    {
        return $this->application->getComposer();
    }
}
