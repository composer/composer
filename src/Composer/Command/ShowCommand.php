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
use Composer\Package\PackageInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\ComposerRepository;

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
            ->setDescription('Show package details')
            ->setDefinition(array(
                new InputArgument('package', InputArgument::REQUIRED, 'the package to inspect'),
                new InputArgument('version', InputArgument::OPTIONAL, 'the version'),
            ))
            ->setHelp(<<<EOT
The show command displays detailed information about a package
<info>php composer.phar show composer/composer master-dev</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($composer = $this->getComposer(false)) {
            $localRepo = $composer->getRepositoryManager()->getLocalRepository();
            $installedRepo = new CompositeRepository(array($localRepo, new PlatformRepository()));
            $repos = new CompositeRepository(array_merge(array($installedRepo), $composer->getRepositoryManager()->getRepositories()));
        } else {
            $output->writeln('No composer.json found in the current directory, showing packages from packagist.org');
            $installedRepo = new PlatformRepository;
            $repos = new CompositeRepository(array($installedRepo, new ComposerRepository(array('url' => 'http://packagist.org'))));
        }

        $package = $this->getPackage($input, $output, $installedRepo, $repos);
        if (!$package) {
            throw new \InvalidArgumentException('no package found');
        }

        $this->printMeta($input, $output, $package, $installedRepo, $repos);
        $this->printLinks($input, $output, $package, 'requires');
        $this->printLinks($input, $output, $package, 'recommends');
        $this->printLinks($input, $output, $package, 'replaces');
    }

    /**
     * finds a package by name and version if provided
     *
     * @param InputInterface $input
     * @return PackageInterface
     * @throws \InvalidArgumentException
     */
    protected function getPackage(InputInterface $input, OutputInterface $output, $installedRepo, $repos)
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
        foreach ($repos->findPackagesByName($input->getArgument('package')) as $package) {
            if (null === $highestVersion || version_compare($package->getVersion(), $highestVersion->getVersion(), '>=')) {
                $highestVersion = $package;
            }
        }

        return $highestVersion;
    }

    /**
     * prints package meta data
     */
    protected function printMeta(InputInterface $input, OutputInterface $output, PackageInterface $package, $installedRepo, $repos)
    {
        $output->writeln('<info>name</info>     : ' . $package->getPrettyName());
        $this->printVersions($input, $output, $package, $installedRepo, $repos);
        $output->writeln('<info>type</info>     : ' . $package->getType());
        $output->writeln('<info>names</info>    : ' . join(', ', $package->getNames()));
        $output->writeln('<info>source</info>   : ' . sprintf('[%s] <comment>%s</comment> %s', $package->getSourceType(), $package->getSourceUrl(), $package->getSourceReference()));
        $output->writeln('<info>dist</info>     : ' . sprintf('[%s] <comment>%s</comment> %s', $package->getDistType(), $package->getDistUrl(), $package->getDistReference()));
        $output->writeln('<info>license</info>  : ' . join(', ', $package->getLicense()));

        if ($package->getAutoload()) {
            $output->writeln("\n<info>autoload</info>");
            foreach ($package->getAutoload() as $type => $autoloads) {
                $output->writeln('<comment>' . $type . '</comment>');

                foreach ($autoloads as $name => $path) {
                    $output->writeln($name . ' : ' . ($path ?: '.'));
                }
            }
        }
    }

    /**
     * prints all available versions of this package and highlights the installed one if any
     */
    protected function printVersions(InputInterface $input, OutputInterface $output, PackageInterface $package, $installedRepo, $repos)
    {
        if ($input->getArgument('version')) {
            $output->writeln('<info>version</info>  : ' . $package->getPrettyVersion());
            return;
        }

        $versions = array();

        foreach ($repos->findPackagesByName($package->getName()) as $version) {
            $versions[] = $version->getPrettyVersion();
        }

        $versions = join(', ', $versions);

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
    protected function printLinks(InputInterface $input, OutputInterface $output, PackageInterface $package, $linkType)
    {
        if ($links = $package->{'get'.ucfirst($linkType)}()) {
            $output->writeln("\n<info>" . $linkType . "</info>");

            foreach ($links as $link) {
                $output->writeln($link->getTarget() . ' <comment>' . $link->getPrettyConstraint() . '</comment>');
            }
        }
    }
}