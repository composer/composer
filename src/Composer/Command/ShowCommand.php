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
use Composer\Package\Dumper\ArrayDumper;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class ShowCommand extends Command
{

    //holds the array dump of an package
    private $dump;
    private $output;

    protected function configure()
    {
        $this
            ->setName('show')
            ->setDescription('show package details')
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
        $dumper = new ArrayDumper();
        $this->output = $output;
        $this->dump = $dumper->dump($this->getPackage($input));

        $this->printMeta($input);
        $this->printLinks('requires');
        $this->printLinks('recommends');
        $this->printLinks('replaces');
    }

    /**
     * finds a package by name and version if provided
     * 
     * @param InputInterface $input
     * @return PackageInterface
     * @throws \InvalidArgumentException 
     */
    protected function getPackage(InputInterface $input)
    {
        $composer = $this->getComposer();
        $localRepo = $composer->getRepositoryManager()->getLocalRepository();        

        //we have a name and a version so we can use ::findPackage
        if ($input->getArgument('version')) {
            return $composer->getRepositoryManager()->findPackage($input->getArgument('package'), $input->getArgument('version'));
        }
        
        //check if we have a local installation so we can grab the right package/version
        foreach ($localRepo->getPackages() as $package) {
            if ($package->getName() === $input->getArgument('package')) {
                return $package;
            }
        }

        //we only have a name, so search for the first package where the name matches
        foreach ($composer->getRepositoryManager()->getRepositories() as $repository) {
            foreach ($repository->getPackages() as $package) {
                if ($package->getName() == $input->getArgument('package')) {
                    return $package;
                }
            }
        }            
        
        throw new \InvalidArgumentException('no package found');
    }

    /**
     * prints package meta data
     */
    protected function printMeta(InputInterface $input)
    {
        $this->output->writeln('<info>name</info>     :  ' . $this->dump['name']);
        $this->printVersions($input);
        $this->output->writeln('<info>type</info>     : ' . $this->dump['type']);
        $this->output->writeln('<info>names</info>    : ' . join(', ', $this->dump['names']));
        $this->output->writeln('<info>source</info>   : ' . sprintf('[%s] <comment>%s</comment> %s', $this->dump['source']['type'], $this->dump['source']['url'], $this->dump['source']['reference']));
        $this->output->writeln('<info>dist</info>     : ' . sprintf('[%s] <comment>%s</comment> %s', $this->dump['dist']['type'], $this->dump['dist']['url'], $this->dump['dist']['reference']));
        $this->output->writeln('<info>licence</info>  : ' . join(', ', $this->dump['license']));
        $this->output->writeln("\n<info>autoload</info>");

        if (array_key_exists('autoload', $this->dump)) {
            foreach ($this->dump['autoload'] as $type => $autoloads) {
                $this->output->writeln('<comment>' . $type . '</comment>');
                
                foreach ($autoloads as $name => $path) {
                    $this->output->writeln($name .' : '. $path);
                }
            }
        }
    }

    /**
     * prints all available versions of this package and highlights the installed one if any
     */
    protected function printVersions(InputInterface $input)
    {
        if ($input->getArgument('version')) {
            $this->output->writeln('<info>version</info>  : ' . $this->dump['version_normalized']);
            return;
        }
        
        $versions = array();

        foreach ($this->getComposer()->getRepositoryManager()->getRepositories() as $repository) {
            foreach ($repository->getPackages() as $package) {
                if ($package->getName() === $this->dump['name']) {
                    $versions[] = $package->getPrettyVersion();
                }
            }
        }

        $versions = str_replace($this->dump['version'], '<info>' . $this->dump['version'] . '</info>', join(', ', $versions));

        $this->output->writeln('<info>versions</info> : ' . $versions);
    }

    /**
     * print link objects
     * 
     * @param string $linksName 
     */
    protected function printLinks($linksName)
    {
        if (isset($this->dump[$linksName])) {

            $this->output->writeln("\n<info>" . $linksName . "</info>");

            foreach ($this->dump[$linksName] as $link) {
                $this->output->writeln($link->getTarget() . ' <comment>' . $link->getPrettyConstraint() . '</comment>');
            }
        }
    }
}