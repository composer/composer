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
 * @author Gusakov Nikita <gusakov.nik@gmail.com>
 */
class SuggestsCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('suggests')
            ->setDescription('Show packages suggests')
            ->setDefinition(array(
                new InputOption('with-dev', 'D', InputOption::VALUE_NONE, 'Show dev suggests'),
            ))
            ->setHelp(<<<EOT

The <info>suggests</info> command show packages that suggesting to install other packages.
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $lock = $this->getComposer()->getLocker()->getLockData();

        $output->writeln('<comment>Suggests:</comment>');
        $this->outSuggests($output, $lock['packages']);

        if ( $input->getOption('with-dev') ) {
            $output->writeln('<comment>Dev suggests:</comment>');
            $this->outSuggests($output, $lock['packages-dev']);
        }

    }

    protected function outSuggests(OutputInterface $output, array $packages)
    {
        $isHas = false;
        foreach ($packages as $package) {
            if ( isset($package['suggest']) ) {
                $isHas = true;
                $output->writeln(sprintf('  <info>%s</info>:', $package['name']));
                foreach ($package['suggest'] as $name => $ver) {
                    $output->writeln(sprintf('    "%s": "%s"', $name, $ver));
                }
            }
        }
        if ( !$isHas ) {
            $output->writeln('  Nothing!');
        }
    }
}
