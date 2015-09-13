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

use Composer\Composer;
use Composer\Factory;
use Composer\Util\Filesystem;
use Composer\Util\RemoteFilesystem;
use Composer\Downloader\FilesystemException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

/**
 * @author Igor Wiedler <igor@wiedler.ch>
 * @author Kevin Ran <kran@adobe.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class SelfUpdateCommand extends Command
{
    const HOMEPAGE = 'getcomposer.org';
    const OLD_INSTALL_EXT = '-old.phar';

    protected function configure()
    {
        $this
            ->setName('self-update')
            ->setAliases(array('selfupdate'))
            ->setDescription('Updates composer.phar to the latest version.')
            ->setDefinition(array(
                new InputOption('rollback', 'r', InputOption::VALUE_NONE, 'Revert to an older installation of composer'),
                new InputOption('clean-backups', null, InputOption::VALUE_NONE, 'Delete old backups during an update. This makes the current version of composer the only backup available after the update'),
                new InputArgument('version', InputArgument::OPTIONAL, 'The version to update to'),
                new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
            ))
            ->setHelp(<<<EOT
The <info>self-update</info> command checks getcomposer.org for newer
versions of composer and if found, installs the latest.

<info>php composer.phar self-update</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $baseUrl = (extension_loaded('openssl') ? 'https' : 'http') . '://' . self::HOMEPAGE;
        $config = Factory::createConfig();
        $io = $this->getIO();
        $remoteFilesystem = new RemoteFilesystem($io, $config);
        $cacheDir = $config->get('cache-dir');
        $rollbackDir = $config->get('home');
        $localFilename = realpath($_SERVER['argv'][0]) ?: $_SERVER['argv'][0];

        // check if current dir is writable and if not try the cache dir from settings
        $tmpDir = is_writable(dirname($localFilename)) ? dirname($localFilename) : $cacheDir;

        // check for permissions in local filesystem before start connection process
        if (!is_writable($tmpDir)) {
            throw new FilesystemException('Composer update failed: the "'.$tmpDir.'" directory used to download the temp file could not be written');
        }
        if (!is_writable($localFilename)) {
            throw new FilesystemException('Composer update failed: the "'.$localFilename.'" file could not be written');
        }

        if ($input->getOption('rollback')) {
            return $this->rollback($output, $rollbackDir, $localFilename);
        }

        $latestVersion = trim($remoteFilesystem->getContents(self::HOMEPAGE, $baseUrl. '/version', false));
        $updateVersion = $input->getArgument('version') ?: $latestVersion;

        if (preg_match('{^[0-9a-f]{40}$}', $updateVersion) && $updateVersion !== $latestVersion) {
            $io->writeError('<error>You can not update to a specific SHA-1 as those phars are not available for download</error>');

            return 1;
        }

        if (Composer::VERSION === $updateVersion) {
            $io->writeError('<info>You are already using composer version '.$updateVersion.'.</info>');

            return 0;
        }

        $tempFilename = $tmpDir . '/' . basename($localFilename, '.phar').'-temp.phar';
        $backupFile = sprintf(
            '%s/%s-%s%s',
            $rollbackDir,
            strtr(Composer::RELEASE_DATE, ' :', '_-'),
            preg_replace('{^([0-9a-f]{7})[0-9a-f]{33}$}', '$1', Composer::VERSION),
            self::OLD_INSTALL_EXT
        );

        $io->writeError(sprintf("Updating to version <info>%s</info>.", $updateVersion));
        $remoteFilename = $baseUrl . (preg_match('{^[0-9a-f]{40}$}', $updateVersion) ? '/composer.phar' : "/download/{$updateVersion}/composer.phar");
        $remoteFilesystem->copy(self::HOMEPAGE, $remoteFilename, $tempFilename, !$input->getOption('no-progress'));
        if (!file_exists($tempFilename)) {
            $io->writeError('<error>The download of the new composer version failed for an unexpected reason</error>');

            return 1;
        }

        // remove saved installations of composer
        if ($input->getOption('clean-backups')) {
            $finder = $this->getOldInstallationFinder($rollbackDir);

            $fs = new Filesystem;
            foreach ($finder as $file) {
                $file = (string) $file;
                $io->writeError('<info>Removing: '.$file.'</info>');
                $fs->remove($file);
            }
        }

        if ($err = $this->setLocalPhar($localFilename, $tempFilename, $backupFile)) {
            $io->writeError('<error>The file is corrupted ('.$err->getMessage().').</error>');
            $io->writeError('<error>Please re-run the self-update command to try again.</error>');

            return 1;
        }

        if (file_exists($backupFile)) {
            $io->writeError('Use <info>composer self-update --rollback</info> to return to version '.Composer::VERSION);
        } else {
            $io->writeError('<warning>A backup of the current version could not be written to '.$backupFile.', no rollback possible</warning>');
        }
    }

    protected function rollback(OutputInterface $output, $rollbackDir, $localFilename)
    {
        $rollbackVersion = $this->getLastBackupVersion($rollbackDir);
        if (!$rollbackVersion) {
            throw new \UnexpectedValueException('Composer rollback failed: no installation to roll back to in "'.$rollbackDir.'"');
        }

        if (!is_writable($rollbackDir)) {
            throw new FilesystemException('Composer rollback failed: the "'.$rollbackDir.'" dir could not be written to');
        }

        $old = $rollbackDir . '/' . $rollbackVersion . self::OLD_INSTALL_EXT;

        if (!is_file($old)) {
            throw new FilesystemException('Composer rollback failed: "'.$old.'" could not be found');
        }
        if (!is_readable($old)) {
            throw new FilesystemException('Composer rollback failed: "'.$old.'" could not be read');
        }

        $oldFile = $rollbackDir . "/{$rollbackVersion}" . self::OLD_INSTALL_EXT;
        $io = $this->getIO();
        $io->writeError(sprintf("Rolling back to version <info>%s</info>.", $rollbackVersion));
        if ($err = $this->setLocalPhar($localFilename, $oldFile)) {
            $io->writeError('<error>The backup file was corrupted ('.$err->getMessage().') and has been removed.</error>');

            return 1;
        }

        return 0;
    }

    protected function setLocalPhar($localFilename, $newFilename, $backupTarget = null)
    {
        try {
            @chmod($newFilename, fileperms($localFilename));
            if (!ini_get('phar.readonly')) {
                // test the phar validity
                $phar = new \Phar($newFilename);
                // free the variable to unlock the file
                unset($phar);
            }

            // copy current file into installations dir
            if ($backupTarget && file_exists($localFilename)) {
                @copy($localFilename, $backupTarget);
            }

            rename($newFilename, $localFilename);
        } catch (\Exception $e) {
            if ($backupTarget) {
                @unlink($newFilename);
            }
            if (!$e instanceof \UnexpectedValueException && !$e instanceof \PharException) {
                throw $e;
            }

            return $e;
        }
    }

    protected function getLastBackupVersion($rollbackDir)
    {
        $finder = $this->getOldInstallationFinder($rollbackDir);
        $finder->sortByName();
        $files = iterator_to_array($finder);

        if (count($files)) {
            return basename(end($files), self::OLD_INSTALL_EXT);
        }

        return false;
    }

    protected function getOldInstallationFinder($rollbackDir)
    {
        $finder = Finder::create()
            ->depth(0)
            ->files()
            ->name('*' . self::OLD_INSTALL_EXT)
            ->in($rollbackDir);

        return $finder;
    }
}
