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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Downloader\ChangeReportInterface;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Script\ScriptEvents;
use Composer\Downloader\DvcsDownloaderInterface;

/**
 * @author Tiago Ribeiro <tiago.ribeiro@seegno.com>
 * @author Rui Marinho <rui.marinho@seegno.com>
 */
class StatusCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('status')
            ->setDescription('Show a list of locally modified packages')
            ->setDefinition(array(
                new InputOption('verbose', 'v|vv|vvv', InputOption::VALUE_NONE, 'Show modified files for each directory that contains changes.'),
            ))
            ->setHelp(<<<EOT
The status command displays a list of dependencies that have
been modified locally.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // init repos
        $composer = $this->getComposer();

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'status', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $installedRepo = $composer->getRepositoryManager()->getLocalRepository();

        $dm = $composer->getDownloadManager();
        $im = $composer->getInstallationManager();

        // Dispatch pre-status-command
        $composer->getEventDispatcher()->dispatchScript(ScriptEvents::PRE_STATUS_CMD, true);

        $errors = array();
        $io = $this->getIO();
        $unpushedChanges = array();

        // list packages
        foreach ($installedRepo->getCanonicalPackages() as $package) {
            $downloader = $dm->getDownloaderForInstalledPackage($package);

            if ($downloader instanceof ChangeReportInterface) {
                $targetDir = $im->getInstallPath($package);

                if (is_link($targetDir)) {
                    $errors[$targetDir] = $targetDir . ' is a symbolic link.';
                }

                if ($changes = $downloader->getLocalChanges($package, $targetDir)) {
                    $errors[$targetDir] = $changes;
                }

                if ($downloader instanceof DvcsDownloaderInterface) {
                    if ($unpushed = $downloader->getUnpushedChanges($package, $targetDir)) {
                        $unpushedChanges[$targetDir] = $unpushed;
                    }
                }
            }
        }

        // output errors/warnings
        if (!$errors && !$unpushedChanges) {
            $io->writeError('<info>No local changes</info>');
        } elseif ($errors) {
            $io->writeError('<error>You have changes in the following dependencies:</error>');
        }

        foreach ($errors as $path => $changes) {
            if ($input->getOption('verbose')) {
                $indentedChanges = implode("\n", array_map(function ($line) {
                    return '    ' . ltrim($line);
                }, explode("\n", $changes)));
                $io->write('<info>'.$path.'</info>:');
                $io->write($indentedChanges);
            } else {
                $io->write($path);
            }
        }

        if ($unpushedChanges) {
            $io->writeError('<warning>You have unpushed changes on the current branch in the following dependencies:</warning>');

            foreach ($unpushedChanges as $path => $changes) {
                if ($input->getOption('verbose')) {
                    $indentedChanges = implode("\n", array_map(function ($line) {
                        return '    ' . ltrim($line);
                    }, explode("\n", $changes)));
                    $io->write('<info>'.$path.'</info>:');
                    $io->write($indentedChanges);
                } else {
                    $io->write($path);
                }
            }
        }

        if (($errors || $unpushedChanges) && !$input->getOption('verbose')) {
            $io->writeError('Use --verbose (-v) to see a list of files');
        }

        // Dispatch post-status-command
        $composer->getEventDispatcher()->dispatchScript(ScriptEvents::POST_STATUS_CMD, true);

        return ($errors ? 1 : 0) + ($unpushedChanges ? 2 : 0);
    }
}
