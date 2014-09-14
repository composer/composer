<?php

namespace Composer\IO\WorkTracker;

use Composer\IO\WorkTracker\WorkTrackerInterface;
use Composer\IO\WorkTracker\FormatterInterface;

class ContextWorkTracker implements WorkTrackerInterface
{
    protected $workTracker;
    protected $formatter;

    public function __construct(WorkTrackerInterface $workTracker)
    {
        $this->workTracker = $workTracker;
    }

    public function getParent() 
    {
        return $this->workTracker->getParent();
    }

    public function getTitle()
    {
        return $this->workTracker->title;
    }

    public function createUnbound($title)
    {
        $this->workTracker = $this->workTracker->createUnbound($title);
        return $this->workTracker;
    }

    public function createBound($title, $max)
    {
        $this->workTracker = $this->workTracker->createBound($title, $max);
        return $this->workTracker;
    }

    public function complete()
    {
        $this->workTracker->complete();
        $this->workTracker = $this->workTracker->getParent();
    }

    public function ping()
    {
        $this->workTracker->ping();
    }

    public function getWorkTracker()
    {
        return $this->workTracker;
    }
}
