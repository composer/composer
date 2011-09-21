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

use Composer\DependencyResolver;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Symfony\Component\Console\Input\InputInterface;
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
            ->setHelp(<<<EOT
The <info>install</info> command reads the composer.json file from the
current directory, processes it, and downloads and installs all the
libraries and dependencies outlined in that file.

<info>php composer install</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->getLock()->isLocked()) {
            $output->writeln('<info>Found lockfile. Reading</info>');

            foreach ($this->getLock()->getLockedPackages() as $package) {
                $installer = $this->getComposer()->getInstaller($package->getType());
                if (!$installer->isInstalled($package)) {
                    $installer->install($package);
                }
            }

            return 0;
        }

        // creating repository pool
        $pool = new Pool;
        foreach ($this->getComposer()->getRepositories() as $repository) {
            $pool->addRepository($repository);
        }

        // creating requirements request
        $request = new Request($pool);
        foreach ($this->getPackage()->getRequires() as $link) {
            $request->install($link->getTarget(), $link->getConstraint());
        }

        // prepare solver
        $platform = $this->getComposer()->getRepository('Platform');
        $policy   = new DependencyResolver\DefaultPolicy();
        $solver   = new DependencyResolver\Solver($policy, $pool, $platform);

        // solve dependencies and execute operations
        $operations = $this->solveDependencies($request, $solver);
        foreach ($operations as $operation) {
            $operation->execute();
            // TODO: collect installable packages into $installed
        }

        $output->writeln('> Done');

        if (false) {
            $config->lock($installed);
            $output->writeln('> Locked');
        }
    }
}
