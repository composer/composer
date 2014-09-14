<?php

namespace Composer\IO\WorkTracker;

interface WorkTrackerInterface
{
    public function getTitle();

    public function createUnbound($title);

    public function createBound($title, $max);

    public function ping();

    public function complete();
}
