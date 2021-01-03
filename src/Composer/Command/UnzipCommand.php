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

namespace Composer\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Downloader\ChangeReportInterface;
use Composer\Downloader\DvcsDownloaderInterface;
use Composer\Downloader\VcsCapableDownloaderInterface;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Version\VersionGuesser;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Script\ScriptEvents;
use Composer\Util\ProcessExecutor;
use ZipArchive;

/**
 * @author Tiago Ribeiro <tiago.ribeiro@seegno.com>
 * @author Rui Marinho <rui.marinho@seegno.com>
 */
class UnzipCommand extends BaseCommand
{
    const EXIT_ERROR_WHILE_EXTRACT = 253;
    const EXIT_MAYBE_SAME_FILE_DIFFERENT_CAPS = 252;

    /**
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure()
    {
        $this
            ->setName('unzip')
            ->setDescription('Unzip the given archive.')
            ->setDefinition(array(
                new InputArgument('archive', InputArgument::REQUIRED, 'Path to archive to be unzipped'),
                new InputArgument('destination', InputArgument::REQUIRED, 'Directory to extract into'),
            ))
        ;
    }

    /**
     * @param  InputInterface  $input
     * @param  OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $file = $input->getArgument('archive');
        $path = $input->getArgument('destination');

        $zipArchive = new ZipArchive();

        try {
            if (true === ($retval = $zipArchive->open($file))) {
                $extractResult = $zipArchive->extractTo($path);

                if (true === $extractResult) {
                    $zipArchive->close();
                } else {
                    exit(self::EXIT_ERROR_WHILE_EXTRACT);
                }
            } else {
                // exit with the one of the ZipArchive::ER* errors
                exit($retval);
            }
        } catch (\ErrorException $e) {
            $this->getIO()->writeError($e->getMessage());
            exit(self::EXIT_MAYBE_SAME_FILE_DIFFERENT_CAPS);
        }

        return 0;
    }
}
