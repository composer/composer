<?php

namespace Composer\IO\WorkTracker;

use Composer\IO\WorkTracker\AbstractWorkTracker;

/**
 * Interface for progress output formatters
 *
 * @author Daniel Leech <daniel@dantleech.com>
 */
interface FormatterInterface
{
    /**
     * Called when work tracker is created
     *
     * @param AbstractWorkTracker
     */
    public function create(AbstractWorkTracker $workTracker);

    /**
     * Called when work tracker is completed
     *
     * @param AbstractWorkTracker
     */
    public function complete(AbstractWorkTracker $workTracker);

    /**
     * Called when the work tracker is "pinged" (notified of
     * some progress).
     *
     * @param AbstractWorkTracker $workTracker
     */
    public function ping(AbstractWorkTracker $workTracker);
}
