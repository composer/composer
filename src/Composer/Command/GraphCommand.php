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

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

use Composer\Package\Locker;
use Composer\Grapher\Grapher;
use Composer\Grapher\RepositoryGraphBuilder;
use Composer\Grapher\D3GraphOutput;

/**
 * Graphs installed project dependencies with D3.js.
 *
 * @author Felix Jodoin <felix@fjstudios.net>
 */
class GraphCommand extends Command
{

    protected function configure()
    {
        $this
            ->setName('graph')
            ->setDescription('Graphs installed project dependencies from an existing composer.lock.')
            ->setDefinition(array(
                    new InputArgument('path', InputArgument::REQUIRED, 'Path to output rendered pages'),
                ))
            ->setHelp(<<<HERE
The <info>graph</info> command reads the requirements of
each project dependency as specified in an existing composer.lock.
The composer.lock must have already been created by Composer
from an <info>install</info> operation, after a successful resolution
of dependencies.

<info>php composer.phar graph</info>

HERE
                )
            ;
    }

    protected function execute(InputInterface $consoleInput, OutputInterface $consoleOutput)
    {
        $composer = $this->getComposer();
        $locker = $composer->getLocker();

        $builder = new RepositoryGraphBuilder($locker->getLockedRepository());
        $graphOutput = new D3GraphOutput();
        $grapher = new Grapher($builder, $graphOutput);

        $path = $consoleInput->getArgument('path');

        file_put_contents($path, $grapher->graph());

        $consoleOutput->writeln('<info>Graphed dependencies to '.$path.'</info>');
    }
}
