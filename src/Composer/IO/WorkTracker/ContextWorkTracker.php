<?php

namespace Composer\IO\WorkTracker;

use Composer\IO\WorkTracker\WorkTrackerInterface;
use Composer\IO\WorkTracker\FormatterInterface;

/**
 * The context work tracker is a proxy to the current work tracker
 *
 * Calling a create[Bound|Unbound] method will create a new work tracker
 * and set it as the current work tracker. Calling "complete" will place
 * the parent of the current work tracker as the current work tracker.
 *
 * @author Daniel Leech <daniel@dantleech.com>
 */
class ContextWorkTracker implements WorkTrackerInterface
{
    protected $workTracker;

    /**
     * @param WorkTrackerInterface $workTracker Initial work tracker
     */
    public function __construct(WorkTrackerInterface $workTracker)
    {
        $this->workTracker = $workTracker;
    }

    /**
     * {@inheritDoc}
     */
    public function getParent() 
    {
        return $this->workTracker->getParent();
    }

    /**
     * {@inheritdoc}
     */
    public function getTitle()
    {
        return $this->workTracker->title;
    }

    /**
     * {@inheritdoc}
     */
    public function createUnbound($title)
    {
        $this->workTracker = $this->workTracker->createUnbound($title);
        return $this->workTracker;
    }

    /**
     * {@inheritdoc}
     */
    public function createBound($title, $max)
    {
        $this->workTracker = $this->workTracker->createBound($title, $max);
        return $this->workTracker;
    }

    /**
     * {@inheritdoc}
     */
    public function complete()
    {
        $this->workTracker->complete();
        $this->workTracker = $this->workTracker->getParent();
    }

    /**
     * {@inheritdoc}
     */
    public function ping()
    {
        $this->workTracker->ping();
    }

    /**
     * Return the current work tracker
     *
     * @return WorkTrackerInterface
     */
    public function getWorkTracker()
    {
        return $this->workTracker;
    }
}
