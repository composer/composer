<?php

namespace Composer\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
          new InputOption('dev', null, InputOption::VALUE_NONE, 'Enables the graphing of dev-require packages.')
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

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $composer = $this->getComposer();
    $locker = $composer->getLocker();

    $builder = new RepositoryGraphBuilder($locker->getLockedRepository());
    $output = new D3GraphOutput();
    $grapher = new Grapher($builder, $output);
    
    $path = $input->getArgument('path');

    $grapher->graph($path);
  }  
}