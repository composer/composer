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
use Composer\Package\PackageInterface;
use Composer\Package\AliasPackage;
use Composer\Factory;

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
            $packagist = new ComposerRepository(array('url' => 'http://packagist.org'), $this->getIO(), Factory::createConfig());
            $repos = new CompositeRepository(array($installedRepo, $packagist));
        }

        $tokens = $input->getArgument('tokens');
        $packages = array();

        foreach ($repos->getPackages() as $package) {
            if ($package instanceof AliasPackage || isset($packages[$package->getName()])) {
                continue;
            }

            foreach ($tokens as $token) {
                if (!$this->matchPackage($package, $token)) {
                    continue;
                }

                if (false !== ($pos = stripos($package->getName(), $token))) {
                    $name = substr($package->getPrettyName(), 0, $pos)
                        . '<highlight>' . substr($package->getPrettyName(), $pos, strlen($token)) . '</highlight>'
                        . substr($package->getPrettyName(), $pos + strlen($token));
                } else {
                    $name = $package->getPrettyName();
                }

                $packages[$package->getName()] = array(
                    'name' => $name,
                    'description' => strtok($package->getDescription(), "\r\n")
                );
                continue 2;
            }
        }

        foreach ($packages as $details) {
            $output->writeln($details['name'] .' <comment>:</comment> '. $details['description']);
        }
    }

    /**
     * tries to find a token within the name/keywords/description
     *
     * @param PackageInterface $package
     * @param string $token
     * @return boolean
     */
    private function matchPackage(PackageInterface $package, $token)
    {
        return (false !== stripos($package->getName(), $token))
            || (false !== stripos(join(',', $package->getKeywords() ?: array()), $token))
            || (false !== stripos($package->getDescription(), $token))
        ;
    }
}