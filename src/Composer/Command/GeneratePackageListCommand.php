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
use Symfony\Component\Console\Output\OutputInterface;
use \Composer\Package\Dumper\ArrayDumper;

/**
 * @author Per Bernhardt <plb@webfactory.de>
 */
class GeneratePackageListCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('generate:package-list')
            ->setDescription('Generates a list off all available packages in json format. Can be used to setup a simple composer repository.')
            ->setHelp(<<<EOT
<info>php composer.phar generate:packages-list</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();
        $dumper = new ArrayDumper();

        $packages = array();

        foreach ($composer->getRepositoryManager()->getRepositories() as $repository) {
            foreach ($repository->getPackages() as $package) {
                $packages[$package->getName()]['versions'][$package->getVersion()] = $dumper->dump($package);
            }
        }

        $output->write(json_encode($packages));
    }
}
