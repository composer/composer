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
        $rfs = new RemoteFilesystem($this->getIO());
        $latest = trim($rfs->getContents('getcomposer.org', 'http://getcomposer.org/version', false));

        if (Composer::VERSION !== $latest) {
            $output->writeln(sprintf("Updating to version <info>%s</info>.", $latest));

            $remoteFilename = 'http://getcomposer.org/composer.phar';
            $localFilename = $_SERVER['argv'][0];
            $tempFilename = $localFilename.'temp';

            $rfs->copy('getcomposer.org', $remoteFilename, $tempFilename);

            try {
                $phar = new \Phar($tempFilename);
                rename($tempFilename, $localFilename);
            } catch (\UnexpectedValueException $e) {
                unlink($tempFilename);
                $output->writeln("<error>The download is corrupt. Please re-run the self-update command.</error>");
            }
        } else {
            $output->writeln("<info>You are using the latest composer version.</info>");
        }
    }
}
