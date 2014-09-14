<?php

namespace Composer\IO\WorkTracker\Formatter;

use Composer\IO\WorkTracker\FormatterInterface;
use Composer\IO\WorkTracker\WorkTrackerInterface;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\IO\WorkTracker\BoundWorkTracker;

class MultiProgressFormatter implements FormatterInterface
{
    protected $output;
    protected $progress = array();
    protected $lastMessagesLength = array();
    protected $lastMessageCount = 0;
    protected $depth = 4;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    public function create(WorkTrackerInterface $workTracker)
    {
        // $this->output->writeln(sprintf('<info>Starting: %s</info>', $workTracker->getTitle()));
    }

    public function complete(WorkTrackerInterface $workTracker)
    {
        // $this->output->writeln(sprintf('<comment>Finished: %s</comment>', $workTracker->getTitle()));
    }

    public function ping(WorkTrackerInterface $workTracker)
    {
        $out = array();
        while ($parent = $workTracker->getParent()) {

            if ($workTracker instanceof \Composer\IO\WorkTracker\BoundWorkTracker) {
                $out[] = sprintf('[%d/%d] %s', $workTracker->getPingCount(), $workTracker->getMax(), $workTracker->getTitle());
            } else {
                $out[] = sprintf('[%d/-] %s', $workTracker->getPingCount(), $workTracker->getTitle());
            }

            $workTracker = $parent;
        }

        $out = array_reverse($out);

        $this->overwrite($out);
    }

    public function log($message)
    {
        $this->output->writeln($message);
    }

    private function overwrite($messages)
    {
        if ($this->lastMessageCount) {
            echo "\033[" . $this->depth . "A";
        }

        for ($i = 0; $i < $this->depth; $i++) {

            if (isset($messages[$i])) {
                $message = $messages[$i];
            } else {
                $message = '';
            }

            $length = strlen($message);

            // append whitespace to match the last line's length
            if (isset($this->lastMessagesLength[$i]) && $this->lastMessagesLength[$i] > $length) {
                $message = str_pad($message, $this->lastMessagesLength[$i], "\x20", STR_PAD_RIGHT);
            }

            // carriage return
            $this->output->writeln($message);

            if (!$message) {
                unset($this->lastMessagesLength[$i]);
            } else {
                $this->lastMessagesLength[$i] = strlen($message);
            }
        }

        $this->lastMessageCount = count($messages);
    }
}
