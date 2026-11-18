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

use Composer\Console\ProgramStatus;
use Composer\IO\ConsoleIO;
use Composer\Test\TestCase;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Composer\Util\Platform;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class LoopTest extends TestCase
{
    /** @dataProvider provideNonProgressStatuses */
    public function testProgressReportingDoesNotAbortAfterPluginChangesStatus(ProgramStatus $status): void
    {
        $originalSetting = Platform::getEnv('COMPOSER_TERMINAL_STATUS');
        Platform::putEnv('COMPOSER_TERMINAL_STATUS', '7501,9;4');
        try {
            $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
            $io = new ConsoleIO(new ArrayInput([]), $output, new HelperSet());
            $io->writeProgramStatus($status);
            $output->fetch();

            $downloader = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
            $downloader->method('countActiveJobs')->willReturnOnConsecutiveCalls(2, 1, 0);
            $loop = new Loop($downloader);
            $progress = new ProgressBar(new BufferedOutput());
            $loop->wait([], $progress, [$io, 'writeProgress']);
            $io->writeProgress(null);

            self::assertSame(2, $progress->getProgress());
            $written = $output->fetch();
            self::assertStringNotContainsString(':progress=', $written);
            self::assertStringEndsWith("\033]7501;state=".$status->getState().":app=composer\033\\", $written);
        } finally {
            if ($originalSetting === false) {
                Platform::clearEnv('COMPOSER_TERMINAL_STATUS');
            } else {
                Platform::putEnv('COMPOSER_TERMINAL_STATUS', $originalSetting);
            }
        }
    }

    public static function provideNonProgressStatuses(): array
    {
        return [[ProgramStatus::idle()], [ProgramStatus::done()], [ProgramStatus::error()], [ProgramStatus::clear()]];
    }

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
