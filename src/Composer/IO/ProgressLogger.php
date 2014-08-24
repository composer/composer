<?php

namespace Composer\IO;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressHelper;

class ProgressLogger
{
    const RESOLUTION = 100;
    const LEVEL_NORMAL = 0;
    const LEVEL_ERROR = 1;
    const LEVEL_DEBUG = 2;
    const LEVEL_VERY_VERBOSE = 3;

    protected $nbBatches;
    protected $progressBatch;
    protected $globalPercentDone = 0;
    protected $progressHelper;
    protected $output;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    public function push($description, $max)
    {
        $this->output->writeln('<comment>' . $description . '</comment>');
        return new ProgressSection($description, $max);
    }

    public function pushNoMax($description)
    {
        $this->output->writeln('<comment>' . $description . '</comment>');
        return new ProgressSection($description, null);
    }

    public function write($message, $level = self::LEVEL_NORMAL)
    {
        $this->output->writeln($message);
    }

    public function spin($threshold)
    {
        $this->progressBar->display();
    }
}
