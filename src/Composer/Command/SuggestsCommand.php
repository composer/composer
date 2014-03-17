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

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Gusakov Nikita <dev@nkt.me>
 */
class SuggestsCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('suggests')
            ->setDescription('Show packages suggests')
            ->setDefinition(array(
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Show dev suggests'),
            ))
            ->setHelp(<<<EOT

The <info>suggests</info> command show packages that suggesting to install other packages.

EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $lockData = $this->getComposer()->getLocker()->getLockData();
        $this->printSuggests($output, $lockData['packages']);
        if ($input->getOption('dev')) {
            $this->printSuggests($output, $lockData['packages-dev']);
        }
    }

    private function printSuggests(OutputInterface $output, array $packages)
    {
        foreach ($packages as $package) {
            if (isset($package['suggest'])) {
                foreach ($package['suggest'] as $target => $reason) {
                    $output->writeln($package['name'].' suggests installing '.$target.' ('.$reason.')');
                }
            }
        }
    }
}
