<?php

namespace Composer\IO\WorkTracker;

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

    public function __construct($title, $parent = null, $formatter)
    {
        $this->title = $title;
        $this->parent = $parent;
        $this->lastPingTime = microtime(true);
        $this->formatter = $formatter;
    }

    public function getParent() 
    {
        return $this->parent;
    }
    
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

    public function getTitle()
    {
        return $this->title;
    }

    public function createUnbound($title)
    {
        $child = new UnboundWorkTracker($title, $this, $this->formatter);
        $this->formatter->create($child);
        return $child;
    }

    public function createBound($title, $max)
    {
        $child = new BoundWorkTracker($title, $this, $this->formatter, $max);
        $this->formatter->create($child);
        return $child;
    }

    public function complete()
    {
        $this->isComplete = true;
        $this->formatter->complete($this);
    }

    public function isComplete()
    {
        return $this->isComplete;
    }

    public function ping()
    {
        $this->pingCount++;
        $this->formatter->ping($this);
        $this->lastPingTime = microtime(true);
    }

    public function getElapsedPingTime()
    {
        $elapsed = microtime(true) - $this->lastPingTime;
        return $elapsed;
    }

    public function getPingCount()
    {
        return $this->pingCount;
    }
}
