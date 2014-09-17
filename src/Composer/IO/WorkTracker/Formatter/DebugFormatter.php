<?php

namespace Composer\IO\WorkTracker\Formatter;

use Composer\IO\WorkTracker\FormatterInterface;
use Composer\IO\WorkTracker\AbstractWorkTracker;
use Symfony\Component\Console\Output\OutputInterface;

class DebugFormatter implements FormatterInterface
{
    protected $output;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    public function create(AbstractWorkTracker $workTracker)
    {
        $this->formatMessage($workTracker, 'BEGIN', $workTracker->getTitle());
    }

    public function complete(AbstractWorkTracker $workTracker)
    {
        $this->formatMessage($workTracker, 'DONE', $workTracker->getTitle());
    }

    public function ping(AbstractWorkTracker $workTracker)
    {
        $this->formatMessage($workTracker, 'PING', sprintf(
            '[#%1$06d] [%ss] %s',
            $workTracker->getPingCount(),
            $workTracker->getElapsedPingTime(),
            $workTracker->getTitle()
        ));
    }

    private function formatMessage(AbstractWorkTracker $workTracker, $subject, $message)
    {
        $this->output->writeln(sprintf('%s%s: %s', str_repeat('  ', $workTracker->getDepth()), $subject, $message));
    }
}
