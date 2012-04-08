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
use Composer\Autoload\AutoloadGenerator;
use Composer\Autoload\ClassMapGenerator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Alexander Deruwe <alexander.deruwe@gmail.com>
 */
class GenerateClassMapCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('generate-classmap')
            ->setDescription('Generate complete class map for autoload')
            ->setHelp(<<<EOT
The generate-classmap command analyzes your composer.json and
generates an up-to-date class map for quick autoloading.
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();
        $dirs = $this->getDirsToBeMapped($composer);
        $destination = $composer->getInstallationManager()->getVendorPath().'/.composer/autoload_generatedclassmap.php';

        ClassMapGenerator::dump($dirs, $destination);
    }

    private function getDirsToBeMapped(Composer $composer)
    {
        $localRepository = $composer->getRepositoryManager()->getLocalRepository();
        $generator = new AutoloadGenerator();
        $packageMap = $generator->buildPackageMap($composer->getInstallationManager(), $composer->getPackage(), $localRepository->getPackages());
        $autoloads = $generator->parseAutoloads($packageMap);
        $dirs = array();

        foreach ($autoloads['psr-0'] as $namespace => $destinations) {
            $dirs = array_merge($dirs, $destinations);
        }

        return $dirs;
    }
}
