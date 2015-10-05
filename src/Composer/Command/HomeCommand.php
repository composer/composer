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
use Composer\Package\CompletePackageInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\ArrayRepository;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class HomeCommand extends Command
{
    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this
            ->setName('browse')
            ->setAliases(array('home'))
            ->setDescription('Opens the package\'s repository URL or homepage in your browser.')
            ->setDefinition(array(
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Package(s) to browse to.'),
                new InputOption('homepage', 'H', InputOption::VALUE_NONE, 'Open the homepage instead of the repository URL.'),
                new InputOption('show', 's', InputOption::VALUE_NONE, 'Only show the homepage or repository URL.'),
            ))
            ->setHelp(<<<EOT
The home command opens or shows a package's repository URL or
homepage in your default browser.

To open the homepage by default, use -H or --homepage.
To show instead of open the repository or homepage URL, use -s or --show.
EOT
            );
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $repos = $this->initializeRepos();
        $io = $this->getIO();
        $return = 0;

        foreach ($input->getArgument('packages') as $packageName) {
            $handled = false;
            $packageExists = false;
            foreach ($repos as $repo) {
                foreach ($repo->findPackages($packageName) as $package) {
                    $packageExists = true;
                    if ($this->handlePackage($package, $input->getOption('homepage'), $input->getOption('show'))) {
                        $handled = true;
                        break 2;
                    }
                }
            }

            if (!$packageExists) {
                $return = 1;
                $io->writeError('<warning>Package '.$packageName.' not found</warning>');
            }

            if (!$handled) {
                $return = 1;
                $io->writeError('<warning>'.($input->getOption('homepage') ? 'Invalid or missing homepage' : 'Invalid or missing repository URL').' for '.$packageName.'</warning>');
            }
        }

        return $return;
    }

    private function handlePackage(CompletePackageInterface $package, $showHomepage, $showOnly)
    {
        $support = $package->getSupport();
        $url = isset($support['source']) ? $support['source'] : $package->getSourceUrl();
        if (!$url || $showHomepage) {
            $url = $package->getHomepage();
        }

        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        if ($showOnly) {
            $this->getIO()->write(sprintf('<info>%s</info>', $url));
        } else {
            $this->openBrowser($url);
        }

        return true;
    }

    /**
     * opens a url in your system default browser
     *
     * @param string $url
     */
    private function openBrowser($url)
    {
        $url = ProcessExecutor::escape($url);

        if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
            return passthru('start "web" explorer "' . $url . '"');
        }

        passthru('which xdg-open', $linux);
        passthru('which open', $osx);

        if (0 === $linux) {
            passthru('xdg-open ' . $url);
        } elseif (0 === $osx) {
            passthru('open ' . $url);
        } else {
            $this->getIO()->writeError('no suitable browser opening command found, open yourself: ' . $url);
        }
    }

    /**
     * Initializes repositories
     *
     * Returns an array of repos in order they should be checked in
     *
     * @return RepositoryInterface[]
     */
    private function initializeRepos()
    {
        $composer = $this->getComposer(false);

        if ($composer) {
            return array_merge(
                array(new ArrayRepository(array($composer->getPackage()))), // root package
                array($composer->getRepositoryManager()->getLocalRepository()), // installed packages
                $composer->getRepositoryManager()->getRepositories() // remotes
            );
        }

        $defaultRepos = Factory::createDefaultRepositories($this->getIO());

        return $defaultRepos;
    }
}
