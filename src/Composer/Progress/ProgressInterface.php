<?php

namespace Composer\Progress;

interface ProgressInterface {

    /**
     * Starts a new progress 'section'.
     *
     * @param $message
     *
     * @return void
     */

    public function section($message);

    /**
     * Sets the total steps.
     *
     * @param $total
     * @param $type
     *
     * @return void
     */

    public function total($total, $type = 'item');

    /**
     * Stores progress information.
     *
     * @param $message
     * @param $count
     *
     * @return void
     */

    public function write($message, $count = null);

    /**
     * Makes the progress bar indeterminate
     *
     * @return void
     */

    public function indeterminate();

    /**
     * Sends a notification to the client.
     *
     * @param string $message
     * @param string $status
     * @return void
     */

    public function notification($message, $status = 'success');

    /**
     * Asks that the client stops polling for new progress information.
     *
     * @return void
     */

    public function stopPolling();

    /**
     * Resets the progress information
     *
     * @return void
     */

    public function reset();

}