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
        $composer = $this->getComposer();

        $latest = file_get_contents('http://getcomposer.org/version');

        if (Composer::VERSION !== $latest) {
            $output->writeln(sprintf("Updating to version %s.", $latest));

            $remoteFilename = 'http://getcomposer.org/composer.phar';
            $localFilename = getcwd().'/composer.phar';

            file_put_contents($localFilename, file_get_contents($remoteFilename));
        } else {
            $output->writeln("You are using the latest composer version.");
        }
    }
}
