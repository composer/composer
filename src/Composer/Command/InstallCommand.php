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

use Composer\Installer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Ryan Weaver <ryan@knplabs.com>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class InstallCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('install')
            ->setDescription('Installs the project dependencies from the composer.lock file if present, or falls back on the composer.json.')
            ->setDefinition(array(
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
                new InputOption('prefer-dist', null, InputOption::VALUE_NONE, 'Forces installation from package dist even for dev versions.'),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Outputs the operations but will not execute anything (implicitly enables --verbose).'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Enables installation of dev-require packages.'),
                new InputOption('no-custom-installers', null, InputOption::VALUE_NONE, 'Disables all custom installers.'),
                new InputOption('no-scripts', null, InputOption::VALUE_NONE, 'Skips the execution of all scripts defined in composer.json file.'),
                new InputOption('verbose', 'v', InputOption::VALUE_NONE, 'Shows more details including new commits pulled in when updating packages.'),
            ))
            ->setHelp(<<<EOT
The <info>install</info> command reads the composer.lock file from
the current directory, processes it, and downloads and installs all the
libraries and dependencies outlined in that file. If the file does not
exist it will look for composer.json and do the same.

<info>php composer.phar install</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();
        $io = $this->getIO();
        $install = Installer::create($io, $composer);

        $install
            ->setDryRun($input->getOption('dry-run'))
            ->setVerbose($input->getOption('verbose'))
            ->setPreferSource($input->getOption('prefer-source'))
            ->setPreferDist($input->getOption('prefer-dist'))
            ->setDevMode($input->getOption('dev'))
            ->setRunScripts(!$input->getOption('no-scripts'))
        ;

        if ($input->getOption('no-custom-installers')) {
            $install->disableCustomInstallers();
        }

        return $install->run() ? 0 : 1;
    }
}
