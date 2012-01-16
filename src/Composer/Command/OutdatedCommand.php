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

use Composer\Repository\PlatformRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\LinkConstraint\MultiConstraint;
use Symfony\Component\Console\Input\InputOption;
use Composer\Composer;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class OutdatedCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('outdated')
            ->setDescription('find outdated packages')
            ->setDefinition(array(
            new InputOption('update', null, InputOption::VALUE_NONE, 'update all outdated packages'),
        ))
            ->setHelp(<<<EOT
The outdated command finds packages that could be updated

EOT
        );
    }

    /**
     * executes this command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        //update
        if ($input->hasParameterOption('update')) {
            return $this->doUpdate($input, $output);
        }

        $composer = $this->getComposer();
        //find outdated packages
        $packages = $composer->getRepositoryManager()->findOutdated($composer);

        $this->printResults($packages, $output);
    }

    /**
     * prints the outdated packages if any available
     *
     * @param array $packages
     * @param OutputInterface $output
     */
    protected function printResults(array $packages, OutputInterface $output)
    {
        $pattern = '<info>[%s]</info> installed: <comment>%s</comment> available: <info>%s</info>';

        foreach ($packages as $name => $package) {
            if (!array_key_exists('available', $package) || $package['available'] == $package['installed']) {
                continue;
            }

            $output->writeln(sprintf($pattern, $name, $package['installed']->getVersion(), $package['available']->getVersion()));
        }
    }

    /**
     * updates packages
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function doUpdate(InputInterface $input, OutputInterface $output)
    {
        $installCommand = $this->getApplication()->find('install');

        return $installCommand->install($input, $output, true);
    }
}