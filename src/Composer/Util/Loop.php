<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Util;

use Composer\Util\HttpDownloader;
use React\Promise\Promise;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Loop
{
    /** @var HttpDownloader */
    private $httpDownloader;
    /** @var ProcessExecutor|null */
    private $processExecutor;
    /** @var Promise[]|null */
    private $currentPromises;

    public function __construct(HttpDownloader $httpDownloader, ProcessExecutor $processExecutor = null)
    {
        $this->httpDownloader = $httpDownloader;
        $this->httpDownloader->enableAsync();

        $this->processExecutor = $processExecutor;
        if ($this->processExecutor) {
            $this->processExecutor->enableAsync();
        }
    }

    /**
     * @return HttpDownloader
     */
    public function getHttpDownloader()
    {
        return $this->httpDownloader;
    }

    /**
     * @return ProcessExecutor|null
     */
    public function getProcessExecutor()
    {
        return $this->processExecutor;
    }

    public function wait(array $promises, ProgressBar $progress = null)
    {
        /** @var \Exception|null */
        $uncaught = null;

        \React\Promise\all($promises)->then(
            function () { },
            function ($e) use (&$uncaught) {
                $uncaught = $e;
            }
        );

        $this->currentPromises = $promises;

        if ($progress) {
            $totalJobs = 0;
            if ($this->httpDownloader) {
                $totalJobs += $this->httpDownloader->countActiveJobs();
            }
            if ($this->processExecutor) {
                $totalJobs += $this->processExecutor->countActiveJobs();
            }
            $progress->start($totalJobs);
        }

        while (true) {
            $activeJobs = 0;

            if ($this->httpDownloader) {
                $activeJobs += $this->httpDownloader->countActiveJobs();
            }
            if ($this->processExecutor) {
                $activeJobs += $this->processExecutor->countActiveJobs();
            }

            if ($progress) {
                $progress->setProgress($progress->getMaxSteps() - $activeJobs);
            }

            if (!$activeJobs) {
                break;
            }

            usleep(5000);
        }

        $this->currentPromises = null;
        if ($uncaught) {
            throw $uncaught;
        }
    }

    public function abortJobs()
    {
        if ($this->currentPromises) {
            foreach ($this->currentPromises as $promise) {
                $promise->cancel();
            }
        }
    }
}
