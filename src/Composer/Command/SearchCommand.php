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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\ComposerRepository;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class SearchCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('search')
            ->setDescription('Search for packages')
            ->setDefinition(array(
                new InputArgument('tokens', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'tokens to search for'),
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
        // init repos
        $platformRepo = new PlatformRepository;
        if ($composer = $this->getComposer(false)) {
            $localRepo = $composer->getRepositoryManager()->getLocalRepository();
            $installedRepo = new CompositeRepository(array($localRepo, $platformRepo));
            $repos = new CompositeRepository(array_merge(array($installedRepo), $composer->getRepositoryManager()->getRepositories()));
        } else {
            $output->writeln('No composer.json found in the current directory, showing packages from packagist.org');
            $installedRepo = $platformRepo;
            $repos = new CompositeRepository(array($installedRepo, new ComposerRepository(array('url' => 'http://packagist.org'))));
        }

        $tokens = array_map('strtolower', $input->getArgument('tokens'));
        $packages = array();

        foreach ($repos->getPackages() as $package) {
            foreach ($tokens as $token) {
                if (false === ($pos = strpos($package->getName(), $token))) {
                    continue;
                }

                $name = substr($package->getPrettyName(), 0, $pos)
                    . '<highlight>' . substr($package->getPrettyName(), $pos, strlen($token)) . '</highlight>'
                    . substr($package->getPrettyName(), $pos + strlen($token));
                $version = $installedRepo->hasPackage($package) ? '<info>'.$package->getPrettyVersion().'</info>' : $package->getPrettyVersion();

                $packages[$name][$package->getPrettyVersion()] = $version;
                continue 2;
            }
        }

        foreach ($packages as $name => $versions) {
            $output->writeln($name .' <comment>:</comment> '. join(', ', $versions));
        }
    }
}