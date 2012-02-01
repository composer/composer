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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Show extends Command
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
        $package = $this->getPackage($input, $output);
        if (!$package) {
            throw new \InvalidArgumentException('no package found');
        }

        $this->printMeta($input, $output, $package);
        $this->printLinks($input, $output, $package, 'requires');
        $this->printLinks($input, $output, $package, 'recommends');
        $this->printLinks($input, $output, $package, 'replaces');
    }

    /**
     * prints package meta data
     */
    protected function printMeta(InputInterface $input, OutputInterface $output, PackageInterface $package)
    {
        $output->writeln('<info>name</info>     : ' . $package->getPrettyName());
        $this->printVersions($input, $output, $package);
        $output->writeln('<info>type</info>     : ' . $package->getType());
        $output->writeln('<info>names</info>    : ' . join(', ', $package->getNames()));
        $output->writeln('<info>source</info>   : ' . sprintf('[%s] <comment>%s</comment> %s', $package->getSourceType(), $package->getSourceUrl(), $package->getSourceReference()));
        $output->writeln('<info>dist</info>     : ' . sprintf('[%s] <comment>%s</comment> %s', $package->getDistType(), $package->getDistUrl(), $package->getDistReference()));
        $output->writeln('<info>licence</info>  : ' . join(', ', $package->getLicense()));

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
    protected function printVersions(InputInterface $input, OutputInterface $output, PackageInterface $package)
    {
        if ($input->getArgument('version')) {
            $output->writeln('<info>version</info>  : ' . $package->getPrettyVersion());
            return;
        }

        $versions = array();

        foreach ($this->getRepositories() as $repository) {
            foreach ($repository->findPackagesByName($package->getName()) as $version) {
                $versions[] = $version->getPrettyVersion();
            }
        }

        $versions = join(', ', $versions);

        // highlight installed version
        if ($this->getLocalRepository()->hasPackage($package)) {
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