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

use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Benoît Merlet <benoit.merlet@gmail.com>
 */
class LicensesCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('licenses')
            ->setDescription('Show information about licenses of dependencies')
            ->setDefinition(array(
                new InputOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format of the output: flat or json', 'flat'),
            ))
            ->setHelp(<<<EOT
The license command displays detailed information about the licenses of
the installed dependencies.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $root = $this->getComposer()->getPackage();
        $repo = $this->getComposer()->getRepositoryManager()->getLocalRepository();

        $versionParser = new VersionParser;

        $nameLength = strlen($root->getPrettyName());
        $versionLength = strlen($versionParser->formatVersion($root));

        foreach ($repo->getPackages() as $package) {
            $packages[$package->getName()] = $package;

            $nameLength    = max($nameLength, strlen($package->getPrettyName()));
            $versionLength = max($versionLength, strlen($versionParser->formatVersion($package)));
        }

        ksort($packages);

        switch ($format = $input->getOption('format')) {
            case 'flat':
                $formatRowCallback = function (PackageInterface $package) use ($versionParser, $nameLength, $versionLength) {
                    return sprintf(
                        '  %s  %s  %s',
                        str_pad($package->getPrettyName(), $nameLength, ' '),
                        str_pad($versionParser->formatVersion($package), $versionLength, ' '),
                        implode(', ', $package->getLicense()) ?: 'none'
                    );
                };

                $output->writeln('Root Package:');
                $output->writeln($formatRowCallback($root));
                $output->writeln('Dependencies:');
                foreach ($packages as $package) {
                    $output->writeln($formatRowCallback($package));
                }
                break;

            case 'json':
                foreach ($packages as $package) {
                    $dependencies[$package->getPrettyName()] = array(
                        'version' => $versionParser->formatVersion($package),
                        'license' => $package->getLicense(),
                    );
                }

                $output->writeln(json_encode(array(
                    'name'         => $root->getPrettyName(),
                    'version'      => $versionParser->formatVersion($root),
                    'license'      => $root->getLicense(),
                    'dependencies' => $dependencies,
                )));
                break;

            default:
                $output->writeln(sprintf('Unsupported format "%s".  See help for supported formats.', $format));
                break;
        }
    }
}
