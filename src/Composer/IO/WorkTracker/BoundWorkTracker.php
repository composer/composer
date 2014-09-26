<?php

namespace Composer\IO\WorkTracker;

/**
 * Work tracker representing a piece of work with a 
 * known number of steps
 *
 * @author Daniel Leech <daniel@dantleech.com>
 */
class BoundWorkTracker extends AbstractWorkTracker
{
    protected $max;

    /**
     * {@inheritDoc}
     *
     * @param integer $max Maximum number of steps
     */
    public function __construct($title, $formatter, WorkTrackerInterface $parent = null, $max)
    {
        parent::__construct($title, $formatter, $parent);
        $this->max = $max;
    }

    /**
     * Return the maximum number of steps that this
     * tracker can perform
     *
     * @return integer
     */
    public function getMax() 
    {
        return $this->max;
    }
    
}
