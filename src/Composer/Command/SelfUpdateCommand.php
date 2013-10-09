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
use Composer\Util\RemoteFilesystem;
use Composer\Downloader\FilesystemException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Igor Wiedler <igor@wiedler.ch>
 */
class SelfUpdateCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('self-update')
            ->setAliases(array('selfupdate'))
            ->setDescription('Updates composer.phar to the latest version.')
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
        $localFilename = realpath($_SERVER['argv'][0]) ?: $_SERVER['argv'][0];
        $tempFilename = dirname($localFilename) . '/' . basename($localFilename, '.phar').'-temp.phar';

        // check for permissions in local filesystem before start connection process
        if (!is_writable($tempDirectory = dirname($tempFilename))) {
            throw new FilesystemException('Composer update failed: the "'.$tempDirectory.'" directory used to download the temp file could not be written');
        }

        if (!is_writable($localFilename)) {
            throw new FilesystemException('Composer update failed: the "'.$localFilename. '" file could not be written');
        }

        $protocol = extension_loaded('openssl') ? 'https' : 'http';
        $rfs = new RemoteFilesystem($this->getIO());
        $latest = trim($rfs->getContents('getcomposer.org', $protocol . '://getcomposer.org/version', false));

        if (Composer::VERSION !== $latest) {
            $output->writeln(sprintf("Updating to version <info>%s</info>.", $latest));

            $remoteFilename = $protocol . '://getcomposer.org/composer.phar';

            $rfs->copy('getcomposer.org', $remoteFilename, $tempFilename);

            if (!file_exists($tempFilename)) {
                $output->writeln('<error>The download of the new composer version failed for an unexpected reason');

                return 1;
            }

            try {
                @chmod($tempFilename, 0777 & ~umask());
                // test the phar validity
                $phar = new \Phar($tempFilename);
                // free the variable to unlock the file
                unset($phar);
                rename($tempFilename, $localFilename);
            } catch (\Exception $e) {
                @unlink($tempFilename);
                if (!$e instanceof \UnexpectedValueException && !$e instanceof \PharException) {
                    throw $e;
                }
                $output->writeln('<error>The download is corrupted ('.$e->getMessage().').</error>');
                $output->writeln('<error>Please re-run the self-update command to try again.</error>');
            }
        } else {
            $output->writeln("<info>You are using the latest composer version.</info>");
        }
    }
}
