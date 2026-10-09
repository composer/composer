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
use Symfony\Component\Console\Output\BufferedOutput;

class LoopTest extends TestCase
{
    public function testReportsProgressAndFinalCompletion(): void
    {
        $downloader = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
        $downloader->method('countActiveJobs')->willReturnOnConsecutiveCalls(3, 2, 0);
        $loop = new Loop($downloader);
        $percentages = [];
        $loop->wait([], new ProgressBar(new BufferedOutput()), static function (int $percent) use (&$percentages): void {
            $percentages[] = $percent;
        });
        self::assertSame(33, $percentages[0]);
        self::assertSame(100, $percentages[count($percentages) - 1]);
    }

    public function testDoesNotReportProgressWithoutProgressBar(): void
    {
        $downloader = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
        $downloader->expects($this->once())->method('countActiveJobs')->willReturn(0);
        $loop = new Loop($downloader);
        $loop->wait([], null, static function (): void {
            self::fail('Progress is disabled');
        });
    }
}
