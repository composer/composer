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

namespace Composer\Command\Package;

use Composer\Command\Command;
use Composer\Package\PackageInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Listing extends Command
{
    protected function configure()
    {
        $this
            ->setName('list-packages')
            ->setDescription('Lists all existing packages and their version')
            ->setDefinition(array(
                new InputOption('local', null, InputOption::VALUE_NONE, 'list locally installed packages only'),
                new InputOption('platform', null, InputOption::VALUE_NONE, 'list platform packages only'),
            ))
            ->setHelp(<<<EOT
<info>php composer.phar debug:packages</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // create local repo, this contains all packages that are installed in the local project
        $localRepo = $this->getLocalRepository();
        // create installed repo, this contains all local packages + platform packages (php & extensions)
        $installedRepo = $this->getInstalledRepository();

        if ($input->getOption('local')) {
            foreach ($localRepo->getPackages() as $package) {
                $this->printPackage($output, $package, 'local');
            }

            return;
        }

        if ($input->getOption('platform')) {
            $repos = array_diff($installedRepo->getPackages(), $localRepo->getPackages());
            foreach ($repos as $package) {
                $this->printPackage($output, $package, 'platform', 'info');
            }

            return;
        }

        foreach ($installedRepo->getPackages() as $package) {
            if ($localRepo->hasPackage($package)) {
                $this->printPackage($output, $package, 'installed','info');
            } else {
                $this->printPackage($output, $package, 'platform','info');
            }
        }

        foreach ($this->getRepositories() as $repository) {
            foreach ($repository->getPackages() as $package) {
                if (!$installedRepo->hasPackage($package)) {
                    $this->printPackage($output, $package, 'available');
                }
            }
        }
    }
    
    protected function printPackage(OutputInterface $output, PackageInterface $package, $state, $style = 'comment')
    {
        $output->writeln(sprintf('<%s>%s </%s>: %s %s (<comment>%s %s</comment>) ',
            $style,
            $state, 
            $style,
            $package->getPrettyName(),
            $package->getPrettyVersion(),
            $package->getName(),
            $package->getVersion())
        );
    }
}