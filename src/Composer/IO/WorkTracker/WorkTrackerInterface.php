<?php

namespace Composer\IO\WorkTracker;

interface WorkTrackerInterface
{
    /**
     * Return the title of this tracker
     *
     * @return string
     */
    public function getTitle();

    /**
     * Create a new bound work tracker
     *
     * @param string $title
     * @param string $max Maximum number of steps
     *
     * @return BoundWorkTracker
     */
    public function createUnbound($title);

    /**
     * Create a new unbound work tracker
     *
     * @param string $title
     *
     * @return UnboundWorkTracker
     */
    public function createBound($title, $max);

    /**
     * Notify this tracker that a step has been completed
     */
    public function ping();

    /**
     * Complete this work
     */
    public function complete();

    /**
     * Return the parent work tracker if it is set.
     *
     * @reutrn WorkTrackerInterface
     */
    public function getParent();
}
