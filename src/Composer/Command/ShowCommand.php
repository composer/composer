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

use Composer\Composer;
use Composer\Factory;
use Composer\Package\PackageInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryInterface;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ShowCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('show')
            ->setDescription('Show information about packages')
            ->setDefinition(array(
                new InputArgument('package', InputArgument::OPTIONAL, 'Package to inspect'),
                new InputArgument('version', InputArgument::OPTIONAL, 'Version to inspect'),
                new InputOption('installed', null, InputOption::VALUE_NONE, 'List installed packages only'),
                new InputOption('platform', null, InputOption::VALUE_NONE, 'List platform packages only'),
            ))
            ->setHelp(<<<EOT
The show command displays detailed information about a package, or
lists all packages available.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // init repos
        $platformRepo = new PlatformRepository;
        if ($input->getOption('platform')) {
            $repos = $installedRepo = $platformRepo;
        } elseif ($input->getOption('installed')) {
            $composer = $this->getComposer();
            $repos = $installedRepo = $composer->getRepositoryManager()->getLocalRepository();
        } elseif ($composer = $this->getComposer(false)) {
            $localRepo = $composer->getRepositoryManager()->getLocalRepository();
            $installedRepo = new CompositeRepository(array($localRepo, $platformRepo));
            $repos = new CompositeRepository(array_merge(array($installedRepo), $composer->getRepositoryManager()->getRepositories()));
        } else {
            $output->writeln('No composer.json found in the current directory, showing packages from packagist.org');
            $installedRepo = $platformRepo;
            $packagist = new ComposerRepository(array('url' => 'http://packagist.org'), $this->getIO(), Factory::createConfig());
            $repos = new CompositeRepository(array($installedRepo, $packagist));
        }

        // show single package or single version
        if ($input->getArgument('package')) {
            $package = $this->getPackage($input, $output, $installedRepo, $repos);
            if (!$package) {
                throw new \InvalidArgumentException('Package '.$input->getArgument('package').' not found');
            }

            $this->printMeta($input, $output, $package, $installedRepo, $repos);
            $this->printLinks($input, $output, $package, 'requires');
            $this->printLinks($input, $output, $package, 'devRequires', 'requires (dev)');
            if ($package->getSuggests()) {
                $output->writeln("\n<info>suggests</info>");
                foreach ($package->getSuggests() as $suggested => $reason) {
                    $output->writeln($suggested . ' <comment>' . $reason . '</comment>');
                }
            }
            $this->printLinks($input, $output, $package, 'provides');
            $this->printLinks($input, $output, $package, 'conflicts');
            $this->printLinks($input, $output, $package, 'replaces');
            return;
        }

        // list packages
        $packages = array();
        foreach ($repos->getPackages() as $package) {
            if ($platformRepo->hasPackage($package)) {
                $type = '<info>platform</info>:';
            } elseif ($installedRepo->hasPackage($package)) {
                $type = '<info>installed</info>:';
            } else {
                $type = '<comment>available</comment>:';
            }
            if (isset($packages[$type][$package->getName()])
                && version_compare($packages[$type][$package->getName()]->getVersion(), $package->getVersion(), '>=')
            ) {
                continue;
            }
            $packages[$type][$package->getName()] = $package;
        }

        foreach (array('<info>platform</info>:', '<comment>available</comment>:', '<info>installed</info>:') as $type) {
            if (isset($packages[$type])) {
                $output->writeln($type);
                ksort($packages[$type]);
                foreach ($packages[$type] as $package) {
                    $output->writeln('  '.$package->getPrettyName() .' <comment>:</comment> '. strtok($package->getDescription(), "\r\n"));
                }
                $output->writeln('');
            }
        }
    }

    /**
     * finds a package by name and version if provided
     *
     * @param InputInterface $input
     * @return PackageInterface
     * @throws \InvalidArgumentException
     */
    protected function getPackage(InputInterface $input, OutputInterface $output, RepositoryInterface $installedRepo, RepositoryInterface $repos)
    {
        // we have a name and a version so we can use ::findPackage
        if ($input->getArgument('version')) {
            return $repos->findPackage($input->getArgument('package'), $input->getArgument('version'));
        }

        // check if we have a local installation so we can grab the right package/version
        foreach ($installedRepo->getPackages() as $package) {
            if ($package->getName() === $input->getArgument('package')) {
                return $package;
            }
        }

        // we only have a name, so search for the highest version of the given package
        $highestVersion = null;
        foreach ($repos->findPackages($input->getArgument('package')) as $package) {
            if (null === $highestVersion || version_compare($package->getVersion(), $highestVersion->getVersion(), '>=')) {
                $highestVersion = $package;
            }
        }

        return $highestVersion;
    }

    /**
     * prints package meta data
     */
    protected function printMeta(InputInterface $input, OutputInterface $output, PackageInterface $package, RepositoryInterface $installedRepo, RepositoryInterface $repos)
    {
        $output->writeln('<info>name</info>     : ' . $package->getPrettyName());
        $output->writeln('<info>descrip.</info> : ' . $package->getDescription());
        $output->writeln('<info>keywords</info> : ' . join(', ', $package->getKeywords() ?: array()));
        $this->printVersions($input, $output, $package, $installedRepo, $repos);
        $output->writeln('<info>type</info>     : ' . $package->getType());
        $output->writeln('<info>license</info>  : ' . implode(', ', $package->getLicense()));
        $output->writeln('<info>source</info>   : ' . sprintf('[%s] <comment>%s</comment> %s', $package->getSourceType(), $package->getSourceUrl(), $package->getSourceReference()));
        $output->writeln('<info>dist</info>     : ' . sprintf('[%s] <comment>%s</comment> %s', $package->getDistType(), $package->getDistUrl(), $package->getDistReference()));
        $output->writeln('<info>names</info>    : ' . implode(', ', $package->getNames()));

        if ($package->getAutoload()) {
            $output->writeln("\n<info>autoload</info>");
            foreach ($package->getAutoload() as $type => $autoloads) {
                $output->writeln('<comment>' . $type . '</comment>');

                if ($type === 'psr-0') {
                    foreach ($autoloads as $name => $path) {
                        $output->writeln(($name ?: '*') . ' => ' . ($path ?: '.'));
                    }
                } elseif ($type === 'classmap') {
                    $output->writeln(implode(', ', $autoloads));
                }
            }
        }
    }

    /**
     * prints all available versions of this package and highlights the installed one if any
     */
    protected function printVersions(InputInterface $input, OutputInterface $output, PackageInterface $package, RepositoryInterface $installedRepo, RepositoryInterface $repos)
    {
        if ($input->getArgument('version')) {
            $output->writeln('<info>version</info>  : ' . $package->getPrettyVersion());
            return;
        }

        $versions = array();

        foreach ($repos->findPackages($package->getName()) as $version) {
            $versions[$version->getPrettyVersion()] = $version->getVersion();
        }

        uasort($versions, 'version_compare');

        $versions = implode(', ', array_keys(array_reverse($versions)));

        // highlight installed version
        if ($installedRepo->hasPackage($package)) {
            $versions = str_replace($package->getPrettyVersion(), '<info>* ' . $package->getPrettyVersion() . '</info>', $versions);
        }

        $output->writeln('<info>versions</info> : ' . $versions);
    }

    /**
     * print link objects
     *
     * @param string $linkType
     */
    protected function printLinks(InputInterface $input, OutputInterface $output, PackageInterface $package, $linkType, $title = null)
    {
        $title = $title ?: $linkType;
        if ($links = $package->{'get'.ucfirst($linkType)}()) {
            $output->writeln("\n<info>" . $title . "</info>");

            foreach ($links as $link) {
                $output->writeln($link->getTarget() . ' <comment>' . $link->getPrettyConstraint() . '</comment>');
            }
        }
    }
}
