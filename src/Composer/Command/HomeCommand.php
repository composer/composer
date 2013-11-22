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
use Composer\Factory;
use Composer\Package\CompletePackageInterface;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Repository\CompositeRepository;
use Composer\Repository\RepositoryInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\InvalidArgumentException;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class HomeCommand extends Command
{
    protected $versionParser;

    protected function configure()
    {
        $this
            ->setName('home')
            ->setDescription('opens the package in your browser')
            ->setDefinition(array(
                new InputArgument('package', InputArgument::REQUIRED, 'Package to goto'),
            ))
            ->setHelp(<<<EOT
The home command opens the package in your preferred browser
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $repo = $this->initializeRepo($input, $output);
        $package = $this->getPackage($repo, $input->getArgument('package'));

        /** @var CompletePackageInterface $package */
        if ($package instanceof CompletePackageInterface && filter_var($package->getSourceUrl(), FILTER_VALIDATE_URL)) {
            $this->openBrowser($package->getSourceUrl());
        } elseif ($package instanceof CompletePackageInterface) {
            $this->getIO()->write('no valid source-url given for ' . $package->getName());
        } else {
            throw new InvalidArgumentException('package not found');
        }
    }

    /**
     * finds a package by name
     *
     * @param  RepositoryInterface $repos
     * @param  string              $name
     * @return CompletePackageInterface
     */
    protected function getPackage(RepositoryInterface $repos, $name)
    {
        $name = strtolower($name);
        $pool = new Pool('dev');
        $pool->addRepository($repos);
        $matches = $pool->whatProvides($name);

        foreach ($matches as $index => $package) {
            // skip providers/replacers
            if ($package->getName() !== $name) {
                unset($matches[$index]);
                continue;
            }

            return $package;
        }
    }

    /**
     * opens a url in your system default browser
     *
     * @param string $url
     */
    private function openBrowser($url)
    {
        passthru('which xdg-open', $linux);
        passthru('which open', $osx);
        $windows = defined('PHP_WINDOWS_VERSION_MAJOR');

        if (0 === $linux) {
            passthru('xdg-open ' . $url);
        } elseif (0 === $osx) {
            passthru('open ' . $url);
        } elseif (true === $windows) {
            passthru('start "web" explorer "' . $url . '"');
        } else {
            $this->getIO()->write('no suitable browser opening tool found, open yourself: ' . $url);
        }
    }

    /**
     * initializes the repo
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return CompositeRepository
     */
    private function initializeRepo(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer(false);

        if ($composer) {
            $repo = new CompositeRepository($composer->getRepositoryManager()->getRepositories());
        } else {
            $defaultRepos = Factory::createDefaultRepositories($this->getIO());
            $repo = new CompositeRepository($defaultRepos);
        }

        if ($composer) {
            $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'home', $input, $output);
            $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

            return $repo;
        }

        return $repo;
    }

}
