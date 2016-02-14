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
use Composer\Package\RootPackage;
use Composer\Repository\ArrayRepository;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Justin Rainbow <justin.rainbow@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class DependsCommand extends Command
{
    /** @var CompositeRepository  */
    private $repository;

    protected function configure()
    {
        $this
            ->setName('depends')
            ->setAliases(array('why'))
            ->setDescription('Shows which packages depend on the given package')
            ->setDefinition(array(
                new InputArgument('package', InputArgument::REQUIRED, 'Package to inspect'),
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
        $this->repository = new CompositeRepository(array(
            new ArrayRepository(array($composer->getPackage())),
            $composer->getRepositoryManager()->getLocalRepository(),
            new PlatformRepository(array(), $platformOverrides),
        ));
        $pool = new Pool();
        $pool->addRepository($this->repository);

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
        $recursive = true;
        $tree = true;

        // Resolve dependencies
        $results = $this->getDependers($needle, $constraint, $matchInvert, $recursive);
        if (empty($results)) {
            $extra = isset($constraint) ? sprintf(' in versions %smatching %s', $matchInvert ? 'not ' : '', $textConstraint) : '';
            $this->getIO()->writeError(sprintf('<info>There is no installed package depending on "%s"%s</info>',
                        $needle, $extra));
        } elseif ($tree) {
            $root = $packages[0];
            $this->getIO()->write(sprintf('<info>%s</info> %s %s', $root->getPrettyName(), $root->getPrettyVersion(), $root->getDescription()));
            $this->printTree($output, $results);
        } else {
            $this->printTable($output, $results);
        }
    }

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
                $realVersion = (strpos($package->getPrettyVersion(), 'No version set') === 0) ? '-' : $package->getPrettyVersion();
                $rows[] = array($package->getPrettyName(), $realVersion, $link->getDescription(), sprintf('%s (%s)', $link->getTarget(), $link->getPrettyConstraint()));
                $queue = array_merge($queue, $children);
            }
            $results = $queue;
            $table = array_merge($rows, $table);
        } while(!empty($results));

        // Render table
        $renderer = new Table($output);
        $renderer->setStyle('compact')->setRows($table)->render();
    }

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
            $output->write(sprintf("%s%s %s %s %s (%s)\n", $prefix, $isLast ? '`-' : '|-', $link->getSource(), $link->getDescription(), $link->getTarget(), $link->getPrettyConstraint()));
            $this->printTree($output, $children, $prefix . ($isLast ? '   ' : '|  '));
        }
    }

    /**
     * @param string $needle The package to inspect.
     * @param ConstraintInterface|null $constraint Optional constraint to filter by.
     * @param bool $invert Whether to invert matches on the previous constraint.
     * @param bool $recurse Whether to recursively expand the requirement tree.
     * @return array An array with dependers as key, and as values an array containing the source package and the link respectively
     */
    private function getDependers($needle, $constraint = null, $invert = false, $recurse = true)
    {
        $needles = is_array($needle) ? $needle : array($needle);
        $results = array();

        /**
         * Loop over all currently installed packages.
         * @var PackageInterface $package
         */
        foreach ($this->repository->getPackages() as $package) {
            // Retrieve all requirements, but dev only for the root package
            $links = $package->getRequires();
            $links += $package->getReplaces();
            if ($package instanceof RootPackage) {
                $links += $package->getDevRequires();
            }

            // Cross-reference all discovered links to the needles
            foreach ($links as $link) {
                foreach ($needles as $needle) {
                    if ($link->getTarget() === $needle) {
                        if (is_null($constraint) || (($link->getConstraint()->matches($constraint) === !$invert))) {
                            $results[$link->getSource()] = array($package, $link, $recurse ? $this->getDependers($link->getSource(), null, false, true) : array());
                        }
                    }
                }
            }
        }
        ksort($results);
        return $results;
    }
}
