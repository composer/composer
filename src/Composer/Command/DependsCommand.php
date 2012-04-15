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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Justin Rainbow <justin.rainbow@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class DependsCommand extends Command
{
    protected $linkTypes = array(
        'require' => 'requires',
        'require-dev' => 'devRequires',
    );

    protected function configure()
    {
        $this
            ->setName('depends')
            ->setDescription('Shows which packages depend on the given package')
            ->setDefinition(array(
                new InputArgument('package', InputArgument::REQUIRED, 'Package to inspect'),
                new InputOption('link-type', '', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Link types to show (require, require-dev)', array_keys($this->linkTypes))
            ))
            ->setHelp(<<<EOT
Displays detailed information about where a package is referenced.

<info>php composer.phar depends composer/composer</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();
        $references = $this->getReferences($input, $output, $composer);

        if ($input->getOption('verbose')) {
            $this->printReferences($input, $output, $references);
        } else {
            $this->printPackages($input, $output, $references);
        }
    }

    /**
     * finds a list of packages which depend on another package
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param Composer $composer
     * @return array
     * @throws \InvalidArgumentException
     */
    private function getReferences(InputInterface $input, OutputInterface $output, Composer $composer)
    {
        $needle = $input->getArgument('package');

        $references = array();
        $verbose = (Boolean) $input->getOption('verbose');

        $repos = $composer->getRepositoryManager()->getRepositories();
        $types = $input->getOption('link-type');

        foreach ($repos as $repository) {
            foreach ($repository->getPackages() as $package) {
                foreach ($types as $type) {
                    $type = rtrim($type, 's');
                    if (!isset($this->linkTypes[$type])) {
                        throw new \InvalidArgumentException('Unexpected link type: '.$type.', valid types: '.implode(', ', array_keys($this->linkTypes)));
                    }
                    foreach ($package->{'get'.$this->linkTypes[$type]}() as $link) {
                        if ($link->getTarget() === $needle) {
                            if ($verbose) {
                                $references[] = array($type, $package, $link);
                            } else {
                                $references[$package->getName()] = $package->getPrettyName();
                            }
                        }
                    }
                }
            }
        }

        return $references;
    }

    private function printReferences(InputInterface $input, OutputInterface $output, array $references)
    {
        foreach ($references as $ref) {
            $output->writeln($ref[1]->getPrettyName() . ' ' . $ref[1]->getPrettyVersion() . ' <info>' . $ref[0] . '</info> ' . $ref[2]->getPrettyConstraint());
        }
    }

    private function printPackages(InputInterface $input, OutputInterface $output, array $packages)
    {
        ksort($packages);
        foreach ($packages as $package) {
            $output->writeln($package);
        }
    }
}