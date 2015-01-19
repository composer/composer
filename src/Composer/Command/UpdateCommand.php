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

use Composer\Composer;
use Composer\Installer;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Nils Adermann <naderman@naderman.de>
 */
class UpdateCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('update')
            ->setDescription('Updates your dependencies to the latest version according to composer.json, and updates the composer.lock file.')
            ->setDefinition(array(
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Packages that should be updated, if not provided all packages are.'),
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
                new InputOption('prefer-dist', null, InputOption::VALUE_NONE, 'Forces installation from package dist even for dev versions.'),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Outputs the operations but will not execute anything (implicitly enables --verbose).'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Enables installation of require-dev packages (enabled by default, only present for BC).'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables installation of require-dev packages.'),
                new InputOption('lock', null, InputOption::VALUE_NONE, 'Only updates the lock file hash to suppress warning about the lock file being out of date.'),
                new InputOption('no-plugins', null, InputOption::VALUE_NONE, 'Disables all plugins.'),
                new InputOption('no-custom-installers', null, InputOption::VALUE_NONE, 'DEPRECATED: Use no-plugins instead.'),
                new InputOption('no-autoloader', null, InputOption::VALUE_NONE, 'Skips autoloader generation'),
                new InputOption('no-scripts', null, InputOption::VALUE_NONE, 'Skips the execution of all scripts defined in composer.json file.'),
                new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
                new InputOption('with-dependencies', null, InputOption::VALUE_NONE, 'Add also all dependencies of whitelisted packages to the whitelist.'),
                new InputOption('verbose', 'v|vv|vvv', InputOption::VALUE_NONE, 'Shows more details including new commits pulled in when updating packages.'),
                new InputOption('optimize-autoloader', 'o', InputOption::VALUE_NONE, 'Optimize autoloader during autoloader dump.'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore platform requirements (php & ext- packages).'),
                new InputOption('prefer-stable', null, InputOption::VALUE_NONE, 'Prefer stable versions of dependencies.'),
                new InputOption('prefer-lowest', null, InputOption::VALUE_NONE, 'Prefer lowest versions of dependencies.'),
                new InputOption('interactive', 'i', InputOption::VALUE_NONE, 'interactive interface with autocompletion that can help to select the packages to update.'),
            ))
            ->setHelp(<<<EOT
The <info>update</info> command reads the composer.json file from the
current directory, processes it, and updates, removes or installs all the
dependencies.

<info>php composer.phar update</info>

To limit the update operation to a few packages, you can list the package(s)
you want to update as such:

<info>php composer.phar update vendor/package1 foo/mypackage [...]</info>

You may also use an asterisk (*) pattern to limit the update operation to package(s)
from a specific vendor:

<info>php composer.phar update vendor/package1 foo/* [...]</info>
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer(true, $input->getOption('no-plugins'));
        $packages = $input->getArgument('packages');
        if ($input->getOption('interactive')) {
            $packages = $this->getPackagesInteractively($input, $output, $composer, $packages);
        }

        if ($input->getOption('no-custom-installers')) {
            $output->writeln('<warning>You are using the deprecated option "no-custom-installers". Use "no-plugins" instead.</warning>');
            $input->setOption('no-plugins', true);
        }

        $io = $this->getIO();

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'update', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $install = Installer::create($io, $composer);

        $preferSource = false;
        $preferDist = false;

        $config = $composer->getConfig();

        switch ($config->get('preferred-install')) {
            case 'source':
                $preferSource = true;
                break;
            case 'dist':
                $preferDist = true;
                break;
            case 'auto':
            default:
                // noop
                break;
        }
        if ($input->getOption('prefer-source') || $input->getOption('prefer-dist')) {
            $preferSource = $input->getOption('prefer-source');
            $preferDist = $input->getOption('prefer-dist');
        }

        $optimize = $input->getOption('optimize-autoloader') || $config->get('optimize-autoloader');

        $install
            ->setDryRun($input->getOption('dry-run'))
            ->setVerbose($input->getOption('verbose'))
            ->setPreferSource($preferSource)
            ->setPreferDist($preferDist)
            ->setDevMode(!$input->getOption('no-dev'))
            ->setDumpAutoloader(!$input->getOption('no-autoloader'))
            ->setRunScripts(!$input->getOption('no-scripts'))
            ->setOptimizeAutoloader($optimize)
            ->setUpdate(true)
            ->setUpdateWhitelist($input->getOption('lock') ? array('lock') : $packages)
            ->setWhitelistDependencies($input->getOption('with-dependencies'))
            ->setIgnorePlatformRequirements($input->getOption('ignore-platform-reqs'))
            ->setPreferStable($input->getOption('prefer-stable'))
            ->setPreferLowest($input->getOption('prefer-lowest'))
        ;

        if ($input->getOption('no-plugins')) {
            $install->disablePlugins();
        }

        return $install->run();
    }

    private function getPackagesInteractively(InputInterface $input, OutputInterface $output, Composer $composer, $packages)
    {
        $packagesMap = $composer->getRepositoryManager()
            ->getLocalRepository()->getPackages();

        $requiredPackageNames = array();
        foreach (
            array_merge(
                $composer->getPackage()->getRequires(),
                $composer->getPackage()->getDevRequires()
            ) as $require) {
            $requiredPackageNames[] = $require->getTarget();
        }

        $InstalledPackageNames = array();
        foreach ($packagesMap as $package) {
            $InstalledPackageNames[] = $package->getPrettyName();
        }

        $autocompleterValues = array_unique(
            array_merge($InstalledPackageNames, $requiredPackageNames)
        );

        $helper       = $this->getHelper('question');
        $question     = new Question(
            '<comment>Enter package name: </comment>',
            null
        );

        $packages = is_array($packages) ? $packages : array();
        $output->writeln('<info>NB: Empty package ends submission.</info>'); // I couldn't find any better for now!

        $continue = true;
        do {
            $question->setAutocompleterValues($autocompleterValues);
            $addedPackage = $helper->ask($input, $output, $question);

            if (is_string($addedPackage)) {
                if (!in_array($addedPackage, $packages)) {
                    $packages[] = $addedPackage;
                    $autocompleterValues = array_diff($autocompleterValues, array($addedPackage));
                } else {
                    $output->writeln(sprintf('<error>The package "%s" was already added.</error>', $package));
                }
            } else {
                $continue = false;
            }
        } while ($continue);

        $packages = array_filter($packages);
        if (!$packages) {
            throw new \InvalidArgumentException('You must enter minimum one package.');
        }

        $table = new Table($output);
        $table->setHeaders(array('Selected packages'));
        foreach ((array)$packages as $package) {
            $table->addRow(array($package));
        }
        $table->render();

        $continue = new ConfirmationQuestion(
            sprintf(
                '<question>Would you like to continue and update the above package%s [yes|no] ?</question><info> (yes) </info>',
                1 === count($packages) ? '' : 's'
            ),
            true
        );

        if ($helper->ask($input, $output, $continue)) {
            return $packages;
        }

        throw new \RuntimeException('Installation aborted.');
    }
}
