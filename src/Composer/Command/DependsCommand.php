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

use Composer\DependencyResolver\Pool;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Repository\ArrayRepository;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Semver\VersionParser;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class DependsCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('depends')
            ->setAliases(array('why'))
            ->setDescription('Shows which packages depend on the given package')
            ->setDefinition(array(
                new InputArgument('package', InputArgument::REQUIRED, 'Package to inspect'),
                new InputOption('recursive', 'r', InputOption::VALUE_NONE, 'Recursively resolves up to the root package'),
                new InputOption('tree', 't', InputOption::VALUE_NONE, 'Prints the results as a nested tree'),
                new InputOption('match-constraint', 'm', InputOption::VALUE_REQUIRED, 'Filters the dependencies shown using this constraint', '*'),
                new InputOption('invert-match-constraint', 'i', InputOption::VALUE_NONE, 'Turns --match-constraint around into a blacklist instead of whitelist'),
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
        // Emit command event on startup
        $composer = $this->getComposer();
        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'depends', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        // Prepare repositories and set up a pool
        $platformOverrides = $composer->getConfig()->get('platform') ?: array();
        $repository = new CompositeRepository(array(
            new ArrayRepository(array($composer->getPackage())),
            $composer->getRepositoryManager()->getLocalRepository(),
            new PlatformRepository(array(), $platformOverrides),
        ));
        $pool = new Pool();
        $pool->addRepository($repository);

        // Find packages that are or provide the requested package first
        $needle = $input->getArgument('package');
        $packages = $pool->whatProvides($needle);
        if (empty($packages)) {
            throw new \InvalidArgumentException(sprintf('Could not find package "%s" in your project', $needle));
        }

        // Parse options that are only relevant for the initial needle(s)
        if ('*' !== ($textConstraint = $input->getOption('match-constraint'))) {
            $versionParser = new VersionParser();
            $constraint = $versionParser->parseConstraints($textConstraint);
        } else {
            $constraint = null;
        }
        $matchInvert = $input->getOption('invert-match-constraint');

        // Parse rendering options
        $renderTree = $input->getOption('tree');
        $recursive = $renderTree || $input->getOption('recursive');

        // Resolve dependencies
        $results = $this->getDependents($needle, $repository->getPackages(), $constraint, $matchInvert, $recursive);
        if (empty($results)) {
            $extra = (null !== $constraint) ? sprintf(' in versions %smatching %s', $matchInvert ? 'not ' : '', $textConstraint) : '';
            $this->getIO()->writeError(sprintf('<info>There is no installed package depending on "%s"%s</info>',
                        $needle, $extra));
        } elseif ($renderTree) {
            $root = $packages[0];
            $this->getIO()->write(sprintf('<info>%s</info> %s %s', $root->getPrettyName(), $root->getPrettyVersion(), $root->getDescription()));
            $this->printTree($output, $results);
        } else {
            $this->printTable($output, $results);
        }
    }

    /**
     * Assembles and prints a bottom-up table of the dependencies.
     *
     * @param OutputInterface $output
     * @param array $results
     */
    private function printTable(OutputInterface $output, $results)
    {
        $table = array();
        $doubles = array();
        do {
            $queue = array();
            $rows = array();
            foreach($results as $result) {
                /**
                 * @var PackageInterface $package
                 * @var Link $link
                 */
                list($package, $link, $children) = $result;
                $unique = (string)$link;
                if (isset($doubles[$unique])) {
                    continue;
                }
                $doubles[$unique] = true;
                $version = (strpos($package->getPrettyVersion(), 'No version set') === 0) ? '-' : $package->getPrettyVersion();
                $rows[] = array($package->getPrettyName(), $version, $link->getDescription(), sprintf('%s (%s)', $link->getTarget(), $link->getPrettyConstraint()));
                $queue = array_merge($queue, $children);
            }
            $results = $queue;
            $table = array_merge($rows, $table);
        } while(!empty($results));

        // Render table
        $renderer = new Table($output);
        $renderer->setStyle('compact')->setRows($table)->render();
    }

    /**
     * Recursively prints a tree of the selected results.
     *
     * @param OutputInterface $output
     * @param array $results
     * @param string $prefix
     */
    public function printTree(OutputInterface $output, $results, $prefix = '')
    {
        $count = count($results);
        $idx = 0;
        foreach($results as $key => $result) {
            /**
             * @var PackageInterface $package
             * @var Link $link
             */
            list($package, $link, $children) = $result;
            $isLast = (++$idx == $count);
            $versionText = (strpos($package->getPrettyVersion(), 'No version set') === 0) ? '' : $package->getPrettyVersion();
            $packageText = rtrim(sprintf('%s %s', $package->getPrettyName(), $versionText));
            $linkText = implode(' ', array($link->getDescription(), $link->getTarget(), $link->getPrettyConstraint()));
            $output->write(sprintf("%s%s %s (%s)\n", $prefix, $isLast ? '`-' : '|-', $packageText, $linkText));
            $this->printTree($output, $children, $prefix . ($isLast ? '   ' : '|  '));
        }
    }


}
