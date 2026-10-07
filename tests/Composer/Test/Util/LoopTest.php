<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Util;

use Composer\Test\TestCase;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Symfony\Component\Console\Helper\ProgressBar;

class LoopTest extends TestCase
{
    public function testWaitRemainsCompatibleWithExistingSubclasses(): void
    {
        $http = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
        $http->method('countActiveJobs')->willReturn(0);
        $loop = new CustomLoop($http);
        $failure = new \RuntimeException('Failed download');
        $this->expectExceptionObject($failure);

        $loop->wait([\React\Promise\reject($failure)]);
    }
}

class CustomLoop extends Loop
{
    /**
     * @param array<\React\Promise\PromiseInterface<mixed>> $promises
     */
    public function wait(array $promises, ?ProgressBar $progress = null): void
    {
        parent::wait($promises, $progress);
    }
}
