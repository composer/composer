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

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Loop
{
    private $httpDownloader;
    private $processExecutor;
    private $currentPromises;

    public function __construct(HttpDownloader $httpDownloader = null, ProcessExecutor $processExecutor = null)
    {
        $this->httpDownloader = $httpDownloader;
        if ($this->httpDownloader) {
            $this->httpDownloader->enableAsync();
        }
        $this->processExecutor = $processExecutor;
        if ($this->processExecutor) {
            $this->processExecutor->enableAsync();
        }
    }

    public function wait(array $promises)
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

        while (true) {
            $hasActiveJob = false;

            if ($this->httpDownloader) {
                if ($this->httpDownloader->hasActiveJob()) {
                    $hasActiveJob = true;
                }
            }
            if ($this->processExecutor) {
                if ($this->processExecutor->hasActiveJob()) {
                    $hasActiveJob = true;
                }
            }

            if (!$hasActiveJob) {
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
