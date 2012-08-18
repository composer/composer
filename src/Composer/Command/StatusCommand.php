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

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Downloader\VcsDownloader;

/**
 * @author Tiago Ribeiro <tiago.ribeiro@seegno.com>
 * @author Rui Marinho <rui.marinho@seegno.com>
 */
class StatusCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('status')
            ->setDescription('Show a list of locally modified packages')
            ->setHelp(<<<EOT
The status command displays a list of packages that have
been modified locally.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // init repos
        $composer = $this->getComposer();
        $installedRepo = $composer->getRepositoryManager()->getLocalRepository();

        $dm = $composer->getDownloadManager();
        $im = $composer->getInstallationManager();

        $errors = array();

        // list packages
        foreach ($installedRepo->getPackages() as $package) {
            $downloader = $dm->getDownloaderForInstalledPackage($package);

            if ($downloader instanceof VcsDownloader) {
                $targetDir = $im->getInstallPath($package);

                if ($downloader->hasLocalChanges($targetDir)) {
                    $errors[] = $targetDir;
                }
            }
        }

        // output errors/warnings
        if (!$errors) {
            $output->writeln('<info>No local changes</info>');
        } else {
            $output->writeln('<error>You have changes in the following packages:</error>');
        }

        foreach ($errors as $error) {
            $output->writeln($error);
        }

        return $errors ? 1 : 0;
    }
}
