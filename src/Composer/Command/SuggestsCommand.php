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

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SuggestsCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('suggests')
            ->setDescription('Show package suggestions')
            ->setDefinition(array(
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Exclude suggestions from require-dev packages'),
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Packages that you want to list suggestions from.'),
            ))
            ->setHelp(<<<EOT

The <info>%command.name%</info> command shows suggested packages.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $lock = $this->getComposer()->getLocker()->getLockData();

        if (empty($lock)) {
            throw new \RuntimeException('Lockfile seems to be empty?');
        }

        $io = $this->getIO();
        $list = $lock['packages'];

        if (!$input->getOption('no-dev')) {
            $list += $lock['packages-dev'];
        }

        $packages = $input->getArgument('packages');

        foreach ($list as $package) {
            if (!empty($package['suggest']) && (empty($packages) || in_array($package['name'], $packages))) {
                $this->printSuggestions($package['name'], $package['suggest']);
            }
        }
    }

    protected function printSuggestions($name, $suggests)
    {
        $io = $this->getIO();

        foreach ($suggests as $target => $reason) {
            if (empty($reason)) {
                $reason = '*';
            }

            if ($io->isVeryVerbose()) {
                $io->write(sprintf('<comment>%s</comment> suggests <info>%s</info>: %s', $name, $target, $reason));
            } elseif ($io->isVerbose()) {
                $io->write(sprintf('<comment>%s</comment> suggests <info>%s</info>', $name, $target));
            } else {
                $io->write(sprintf('<info>%s</info>', $target));
            }
        }
    }
}
