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
use Composer\Util\Platform;
use Composer\SelfUpdate\Keys;
use Composer\SelfUpdate\Versions;
use Composer\IO\IOInterface;
use Composer\Downloader\FilesystemException;
use Composer\Downloader\TransportException;
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
                new InputOption('1', null, InputOption::VALUE_NONE, 'Force an update to the stable channel, but only use 1.x versions'),
                new InputOption('2', null, InputOption::VALUE_NONE, 'Force an update to the stable channel, but only use 2.x versions'),
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
        $httpDownloader = Factory::createHttpDownloader($io, $config);

        $versionsUtil = new Versions($config, $httpDownloader);

        // switch channel if requested
        $requestedChannel = null;
        foreach (Versions::$channels as $channel) {
            if ($input->getOption($channel)) {
                $requestedChannel = $channel;
                $versionsUtil->setChannel($channel);
                break;
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

        // ensure composer.phar location is accessible
        if (!file_exists($localFilename)) {
            throw new FilesystemException('Composer update failed: the "'.$localFilename.'" is not accessible');
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
            if (isset($composeUser['name'], $homeOwner['name']) && $composeUser['name'] !== $homeOwner['name']) {
                $io->writeError('<warning>You are running Composer as "'.$composeUser['name'].'", while "'.$home.'" is owned by "'.$homeOwner['name'].'"</warning>');
            }
        }

        if ($input->getOption('rollback')) {
            return $this->rollback($output, $rollbackDir, $localFilename);
        }

        $latest = $versionsUtil->getLatest();
        $latestStable = $versionsUtil->getLatest('stable');
        try {
            $latestPreview = $versionsUtil->getLatest('preview');
        } catch (\UnexpectedValueException $e) {
            $latestPreview = $latestStable;
        }
        $latestVersion = $latest['version'];
        $updateVersion = $input->getArgument('version') ?: $latestVersion;
        $currentMajorVersion = preg_replace('{^(\d+).*}', '$1', Composer::getVersion());
        $updateMajorVersion = preg_replace('{^(\d+).*}', '$1', $updateVersion);
        $previewMajorVersion = preg_replace('{^(\d+).*}', '$1', $latestPreview['version']);

        if ($versionsUtil->getChannel() === 'stable' && !$input->getArgument('version')) {
            // if requesting stable channel and no specific version, avoid automatically upgrading to the next major
            // simply output a warning that the next major stable is available and let users upgrade to it manually
            if ($currentMajorVersion < $updateMajorVersion) {
                $skippedVersion = $updateVersion;

                $versionsUtil->setChannel($currentMajorVersion);

                $latest = $versionsUtil->getLatest();
                $latestStable = $versionsUtil->getLatest('stable');
                $latestVersion = $latest['version'];
                $updateVersion = $latestVersion;

                $io->writeError('<warning>A new stable major version of Composer is available ('.$skippedVersion.'), run "composer self-update --'.$updateMajorVersion.'" to update to it. See also https://getcomposer.org/'.$updateMajorVersion.'</warning>');
            } elseif ($currentMajorVersion < $previewMajorVersion) {
                // promote next major version if available in preview
                $io->writeError('<warning>A preview release of the next major version of Composer is available ('.$latestPreview['version'].'), run "composer self-update --preview" to give it a try. See also https://github.com/composer/composer/releases for changelogs.</warning>');
            }
        }

        if ($requestedChannel && is_numeric($requestedChannel) && strpos($latestStable['version'], $requestedChannel) !== 0) {
            $io->writeError('<warning>Warning: You forced the install of '.$latestVersion.' via --'.$requestedChannel.', but '.$latestStable['version'].' is the latest stable version. Updating to it via composer self-update --stable is recommended.</warning>');
        }

        if (preg_match('{^[0-9a-f]{40}$}', $updateVersion) && $updateVersion !== $latestVersion) {
            $io->writeError('<error>You can not update to a specific SHA-1 as those phars are not available for download</error>');

            return 1;
        }

        $channelString = $versionsUtil->getChannel();
        if (is_numeric($channelString)) {
            $channelString .= '.x';
        }

        if (Composer::VERSION === $updateVersion) {
            $io->writeError(
                sprintf(
                    '<info>You are already using the latest available Composer version %s (%s channel).</info>',
                    $updateVersion,
                    $channelString
                )
            );

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

        $io->write(sprintf("Upgrading to version <info>%s</info> (%s channel).", $updateVersion, $channelString));
        $remoteFilename = $baseUrl . ($updatingToTag ? "/download/{$updateVersion}/composer.phar" : '/composer.phar');
        try {
            $signature = $httpDownloader->get($remoteFilename.'.sig')->getBody();
        } catch (TransportException $e) {
            if ($e->getStatusCode() === 404) {
                throw new \InvalidArgumentException('Version "'.$updateVersion.'" could not be found.', 0, $e);
            }
            throw $e;
        }
        $io->writeError('   ', false);
        $httpDownloader->copy($remoteFilename, $tempFilename);
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

            // PHP 8 automatically frees the key instance and deprecates the function
            if (PHP_VERSION_ID < 80000) {
                openssl_free_key($pubkeyid);
            }

            if (!$verified) {
                throw new \RuntimeException('The phar signature did not match the file you downloaded, this means your public keys are outdated or that the phar file is corrupt/has been modified');
            }
        }

        // remove saved installations of composer
        if ($input->getOption('clean-backups')) {
            $this->cleanBackups($rollbackDir);
        }

        if (!$this->setLocalPhar($localFilename, $tempFilename, $backupFile)) {
            @unlink($tempFilename);

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
        if (!$this->setLocalPhar($localFilename, $oldFile)) {
            return 1;
        }

        return 0;
    }

    /**
     * Checks if the downloaded/rollback phar is valid then moves it
     *
     * @param  string              $localFilename The composer.phar location
     * @param  string              $newFilename   The downloaded or backup phar
     * @param  string              $backupTarget  The filename to use for the backup
     * @throws FilesystemException If the file cannot be moved
     * @return bool                Whether the phar is valid and has been moved
     */
    protected function setLocalPhar($localFilename, $newFilename, $backupTarget = null)
    {
        $io = $this->getIO();
        @chmod($newFilename, fileperms($localFilename));

        // check phar validity
        if (!$this->validatePhar($newFilename, $error)) {
            $io->writeError('<error>The '.($backupTarget ? 'update' : 'backup').' file is corrupted ('.$error.')</error>');

            if ($backupTarget) {
                $io->writeError('<error>Please re-run the self-update command to try again.</error>');
            }

            return false;
        }

        // copy current file into backups dir
        if ($backupTarget) {
            @copy($localFilename, $backupTarget);
        }

        try {
            rename($newFilename, $localFilename);

            return true;
        } catch (\Exception $e) {
            // see if we can run this operation as an Admin on Windows
            if (!is_writable(dirname($localFilename))
                && $io->isInteractive()
                && $this->isWindowsNonAdminUser()) {
                return $this->tryAsWindowsAdmin($localFilename, $newFilename);
            }

            $action = 'Composer '.($backupTarget ? 'update' : 'rollback');
            throw new FilesystemException($action.' failed: "'.$localFilename.'" could not be written.'.PHP_EOL.$e->getMessage());
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
        return Finder::create()
            ->depth(0)
            ->files()
            ->name('*' . self::OLD_INSTALL_EXT)
            ->in($rollbackDir);
    }

    /**
     * Validates the downloaded/backup phar file
     *
     * @param string      $pharFile The downloaded or backup phar
     * @param null|string $error    Set by method on failure
     *
     * Code taken from getcomposer.org/installer. Any changes should be made
     * there and replicated here
     *
     * @throws \Exception
     * @return bool       If the operation succeeded
     */
    protected function validatePhar($pharFile, &$error)
    {
        if (ini_get('phar.readonly')) {
            return true;
        }

        try {
            // Test the phar validity
            $phar = new \Phar($pharFile);
            // Free the variable to unlock the file
            unset($phar);
            $result = true;
        } catch (\Exception $e) {
            if (!$e instanceof \UnexpectedValueException && !$e instanceof \PharException) {
                throw $e;
            }
            $error = $e->getMessage();
            $result = false;
        }

        return $result;
    }

    /**
     * Returns true if this is a non-admin Windows user account
     *
     * @return bool
     */
    protected function isWindowsNonAdminUser()
    {
        if (!Platform::isWindows()) {
            return false;
        }

        // fltmc.exe manages filter drivers and errors without admin privileges
        exec('fltmc.exe filters', $output, $exitCode);

        return $exitCode !== 0;
    }

    /**
     * Invokes a UAC prompt to update composer.phar as an admin
     *
     * Uses a .vbs script to elevate and run the cmd.exe move command.
     *
     * @param  string $localFilename The composer.phar location
     * @param  string $newFilename   The downloaded or backup phar
     * @return bool   Whether composer.phar has been updated
     */
    protected function tryAsWindowsAdmin($localFilename, $newFilename)
    {
        $io = $this->getIO();

        $io->writeError('<error>Unable to write "'.$localFilename.'". Access is denied.</error>');
        $helpMessage = 'Please run the self-update command as an Administrator.';
        $question = 'Complete this operation with Administrator privileges [<comment>Y,n</comment>]? ';

        if (!$io->askConfirmation($question, false)) {
            $io->writeError('<warning>Operation cancelled. '.$helpMessage.'</warning>');

            return false;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), '');
        $script = $tmpFile.'.vbs';
        rename($tmpFile, $script);

        $checksum = hash_file('sha256', $newFilename);

        // cmd's internal move is fussy about backslashes
        $source = str_replace('/', '\\', $newFilename);
        $destination = str_replace('/', '\\', $localFilename);

        $vbs = <<<EOT
Set UAC = CreateObject("Shell.Application")
UAC.ShellExecute "cmd.exe", "/c move /y ""$source"" ""$destination""", "", "runas", 0
Wscript.Sleep(300)
EOT;

        file_put_contents($script, $vbs);
        exec('"'.$script.'"');
        @unlink($script);

        // see if the file was moved
        if ($result = (hash_file('sha256', $localFilename) === $checksum)) {
            $io->writeError('<info>Operation succeeded.</info>');
        } else {
            $io->writeError('<error>Operation failed (file not written). '.$helpMessage.'</error>');
        }

        return $result;
    }
}
