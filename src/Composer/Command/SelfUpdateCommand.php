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
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Igor Wiedler <igor@wiedler.ch>
 */
class SelfUpdateCommand extends Command
{
    const ROLLBACK = 'rollback';
    const CLEAN_ROLLBACKS = 'clean-rollbacks';
    const HOMEPAGE = 'getcomposer.org';
    const OLD_INSTALL_EXT = '-old.phar';

    protected $remoteFS;
    protected $latestVersion;
    protected $homepageURL;
    protected $localFilename;

    public function __construct($name = null)
    {
        parent::__construct($name);
        $protocol = (extension_loaded('openssl') ? 'https' : 'http') . '://';
        $this->homepageURL = $protocol . self::HOMEPAGE;
        $this->remoteFS = new RemoteFilesystem($this->getIO());
        $this->localFilename = realpath($_SERVER['argv'][0]) ?: $_SERVER['argv'][0];
    }

    protected function configure()
    {
        $this
            ->setName('self-update')
            ->setAliases(array('selfupdate'))
            ->setDescription('Updates composer.phar to the latest version.')
            ->setDefinition(array(
                new InputOption(self::ROLLBACK, 'r', InputOption::VALUE_NONE, 'Revert to an older installation of composer'),
                new InputOption(self::CLEAN_ROLLBACKS, null, InputOption::VALUE_NONE, 'Delete old snapshots during an update. This makes the current version of composer the only rollback snapshot after the update')
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
        $config = Factory::createConfig();
        $cacheDir = rtrim($config->get('cache-dir'), '/');

        // Check if current dir is writable and if not try the cache dir from settings
        $tmpDir = is_writable(dirname($this->localFilename))? dirname($this->localFilename) : $cacheDir;

        // check for permissions in local filesystem before start connection process
        if (!is_writable($tmpDir)) {
            throw new FilesystemException('Composer update failed: the "'.$tmpDir.'" directory used to download the temp file could not be written');
        }

        if (!is_writable($this->localFilename)) {
            throw new FilesystemException('Composer update failed: the "'.$this->localFilename.'" file could not be written');
        }

        $rollbackVersion = false;
        $rollbackDir = rtrim($config->get('home'), '/');

        // rollback specified, get last phar
        if ($input->getOption(self::ROLLBACK)) {
            $rollbackVersion = $this->getLastVersion($rollbackDir);
            if (!$rollbackVersion) {
                throw new FilesystemException('Composer rollback failed: no installation to roll back to in "'.$rollbackDir.'"');

                return 1;
            }
        }

        // if a rollback version is specified, check for permissions and rollback installation
        if ($rollbackVersion) {
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
        }

        $updateVersion = ($rollbackVersion)? $rollbackVersion : $this->getLatestVersion();

        if (Composer::VERSION === $updateVersion) {
            $output->writeln('<info>You are already using composer version '.$updateVersion.'.</info>');

            return 0;
        }

        $tempFilename = $tmpDir . '/' . basename($this->localFilename, '.phar').'-temp.phar';
        $backupFile = ($rollbackVersion)? false : $rollbackDir . '/' . Composer::VERSION . self::OLD_INSTALL_EXT;

        if ($rollbackVersion) {
            rename($rollbackDir . "/{$rollbackVersion}" . self::OLD_INSTALL_EXT, $tempFilename);
            $output->writeln(sprintf("Rolling back to cached version <info>%s</info>.", $rollbackVersion));
        } else {
            $endpoint = ($updateVersion === $this->getLatestVersion()) ? '/composer.phar' : "/download/{$updateVersion}/composer.phar";
            $remoteFilename = $this->homepageURL . $endpoint;

            $output->writeln(sprintf("Updating to version <info>%s</info>.", $updateVersion));

            $this->remoteFS->copy(self::HOMEPAGE, $remoteFilename, $tempFilename);

            // @todo: handle snapshot versions not being found!
            if (!file_exists($tempFilename)) {
                $output->writeln('<error>The download of the new composer version failed for an unexpected reason');

                return 1;
            }

            // remove saved installations of composer
            if ($input->getOption(self::CLEAN_ROLLBACKS)) {
                $files = $this->getOldInstallationFiles($rollbackDir);

                if (!empty($files)) {
                    $fs = new Filesystem;

                    foreach ($files as $file) {
                        $output->writeln('<info>Removing: '.$file);
                        $fs->remove($file);
                    }
                }
            }
        }

        if ($err = $this->setLocalPhar($tempFilename, $backupFile)) {
            $output->writeln('<error>The file is corrupted ('.$err->getMessage().').</error>');
            $output->writeln('<error>Please re-run the self-update command to try again.</error>');

            return 1;
        }

        if ($backupFile) {
            $output->writeln('<info>Saved rollback snapshot '.$backupFile);
        }
    }

    protected function setLocalPhar($filename, $backupFile)
    {
        try {
            @chmod($filename, 0777 & ~umask());
            // test the phar validity
            $phar = new \Phar($filename);
            // free the variable to unlock the file
            unset($phar);

            // copy current file into installations dir
            if ($backupFile) {
                copy($this->localFilename, $backupFile);
            }

            unset($phar);
            rename($filename, $this->localFilename);
        } catch (\Exception $e) {
            @unlink($filename);
            if (!$e instanceof \UnexpectedValueException && !$e instanceof \PharException) {
                throw $e;
            }

            return $e;
        }
    }

    protected function getLastVersion($rollbackDir)
    {
        $files = $this->getOldInstallationFiles($rollbackDir);

        if (empty($files)) {
            return false;
        }

        $fileTimes = array_map('filemtime', $files);
        $map = array_combine($fileTimes, $files);
        $latest = max($fileTimes);
        return basename($map[$latest], self::OLD_INSTALL_EXT);
    }

    protected function getOldInstallationFiles($rollbackDir)
    {
        return glob($rollbackDir . '/*' . self::OLD_INSTALL_EXT);
    }

    protected function getLatestVersion()
    {
        if (!$this->latestVersion) {
            $this->latestVersion = trim($this->remoteFS->getContents(self::HOMEPAGE, $this->homepageURL. '/version', false));
        }

        return $this->latestVersion;
    }
}
