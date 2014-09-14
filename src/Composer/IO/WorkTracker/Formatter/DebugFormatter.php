<?php

namespace Composer\IO\WorkTracker\Formatter;

use Composer\IO\WorkTracker\FormatterInterface;
use Composer\IO\WorkTracker\WorkTrackerInterface;

class DebugFormatter implements FormatterInterface
{
    public function create(WorkTrackerInterface $workTracker)
    {
        echo str_repeat('  ', $workTracker->getDepth()) . "BEGIN: " . $workTracker->getTitle(). "\n";
    }

    public function complete(WorkTrackerInterface $workTracker)
    {
        echo str_repeat('  ', $workTracker->getDepth()) . "DONE: " . $workTracker->getTitle() . "\n";
    }

    public function ping(WorkTrackerInterface $workTracker)
    {
        echo str_repeat('  ', $workTracker->getDepth()) . "PING: [#" . sprintf('%1$06d', $workTracker->getPingCount()) . "] [" . number_format($workTracker->getElapsedPingTime(), 4) . "s] " . $workTracker->getTitle()."\n";
    }

    public function log($message)
    {
        echo str_repeat('  ', $workTracker->getDepth()) . "LOG: " . $message . "\n";
    }
}
