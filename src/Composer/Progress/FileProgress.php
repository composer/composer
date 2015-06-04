<?php

namespace Composer\Progress;

use Symfony\Component\Console\Output\OutputInterface;

class FileProgress implements ProgressInterface {

    /**
     * Section progress.
     *
     * @var int
     */

    protected $sectionCounter;

    /**
     * Item progress.
     *
     * @var int
     */

    protected $itemCounter;

    /**
     * OutputInterface instance.
     *
     * @var OutputInterface
     */

    protected $output;

    /**
     * Constructor.
     *
     * @param                 $filename
     * @param OutputInterface $output
     */
    public function __construct($filename, OutputInterface $output)  {
        $this->sectionCounter = 1;
        $this->itemCounter = 1;
        $this->progress = array(
            'progress' => array(
                'section'   => array('message' => 'Unknown', 'count'=> 0, 'total' => 0),
                'item'      => array('message' => 'Unknown', 'count'=> 0, 'total' => 0)
            )
        );
        $this->file = $filename;
        $this->output = $output;
        $this->persist();
    }

    /**
     * Starts a new progress 'section'.
     *
     * @param $message
     *
     * @return void
     */
    public function section($message) {
        $this->progress['progress']['section']['count'] = $this->sectionCounter++;
        $this->progress['progress']['section']['message'] = $message;
        $this->itemCounter = 0;
        $this->progress['progress']['item']['count'] = 0;
        $this->progress['progress']['item']['total'] = 1;
        $this->progress['progress']['item']['message'] = '';
        $this->output->writeln('[Section] ' . $message);
        $this->persist();
    }

    /**
     * Sets the total steps.
     *
     * @param $total
     * @param $type
     *
     * @return void
     */
    public function total($total, $type = 'item') {
        $this->progress['progress'][$type]['total'] = $total;
        $this->persist();
    }

    /**
     * Makes the progress bar indeterminate
     *
     * @return void
     */
    public function indeterminate() {
        $this->progress['progress']['item']['count'] = 1;
        $this->progress['progress']['item']['total'] = 1;
        $this->progress['progress']['item']['message'] = '';
        $this->persist();
    }

    /**
     * Stores progress information.
     *
     * @param string   $message
     * @param int|null $count
     * @return void
     */
    public function write($message, $count = null) {
        $count = $count === null ? $this->itemCounter++ : $count;
        $this->progress['progress']['item']['count'] = $count;
        $this->progress['progress']['item']['message'] = $message;
        $this->persist();
    }

    /**
     * Sends a notification to the client.
     *
     * @param string $message
     * @param string $status
     * @return void
     */
    public function notification($message, $status = 'success') {
        $this->progress['notification'] = array(
            'content' => $message,
            'status'  => $status,
            'unique'  => rand() // used to only display notifications if they are new
        );
        $this->persist();
    }

    /**
     * Asks that the client stops polling for new progress information.
     *
     * @return void
     */
    public function stopPolling() {
        $this->progress['stopPolling'] = true;
        $this->persist();
    }

    /**
     * Resets the progress information
     *
     * @return void
     */
    public function reset() {
        $this->progress['progress'] = false;
        $this->persist();
    }

    /**
     * Persists progress information to a file.
     *
     * @return void
     */

    protected function persist() {
        file_put_contents($this->file, json_encode($this->progress));
    }

}