<?php

namespace Composer\IO\WorkTracker;

use Composer\IO\WorkTracker\WorkTrackerInterface;

interface FormatterInterface
{
    public function create(WorkTrackerInterface $workTracker);

    public function complete(WorkTrackerInterface $workTracker);

    public function ping(WorkTrackerInterface $workTracker);
}
