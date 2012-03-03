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

use Composer\Install;
use Composer\Script\EventDispatcher;
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
            ->setDescription('Parses the composer.json file and downloads the needed dependencies.')
            ->setDefinition(array(
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Outputs the operations but will not execute anything (implicitly enables --verbose).'),
                new InputOption('no-install-recommends', null, InputOption::VALUE_NONE, 'Do not install recommended packages (ignored when installing from an existing lock file).'),
                new InputOption('install-suggests', null, InputOption::VALUE_NONE, 'Also install suggested packages (ignored when installing from an existing lock file).'),
            ))
            ->setHelp(<<<EOT
The <info>install</info> command reads the composer.json file from the
current directory, processes it, and downloads and installs all the
libraries and dependencies outlined in that file.

<info>php composer.phar install</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();
        $io = $this->getApplication()->getIO();
        $eventDispatcher = new EventDispatcher($composer, $io);
        $install = new Install;

        return $install->run(
            $io,
            $composer,
            $eventDispatcher,
            (Boolean)$input->getOption('prefer-source'),
            (Boolean)$input->getOption('dry-run'),
            (Boolean)$input->getOption('verbose'),
            (Boolean)$input->getOption('no-install-recommends'),
            (Boolean)$input->getOption('install-suggests')
        );
    }
}
