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

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class SearchCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('search')
            ->setDescription('search for packages')
            ->setDefinition(array(
                new InputArgument('tokens', InputArgument::IS_ARRAY, 'tokens to search for'),
            ))
            ->setHelp(<<<EOT
The search command searches for packages by its name
<info>php composer.phar search symfony composer</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();

        // create local repo, this contains all packages that are installed in the local project
        $localRepo = $composer->getRepositoryManager()->getLocalRepository();

        foreach ($composer->getRepositoryManager()->getRepositories() as $repository) {
            foreach ($repository->getPackages() as $package) {
                foreach ((array) $input->getArgument('tokens') as $token) {
                    if (false === strpos($package->getName(), $token)) {
                        continue;
                    }

                    $state = $localRepo->hasPackage($package) ? '<info>installed</info>' : $state = '<comment>available</comment>';

                    $output->writeln($state . ': ' . $package->getPrettyName() . ' <comment>' . $package->getPrettyVersion() . '</comment>');
                }
            }
        }
    }
}