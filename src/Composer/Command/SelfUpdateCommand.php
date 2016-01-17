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
use Composer\Config;
use Composer\Util\Filesystem;
use Composer\Util\Keys;
use Composer\IO\IOInterface;
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
                new InputOption('update-keys', null, InputOption::VALUE_NONE, 'Prompt user for a key update'),
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

        if ($config->get('disable-tls') === true) {
            $baseUrl = 'http://' . self::HOMEPAGE;
        } else {
            $baseUrl = 'https://' . self::HOMEPAGE;
        }

        $io = $this->getIO();
        $remoteFilesystem = Factory::createRemoteFilesystem($io, $config);

        $cacheDir = $config->get('cache-dir');
        $rollbackDir = $config->get('data-dir');
        $home = $config->get('home');
        $localFilename = realpath($_SERVER['argv'][0]) ?: $_SERVER['argv'][0];

        if ($input->getOption('update-keys')) {
            return $this->fetchKeys($io, $config);
        }

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

        $updatingToTag = !preg_match('{^[0-9a-f]{40}$}', $updateVersion);

        $io->write(sprintf("Updating to version <info>%s</info>.", $updateVersion));
        $remoteFilename = $baseUrl . ($updatingToTag ? "/download/{$updateVersion}/composer.phar" : '/composer.phar');
        $signature = $remoteFilesystem->getContents(self::HOMEPAGE, $remoteFilename.'.sig', false);
        $remoteFilesystem->copy(self::HOMEPAGE, $remoteFilename, $tempFilename, !$input->getOption('no-progress'));
        if (!file_exists($tempFilename) || !$signature) {
            $io->writeError('<error>The download of the new composer version failed for an unexpected reason</error>');

            return 1;
        }

        // verify phar signature
        if (!extension_loaded('openssl') && $config->get('disable-tls')) {
            $io->writeError('<warning>Skipping phar signature verification as you have disabled OpenSSL via config.disable-tls</warning>');
        } else {
            if (!extension_loaded('openssl')) {
                throw new \RuntimeException('The openssl extension is required for phar signatures to be verified but it is not available. '
                . 'If you can not enable the openssl extension, you can disable this error, at your own risk, by setting the \'disable-tls\' option to true.');
            }

            $sigFile = 'file://'.$home.'/' . ($updatingToTag ? 'keys.tags.pub' : 'keys.dev.pub');
            if (!file_exists($sigFile)) {
                $io->write('<warning>You are missing the public keys used to verify Composer phar file signatures</warning>');
                if (!$io->isInteractive() || getenv('CI') || getenv('CONTINUOUS_INTEGRATION')) {
                    $io->write('<warning>As this process is not interactive or you run on CI, it is allowed to run for now, but you should run "composer self-update --update-keys" to get them set up.</warning>');
                } else {
                    $this->fetchKeys($io, $config);
                }
            }

            // if still no file is present it means we are on CI/travis or
            // not interactive so we skip the check for now
            if (file_exists($sigFile)) {
                $pubkeyid = openssl_pkey_get_public($sigFile);
                $algo = defined('OPENSSL_ALGO_SHA384') ? OPENSSL_ALGO_SHA384 : 'SHA384';
                if (!in_array('SHA384', openssl_get_md_methods())) {
                    throw new \RuntimeException('SHA384 is not supported by your openssl extension, could not verify the phar file integrity');
                }
                $signature = json_decode($signature, true);
                $signature = base64_decode($signature['sha384']);
                $verified = 1 === openssl_verify(file_get_contents($tempFilename), $signature, $pubkeyid, $algo);
                openssl_free_key($pubkeyid);
                if (!$verified) {
                    throw new \RuntimeException('The phar signature did not match the file you downloaded, this means your public keys are outdated or that the phar file is corrupt/has been modified');
                }
            }
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

    protected function fetchKeys(IOInterface $io, Config $config)
    {
        if (!$io->isInteractive()) {
            throw new \RuntimeException('Public keys are missing and can not be fetched in non-interactive mode, run this interactively or re-install composer using the installer to get the public keys set up');
        }

        $io->write('Open <info>https://composer.github.io/pubkeys.html</info> to find the latest keys');

        $validator = function ($value) {
            if (!preg_match('{^-----BEGIN PUBLIC KEY-----$}', trim($value))) {
                throw new \UnexpectedValueException('Invalid input');
            }
            return trim($value)."\n";
        };

        $devKey = '';
        while (!preg_match('{(-----BEGIN PUBLIC KEY-----.+?-----END PUBLIC KEY-----)}s', $devKey, $match)) {
            $devKey = $io->askAndValidate('Enter Dev / Snapshot Public Key (including lines with -----): ', $validator);
            while ($line = $io->ask('')) {
                $devKey .= trim($line)."\n";
                if (trim($line) === '-----END PUBLIC KEY-----') {
                    break;
                }
            }
        }
        file_put_contents($keyPath = $config->get('home').'/keys.dev.pub', $match[0]);
        $io->write('Stored key with fingerprint: ' . Keys::fingerprint($keyPath));

        $tagsKey = '';
        while (!preg_match('{(-----BEGIN PUBLIC KEY-----.+?-----END PUBLIC KEY-----)}s', $tagsKey, $match)) {
            $tagsKey = $io->askAndValidate('Enter Tags Public Key (including lines with -----): ', $validator);
            while ($line = $io->ask('')) {
                $tagsKey .= trim($line)."\n";
                if (trim($line) === '-----END PUBLIC KEY-----') {
                    break;
                }
            }
        }
        file_put_contents($keyPath = $config->get('home').'/keys.tags.pub', $match[0]);
        $io->write('Stored key with fingerprint: ' . Keys::fingerprint($keyPath));

        $io->write('Public keys stored in '.$config->get('home'));
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

    /**
     * @param string $localFilename
     * @param string $newFilename
     * @param string $backupTarget
     */
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
