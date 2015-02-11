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
     * @var array Arguments passed by the user, these will be forwarded to CLI script handlers
     */
    protected $args;

    /**
     * @var array Flags usable in PHP script handlers
     */
    protected $flags;

    /**
     * @var boolean Whether the event should not be passed to more listeners
     */
    private $propagationStopped = false;

    /**
     * Constructor.
     *
     * @param string $name  The event name
     * @param array  $args  Arguments passed by the user
     * @param array  $flags Optional flags to pass data not as argument
     */
    public function __construct($name, array $args = array(), array $flags = array())
    {
        $this->name = $name;
        $this->args = $args;
        $this->flags = $flags;
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
     * Returns the event's arguments.
     *
     * @return array The event arguments
     */
    public function getArguments()
    {
        return $this->args;
    }

    /**
     * Returns the event's flags.
     *
     * @return array The event flags
     */
    public function getFlags()
    {
        return $this->flags;
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
