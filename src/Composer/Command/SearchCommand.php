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

use Composer\Factory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class SearchCommand extends BaseCommand
{
    protected $matches;
    protected $lowMatches = array();
    protected $tokens;
    protected $output;
    protected $onlyName;

    protected function configure()
    {
        $this
            ->setName('search')
            ->setDescription('Search for packages.')
            ->setDefinition(array(
                new InputOption('only-name', 'N', InputOption::VALUE_NONE, 'Search only in name'),
                new InputOption('type', 't', InputOption::VALUE_REQUIRED, 'Search for a specific package type'),
                new InputArgument('tokens', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'tokens to search for'),
            ))
            ->setHelp(<<<EOT
The search command searches for packages by its name
<info>php composer.phar search symfony composer</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // init repos
        $platformRepo = new PlatformRepository;
        $io = $this->getIO();
        if (!($composer = $this->getComposer(false))) {
            $composer = Factory::create($this->getIO(), array());
        }
        $localRepo = $composer->getRepositoryManager()->getLocalRepository();
        $installedRepo = new CompositeRepository(array($localRepo, $platformRepo));
        $repos = new CompositeRepository(array_merge(array($installedRepo), $composer->getRepositoryManager()->getRepositories()));

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'search', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $onlyName = $input->getOption('only-name');
        $type = $input->getOption('type') ?: null;

        $flags = $onlyName ? RepositoryInterface::SEARCH_NAME : RepositoryInterface::SEARCH_FULLTEXT;
        $results = $repos->search(implode(' ', $input->getArgument('tokens')), $flags, $type);

        foreach ($results as $result) {
            $io->write($result['name'] . (isset($result['description']) ? ' '. $result['description'] : ''));
        }
    }
}
