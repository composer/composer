<?php

namespace Composer\IO\WorkTracker;

use Composer\IO\WorkTracker\FormatterInterface;
use Composer\IO\WorkTracker\WorkTrackerInterface;

/**
 * Class which monitors the progress
 *
 * @author Daniel Leech <daniel@dantleech.com>
 */
abstract class AbstractWorkTracker implements WorkTrackerInterface
{
    protected $title;
    protected $parent;
    protected $pingCount = 0;
    protected $lastPingTime;
    protected $formatter;
    protected $isComplete = false;

    /**
     * @param string $title Title of the piece of work which should be tracked
     * @param FormatterInterface Output formatter to use
     * @param WorkTrackerInterface $parent Parent of the tracker, unless this is the root tracker
     */
    public function __construct($title, FormatterInterface $formatter, WorkTrackerInterface $parent = null)
    {
        $this->title = $title;
        $this->parent = $parent;
        $this->formatter = $formatter;

        // record the time that this work tracker was created
        $this->lastPingTime = microtime(true);
    }

    /**
     * {@inheritDoc}
     */
    public function getParent() 
    {
        return $this->parent;
    }

    /**
     * {@inheritDoc}
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * {@inheritDoc}
     */
    public function createUnbound($title)
    {
        $child = new UnboundWorkTracker($title, $this->formatter, $this);
        $this->formatter->create($child);
        return $child;
    }

    /**
     * {@inheritDoc}
     */
    public function createBound($title, $max)
    {
        $child = new BoundWorkTracker($title, $this->formatter, $this, $max);
        $this->formatter->create($child);
        return $child;
    }

    /**
     * {@inheritDoc}
     */
    public function complete()
    {
        $this->isComplete = true;
        $this->formatter->complete($this);
    }

    /**
     * {@inheritDoc}
     */
    public function ping()
    {
        $this->pingCount++;
        $this->formatter->ping($this);
        $this->lastPingTime = microtime(true);
    }

    /**
     * Return true if the work has been completed
     *
     * @return boolean
     */
    public function isComplete()
    {
        return $this->isComplete;
    }

    /**
     * Return the time elapsed (in microseconds) since the
     * tracker was instantiated
     *
     * @return float
     */
    public function getElapsedPingTime()
    {
        $elapsed = microtime(true) - $this->lastPingTime;
        return $elapsed;
    }

    /**
     * Return the number of times this tracker has been "pinged"
     *
     * @return integer
     */
    public function getPingCount()
    {
        return $this->pingCount;
    }

    /**
     * Return the nested depth of this work tracker
     *
     * @return integer
     */
    public function getDepth()
    {
        $current = $this;
        $depth = 0;
        while ($parent = $current->getParent()) {
            $depth++;
            $current = $parent;
        }

        return $depth;
    }
}
