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
    const HOMEPAGE = 'getcomposer.org';

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
                new InputOption(self::ROLLBACK, 'r', InputOption::VALUE_OPTIONAL, 'Revert to an older installation of composer'),
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
        $saveDir = rtrim($config->get('home'), '/');

        // Check if current dir is writable and if not try the cache dir from settings
        $tmpDir = is_writable(dirname($this->localFilename))? dirname($this->localFilename) : $cacheDir;

        // check for permissions in local filesystem before start connection process
        if (!is_writable($tmpDir)) {
            throw new FilesystemException('Composer update failed: the "'.$tmpDir.'" directory used to download the temp file could not be written');
        }

        if (!is_writable($this->localFilename)) {
            throw new FilesystemException('Composer update failed: the "'.$this->localFilename.'" file could not be written');
        }

        $rollback = $this->getOption(self::ROLLBACK);

        if (is_null($rollback)) {
            $rollback = $this->getLastVersion();
            if (!$rollback) {
                throw new FilesystemException('Composer rollback failed: no installation to roll back to in "'.$saveDir.'"');

                return 1;
            }
        }

        // if a rollback version is specified, check for permissions and rollback installation
        if ($rollback) {
            if (!is_writable($saveDir)) {
                throw new FilesystemException('Composer rollback failed: the "'.$saveDir.'" dir could not be written to');
            }

            $old = $saveDir . "/{$rollback}.phar";

            if (!is_file($old)) {
                throw new FilesystemException('Composer rollback failed: "'.$old.'" could not be found');
            }
            if (!is_readable($old)) {
                throw new FilesystemException('Composer rollback failed: "'.$old.'" could not be read');
            }
        }

        $updateVersion = ($rollback)? $rollback : $this->getLatestVersion();

        if (Composer::VERSION === $updateVersion) {
            $output->writeln("<info>You are already using composer v%s.</info>", $updateVersion);

            return 0;
        }

        $tempFilename = $tmpDir . '/' . basename($this->localFilename, '.phar').'-temp.phar';

        if ($rollback) {
            copy($saveDir . "/{$rollback}.phar", $tempFilename);
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
        }

        if ($err = $this->setLocalPhar($tempFilename, $saveDir)) {
            $output->writeln('<error>The file is corrupted ('.$err->getMessage().').</error>');
            $output->writeln('<error>Please re-run the self-update command to try again.</error>');

            return 1;
        }
    }

    protected function setLocalPhar($filename, $saveDir)
    {
        try {
            @chmod($filename, 0777 & ~umask());
            // test the phar validity
            $phar = new \Phar($filename);
            // copy current file into installations dir
            copy($this->localFilename, $saveDir . Composer::VERSION . '.phar');
            // free the variable to unlock the file
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

    protected function getLastVersion($saveDir)
    {
        $config = Factory::createConfig();
        $files = glob($saveDir . '/*.phar');

        if (empty($files)) {
            return false;
        }

        $fileTimes = array_map('filemtime', $files);
        $map = array_combine($fileTimes, $files);
        $latest = max($fileTimes);
        return basename($map[$latest], '.phar');
    }

    protected function getLatestVersion()
    {
        if (!$this->latestVersion) {
            $this->latestVersion = trim($this->remoteFS->getContents(self::HOMEPAGE, $this->homepageURL. '/version', false));
        }

        return $this->latestVersion;
    }
}
