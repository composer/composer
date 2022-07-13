<?php declare(strict_types=1);

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

use Composer\Package\CompletePackageInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RootPackageRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class HomeCommand extends BaseCommand
{
    /**
     * @inheritDoc
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('browse')
            ->setAliases(array('home'))
            ->setDescription('Opens the package\'s repository URL or homepage in your browser.')
            ->setDefinition(array(
                new InputArgument('packages', InputArgument::IS_ARRAY, 'Package(s) to browse to.'),
                new InputOption('homepage', 'H', InputOption::VALUE_NONE, 'Open the homepage instead of the repository URL.'),
                new InputOption('show', 's', InputOption::VALUE_NONE, 'Only show the homepage or repository URL.'),
            ))
            ->setHelp(
                <<<EOT
The home command opens or shows a package's repository URL or
homepage in your default browser.

To open the homepage by default, use -H or --homepage.
To show instead of open the repository or homepage URL, use -s or --show.

Read more at https://getcomposer.org/doc/03-cli.md#browse-home
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repos = $this->initializeRepos();
        $io = $this->getIO();
        $return = 0;

        $packages = $input->getArgument('packages');
        if (count($packages) === 0) {
            $io->writeError('No package specified, opening homepage for the root package');
            $packages = array($this->requireComposer()->getPackage()->getName());
        }

        foreach ($packages as $packageName) {
            $handled = false;
            $packageExists = false;
            foreach ($repos as $repo) {
                foreach ($repo->findPackages($packageName) as $package) {
                    $packageExists = true;
                    if ($package instanceof CompletePackageInterface && $this->handlePackage($package, $input->getOption('homepage'), $input->getOption('show'))) {
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

    /**
     * @param bool $showHomepage
     * @param bool $showOnly
     * @return bool
     */
    private function handlePackage(CompletePackageInterface $package, bool $showHomepage, bool $showOnly): bool
    {
        $support = $package->getSupport();
        $url = $support['source'] ?? $package->getSourceUrl();
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
     * @return void
     */
    private function openBrowser(string $url): void
    {
        $url = ProcessExecutor::escape($url);

        $process = new ProcessExecutor($this->getIO());
        if (Platform::isWindows()) {
            $process->execute('start "web" explorer ' . $url, $output);

            return;
        }

        $linux = $process->execute('which xdg-open', $output);
        $osx = $process->execute('which open', $output);

        if (0 === $linux) {
            $process->execute('xdg-open ' . $url, $output);
        } elseif (0 === $osx) {
            $process->execute('open ' . $url, $output);
        } else {
            $this->getIO()->writeError('No suitable browser opening command found, open yourself: ' . $url);
        }
    }

    /**
     * Initializes repositories
     *
     * Returns an array of repos in order they should be checked in
     *
     * @return RepositoryInterface[]
     */
    private function initializeRepos(): array
    {
        $composer = $this->tryComposer();

        if ($composer) {
            return array_merge(
                array(new RootPackageRepository(clone $composer->getPackage())), // root package
                array($composer->getRepositoryManager()->getLocalRepository()), // installed packages
                $composer->getRepositoryManager()->getRepositories() // remotes
            );
        }

        return RepositoryFactory::defaultReposWithDefaultManager($this->getIO());
    }
}
