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
use Composer\SelfUpdate\Keys;
use Composer\SelfUpdate\Versions;
use Composer\IO\IOInterface;
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
class SelfUpdateCommand extends BaseCommand
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
                new InputOption('stable', null, InputOption::VALUE_NONE, 'Force an update to the stable channel'),
                new InputOption('preview', null, InputOption::VALUE_NONE, 'Force an update to the preview channel'),
                new InputOption('snapshot', null, InputOption::VALUE_NONE, 'Force an update to the snapshot channel'),
                new InputOption('set-channel-only', null, InputOption::VALUE_NONE, 'Only store the channel as the default one and then exit'),
            ))
            ->setHelp(
                <<<EOT
The <info>self-update</info> command checks getcomposer.org for newer
versions of composer and if found, installs the latest.

<info>php composer.phar self-update</info>

Read more at https://getcomposer.org/doc/03-cli.md#self-update-selfupdate-
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

        $versionsUtil = new Versions($config, $remoteFilesystem);

        // switch channel if requested
        foreach (array('stable', 'preview', 'snapshot') as $channel) {
            if ($input->getOption($channel)) {
                $versionsUtil->setChannel($channel);
            }
        }

        if ($input->getOption('set-channel-only')) {
            return 0;
        }

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

        // check if composer is running as the same user that owns the directory root, only if POSIX is defined and callable
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $composeUser = posix_getpwuid(posix_geteuid());
            $homeOwner = posix_getpwuid(fileowner($home));
            if (isset($composeUser['name']) && isset($homeOwner['name']) && $composeUser['name'] !== $homeOwner['name']) {
                $io->writeError('<warning>You are running composer as "'.$composeUser['name'].'", while "'.$home.'" is owned by "'.$homeOwner['name'].'"</warning>');
            }
        }

        if ($input->getOption('rollback')) {
            return $this->rollback($output, $rollbackDir, $localFilename);
        }

        $latest = $versionsUtil->getLatest();
        $latestVersion = $latest['version'];
        $updateVersion = $input->getArgument('version') ?: $latestVersion;

        if (preg_match('{^[0-9a-f]{40}$}', $updateVersion) && $updateVersion !== $latestVersion) {
            $io->writeError('<error>You can not update to a specific SHA-1 as those phars are not available for download</error>');

            return 1;
        }

        if (Composer::VERSION === $updateVersion) {
            $io->writeError(sprintf('<info>You are already using composer version %s (%s channel).</info>', $updateVersion, $versionsUtil->getChannel()));

            // remove all backups except for the most recent, if any
            if ($input->getOption('clean-backups')) {
                $this->cleanBackups($rollbackDir, $this->getLastBackupVersion($rollbackDir));
            }

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

        $io->write(sprintf("Updating to version <info>%s</info> (%s channel).", $updateVersion, $versionsUtil->getChannel()));
        $remoteFilename = $baseUrl . ($updatingToTag ? "/download/{$updateVersion}/composer.phar" : '/composer.phar');
        $signature = $remoteFilesystem->getContents(self::HOMEPAGE, $remoteFilename.'.sig', false);
        $io->writeError('   ', false);
        $remoteFilesystem->copy(self::HOMEPAGE, $remoteFilename, $tempFilename, !$input->getOption('no-progress'));
        $io->writeError('');

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
                file_put_contents(
                    $home.'/keys.dev.pub',
                    <<<DEVPUBKEY
-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAnBDHjZS6e0ZMoK3xTD7f
FNCzlXjX/Aie2dit8QXA03pSrOTbaMnxON3hUL47Lz3g1SC6YJEMVHr0zYq4elWi
i3ecFEgzLcj+pZM5X6qWu2Ozz4vWx3JYo1/a/HYdOuW9e3lwS8VtS0AVJA+U8X0A
hZnBmGpltHhO8hPKHgkJtkTUxCheTcbqn4wGHl8Z2SediDcPTLwqezWKUfrYzu1f
o/j3WFwFs6GtK4wdYtiXr+yspBZHO3y1udf8eFFGcb2V3EaLOrtfur6XQVizjOuk
8lw5zzse1Qp/klHqbDRsjSzJ6iL6F4aynBc6Euqt/8ccNAIz0rLjLhOraeyj4eNn
8iokwMKiXpcrQLTKH+RH1JCuOVxQ436bJwbSsp1VwiqftPQieN+tzqy+EiHJJmGf
TBAbWcncicCk9q2md+AmhNbvHO4PWbbz9TzC7HJb460jyWeuMEvw3gNIpEo2jYa9
pMV6cVqnSa+wOc0D7pC9a6bne0bvLcm3S+w6I5iDB3lZsb3A9UtRiSP7aGSo7D72
8tC8+cIgZcI7k9vjvOqH+d7sdOU2yPCnRY6wFh62/g8bDnUpr56nZN1G89GwM4d4
r/TU7BQQIzsZgAiqOGXvVklIgAMiV0iucgf3rNBLjjeNEwNSTTG9F0CtQ+7JLwaE
wSEuAuRm+pRqi8BRnQ/GKUcCAwEAAQ==
-----END PUBLIC KEY-----
DEVPUBKEY
                );

                file_put_contents(
                    $home.'/keys.tags.pub',
                    <<<TAGSPUBKEY
-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA0Vi/2K6apCVj76nCnCl2
MQUPdK+A9eqkYBacXo2wQBYmyVlXm2/n/ZsX6pCLYPQTHyr5jXbkQzBw8SKqPdlh
vA7NpbMeNCz7wP/AobvUXM8xQuXKbMDTY2uZ4O7sM+PfGbptKPBGLe8Z8d2sUnTO
bXtX6Lrj13wkRto7st/w/Yp33RHe9SlqkiiS4MsH1jBkcIkEHsRaveZzedUaxY0M
mba0uPhGUInpPzEHwrYqBBEtWvP97t2vtfx8I5qv28kh0Y6t+jnjL1Urid2iuQZf
noCMFIOu4vksK5HxJxxrN0GOmGmwVQjOOtxkwikNiotZGPR4KsVj8NnBrLX7oGuM
nQvGciiu+KoC2r3HDBrpDeBVdOWxDzT5R4iI0KoLzFh2pKqwbY+obNPS2bj+2dgJ
rV3V5Jjry42QOCBN3c88wU1PKftOLj2ECpewY6vnE478IipiEu7EAdK8Zwj2LmTr
RKQUSa9k7ggBkYZWAeO/2Ag0ey3g2bg7eqk+sHEq5ynIXd5lhv6tC5PBdHlWipDK
tl2IxiEnejnOmAzGVivE1YGduYBjN+mjxDVy8KGBrjnz1JPgAvgdwJ2dYw4Rsc/e
TzCFWGk/HM6a4f0IzBWbJ5ot0PIi4amk07IotBXDWwqDiQTwyuGCym5EqWQ2BD95
RGv89BPD+2DLnJysngsvVaUCAwEAAQ==
-----END PUBLIC KEY-----
TAGSPUBKEY
                );
            }

            $pubkeyid = openssl_pkey_get_public($sigFile);
            $algo = defined('OPENSSL_ALGO_SHA384') ? OPENSSL_ALGO_SHA384 : 'SHA384';
            if (!in_array('sha384', array_map('strtolower', openssl_get_md_methods()))) {
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

        // remove saved installations of composer
        if ($input->getOption('clean-backups')) {
            $this->cleanBackups($rollbackDir);
        }

        if ($err = $this->setLocalPhar($localFilename, $tempFilename, $backupFile)) {
            @unlink($tempFilename);
            $io->writeError('<error>The file is corrupted ('.$err->getMessage().').</error>');
            $io->writeError('<error>Please re-run the self-update command to try again.</error>');

            return 1;
        }

        if (file_exists($backupFile)) {
            $io->writeError(sprintf(
                'Use <info>composer self-update --rollback</info> to return to version <comment>%s</comment>',
                Composer::VERSION
            ));
        } else {
            $io->writeError('<warning>A backup of the current version could not be written to '.$backupFile.', no rollback possible</warning>');
        }

        return 0;
    }

    protected function fetchKeys(IOInterface $io, Config $config)
    {
        if (!$io->isInteractive()) {
            throw new \RuntimeException('Public keys can not be fetched in non-interactive mode, please run Composer interactively');
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

        $oldFile = $rollbackDir . '/' . $rollbackVersion . self::OLD_INSTALL_EXT;

        if (!is_file($oldFile)) {
            throw new FilesystemException('Composer rollback failed: "'.$oldFile.'" could not be found');
        }
        if (!is_readable($oldFile)) {
            throw new FilesystemException('Composer rollback failed: "'.$oldFile.'" could not be read');
        }

        $io = $this->getIO();
        $io->writeError(sprintf("Rolling back to version <info>%s</info>.", $rollbackVersion));
        if ($err = $this->setLocalPhar($localFilename, $oldFile)) {
            $io->writeError('<error>The backup file was corrupted ('.$err->getMessage().').</error>');

            return 1;
        }

        return 0;
    }

    /**
     * @param  string                                        $localFilename
     * @param  string                                        $newFilename
     * @param  string                                        $backupTarget
     * @throws \Exception
     * @return \UnexpectedValueException|\PharException|null
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

            return null;
        } catch (\Exception $e) {
            if (!$e instanceof \UnexpectedValueException && !$e instanceof \PharException) {
                throw $e;
            }

            return $e;
        }
    }

    protected function cleanBackups($rollbackDir, $except = null)
    {
        $finder = $this->getOldInstallationFinder($rollbackDir);
        $io = $this->getIO();
        $fs = new Filesystem;

        foreach ($finder as $file) {
            if ($except && $file->getBasename(self::OLD_INSTALL_EXT) === $except) {
                continue;
            }
            $file = (string) $file;
            $io->writeError('<info>Removing: '.$file.'</info>');
            $fs->remove($file);
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
