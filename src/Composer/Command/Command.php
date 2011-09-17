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

use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Solver;
use Composer\Installer\Operation;

/**
 * Base class for Composer commands
 *
 * @author Ryan Weaver <ryan@knplabs.com>
 * @authro Konstantin Kudryashov <ever.zet@gmail.com>
 */
abstract class Command extends BaseCommand
{
    /**
     * @return \Composer\Composer
     */
    protected function getComposer()
    {
        return $this->getApplication()->getComposer();
    }

    protected function solveDependencies(Request $request, Solver $solver)
    {
        $operations = array();
        foreach ($solver->solve($request) as $task) {
            $installer = $this->getComposer()->getInstaller($task['package']->getType());
            $operation = new Operation($installer, $task['job'], $task['package']);

            $operations[] = $operation;
        }

        return $operations;
    }
}
