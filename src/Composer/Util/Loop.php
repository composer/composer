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

    public function __construct(HttpDownloader $httpDownloader)
    {
        $this->httpDownloader = $httpDownloader;
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

        $this->httpDownloader->wait();

        if ($uncaught) {
            throw $uncaught;
        }
    }
}
