<?php declare(strict_types=1);

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
     * @var string[] Arguments passed by the user, these will be forwarded to CLI script handlers
     */
    protected $args;

    /**
     * @var mixed[] Flags usable in PHP script handlers
     */
    protected $flags;

    /**
     * @var bool Whether the event should not be passed to more listeners
     */
    private $propagationStopped = false;

    /**
     * Constructor.
     *
     * @param string   $name  The event name
     * @param string[] $args  Arguments passed by the user
     * @param mixed[]  $flags Optional flags to pass data not as argument
     */
    public function __construct(string $name, array $args = [], array $flags = [])
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
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the event's arguments.
     *
     * @return string[] The event arguments
     */
    public function getArguments(): array
    {
        return $this->args;
    }

    /**
     * Returns the event's flags.
     *
     * @return mixed[] The event flags
     */
    public function getFlags(): array
    {
        return $this->flags;
    }

    /**
     * Checks if stopPropagation has been called
     *
     * @return bool Whether propagation has been stopped
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * Prevents the event from being passed to further listeners
     *
     * @return void
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }
}
