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

With <info>-v</info> you also see which package suggested it and why.

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

        $packages = $lock['packages'];

        if (!$input->getOption('no-dev')) {
            $packages += $lock['packages-dev'];
        }

        $filter = $input->getArgument('packages');

        foreach ($packages as $package) {
            if (empty($package['suggest'])) {
                continue;
            }

            if (!empty($filter) && !in_array($package['name'], $filter)) {
                continue;
            }

            $this->printSuggestions($packages, $package['name'], $package['suggest']);
        }
    }

    protected function printSuggestions($installed, $source, $suggestions)
    {
        foreach ($suggestions as $suggestion => $reason) {
            foreach ($installed as $package) {
                if ($package['name'] === $suggestion) {
                    continue 2;
                }
            }

            if (empty($reason)) {
                $reason = '*';
            }

            $this->printSuggestion($source, $suggestion, $reason);
        }
    }

    protected function printSuggestion($package, $suggestion, $reason)
    {
        $io = $this->getIO();

        if ($io->isVerbose()) {
            $io->write(sprintf('<comment>%s</comment> suggests <info>%s</info>: %s', $package, $suggestion, $reason));
        } else {
            $io->write(sprintf('<info>%s</info>', $suggestion));
        }
    }
}
