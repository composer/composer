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

namespace Composer\Command;

use Symfony\Component\Console\Input\InputInterface;
use Composer\Console\Input\InputOption;
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

/**
 * @author Tiago Ribeiro <tiago.ribeiro@seegno.com>
 * @author Rui Marinho <rui.marinho@seegno.com>
 */
class StatusCommand extends BaseCommand
{
    private const EXIT_CODE_ERRORS = 1;
    private const EXIT_CODE_UNPUSHED_CHANGES = 2;
    private const EXIT_CODE_VERSION_CHANGES = 4;

    /**
     * @return void
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure(): void
    {
        $this
            ->setName('status')
            ->setDescription('Shows a list of locally modified packages.')
            ->setDefinition(array(
                new InputOption('verbose', 'v|vv|vvv', InputOption::VALUE_NONE, 'Show modified files for each directory that contains changes.'),
            ))
            ->setHelp(
                <<<EOT
The status command displays a list of dependencies that have
been modified locally.

Read more at https://getcomposer.org/doc/03-cli.md#status
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'status', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        // Dispatch pre-status-command
        $composer->getEventDispatcher()->dispatchScript(ScriptEvents::PRE_STATUS_CMD, true);

        $exitCode = $this->doExecute($input);

        // Dispatch post-status-command
        $composer->getEventDispatcher()->dispatchScript(ScriptEvents::POST_STATUS_CMD, true);

        return $exitCode;
    }

    /**
     * @return int
     */
    private function doExecute(InputInterface $input): int
    {
        // init repos
        $composer = $this->requireComposer();

        $installedRepo = $composer->getRepositoryManager()->getLocalRepository();

        $dm = $composer->getDownloadManager();
        $im = $composer->getInstallationManager();

        $errors = array();
        $io = $this->getIO();
        $unpushedChanges = array();
        $vcsVersionChanges = array();

        $parser = new VersionParser;
        $guesser = new VersionGuesser($composer->getConfig(), $composer->getLoop()->getProcessExecutor() ?? new ProcessExecutor($io), $parser);
        $dumper = new ArrayDumper;

        // list packages
        foreach ($installedRepo->getCanonicalPackages() as $package) {
            $downloader = $dm->getDownloaderForPackage($package);
            $targetDir = $im->getInstallPath($package);

            if ($downloader instanceof ChangeReportInterface) {
                if (is_link($targetDir)) {
                    $errors[$targetDir] = $targetDir . ' is a symbolic link.';
                }

                if (null !== ($changes = $downloader->getLocalChanges($package, $targetDir))) {
                    $errors[$targetDir] = $changes;
                }
            }

            if ($downloader instanceof VcsCapableDownloaderInterface) {
                if ($downloader->getVcsReference($package, $targetDir)) {
                    switch ($package->getInstallationSource()) {
                        case 'source':
                            $previousRef = $package->getSourceReference();
                            break;
                        case 'dist':
                            $previousRef = $package->getDistReference();
                            break;
                        default:
                            $previousRef = null;
                    }

                    $currentVersion = $guesser->guessVersion($dumper->dump($package), $targetDir);

                    if ($previousRef && $currentVersion && $currentVersion['commit'] !== $previousRef) {
                        $vcsVersionChanges[$targetDir] = array(
                            'previous' => array(
                                'version' => $package->getPrettyVersion(),
                                'ref' => $previousRef,
                            ),
                            'current' => array(
                                'version' => $currentVersion['pretty_version'],
                                'ref' => $currentVersion['commit'],
                            ),
                        );
                    }
                }
            }

            if ($downloader instanceof DvcsDownloaderInterface) {
                if ($unpushed = $downloader->getUnpushedChanges($package, $targetDir)) {
                    $unpushedChanges[$targetDir] = $unpushed;
                }
            }
        }

        // output errors/warnings
        if (!$errors && !$unpushedChanges && !$vcsVersionChanges) {
            $io->writeError('<info>No local changes</info>');

            return 0;
        }

        if ($errors) {
            $io->writeError('<error>You have changes in the following dependencies:</error>');

            foreach ($errors as $path => $changes) {
                if ($input->getOption('verbose')) {
                    $indentedChanges = implode("\n", array_map(static function ($line): string {
                        return '    ' . ltrim($line);
                    }, explode("\n", $changes)));
                    $io->write('<info>'.$path.'</info>:');
                    $io->write($indentedChanges);
                } else {
                    $io->write($path);
                }
            }
        }

        if ($unpushedChanges) {
            $io->writeError('<warning>You have unpushed changes on the current branch in the following dependencies:</warning>');

            foreach ($unpushedChanges as $path => $changes) {
                if ($input->getOption('verbose')) {
                    $indentedChanges = implode("\n", array_map(static function ($line): string {
                        return '    ' . ltrim($line);
                    }, explode("\n", $changes)));
                    $io->write('<info>'.$path.'</info>:');
                    $io->write($indentedChanges);
                } else {
                    $io->write($path);
                }
            }
        }

        if ($vcsVersionChanges) {
            $io->writeError('<warning>You have version variations in the following dependencies:</warning>');

            foreach ($vcsVersionChanges as $path => $changes) {
                if ($input->getOption('verbose')) {
                    // If we don't can't find a version, use the ref instead.
                    $currentVersion = $changes['current']['version'] ?: $changes['current']['ref'];
                    $previousVersion = $changes['previous']['version'] ?: $changes['previous']['ref'];

                    if ($io->isVeryVerbose()) {
                        // Output the ref regardless of whether or not it's being used as the version
                        $currentVersion .= sprintf(' (%s)', $changes['current']['ref']);
                        $previousVersion .= sprintf(' (%s)', $changes['previous']['ref']);
                    }

                    $io->write('<info>'.$path.'</info>:');
                    $io->write(sprintf('    From <comment>%s</comment> to <comment>%s</comment>', $previousVersion, $currentVersion));
                } else {
                    $io->write($path);
                }
            }
        }

        if (($errors || $unpushedChanges || $vcsVersionChanges) && !$input->getOption('verbose')) {
            $io->writeError('Use --verbose (-v) to see a list of files');
        }

        return ($errors ? self::EXIT_CODE_ERRORS : 0) + ($unpushedChanges ? self::EXIT_CODE_UNPUSHED_CHANGES : 0) + ($vcsVersionChanges ? self::EXIT_CODE_VERSION_CHANGES : 0);
    }
}
