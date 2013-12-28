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

/**
 * The base event class
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class Event
{
    /**
     * @var string This event's name
     */
    protected $name;

    /**
     * @var boolean Whether the event should not be passed to more listeners
     */
    private $propagationStopped = false;

    /**
     * Constructor.
     *
     * @param string $name The event name
     */
    public function __construct($name)
    {
        $this->name = $name;
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
     * Checks if stopPropagation has been called
     *
     * @return boolean Whether propagation has been stopped
     */
    public function isPropagationStopped()
    {
        return $this->propagationStopped;
    }

    /**
     * Prevents the event from being passed to further listeners
     */
    public function stopPropagation()
    {
        $this->propagationStopped = true;
    }
}
