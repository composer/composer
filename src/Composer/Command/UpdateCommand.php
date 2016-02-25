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
use Composer\IO\IOInterface;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Nils Adermann <naderman@naderman.de>
 */
class UpdateCommand extends BaseCommand
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
                new InputOption('classmap-authoritative', 'a', InputOption::VALUE_NONE, 'Autoload classes from the classmap only. Implicitly enables `--optimize-autoloader`.'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore platform requirements (php & ext- packages).'),
                new InputOption('prefer-stable', null, InputOption::VALUE_NONE, 'Prefer stable versions of dependencies.'),
                new InputOption('prefer-lowest', null, InputOption::VALUE_NONE, 'Prefer lowest versions of dependencies.'),
                new InputOption('interactive', 'i', InputOption::VALUE_NONE, 'Interactive interface with autocompletion to select the packages to update.'),
                new InputOption('root-reqs', null, InputOption::VALUE_NONE, 'Restricts the update to your first degree dependencies.'),
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

To select packages names interactively with auto-completion use <info>-i</info>.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = $this->getIO();
        if ($input->getOption('no-custom-installers')) {
            $io->writeError('<warning>You are using the deprecated option "no-custom-installers". Use "no-plugins" instead.</warning>');
            $input->setOption('no-plugins', true);
        }

        if ($input->getOption('dev')) {
            $io->writeError('<warning>You are using the deprecated option "dev". Dev packages are installed by default now.</warning>');
        }

        $composer = $this->getComposer(true, $input->getOption('no-plugins'));

        $packages = $input->getArgument('packages');

        if ($input->getOption('interactive')) {
            $packages = $this->getPackagesInteractively($io, $input, $output, $composer, $packages);
        }

        if ($input->getOption('root-reqs')) {
            $require = array_keys($composer->getPackage()->getRequires());
            if (!$input->getOption('no-dev')) {
                $requireDev = array_keys($composer->getPackage()->getDevRequires());
                $require = array_merge($require, $requireDev);
            }

            if (!empty($packages)) {
                $packages = array_intersect($packages, $require);
            } else {
                $packages = $require;
            }
        }

        $composer->getDownloadManager()->setOutputProgress(!$input->getOption('no-progress'));

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
        $authoritative = $input->getOption('classmap-authoritative') || $config->get('classmap-authoritative');

        $install
            ->setDryRun($input->getOption('dry-run'))
            ->setVerbose($input->getOption('verbose'))
            ->setPreferSource($preferSource)
            ->setPreferDist($preferDist)
            ->setDevMode(!$input->getOption('no-dev'))
            ->setDumpAutoloader(!$input->getOption('no-autoloader'))
            ->setRunScripts(!$input->getOption('no-scripts'))
            ->setOptimizeAutoloader($optimize)
            ->setClassMapAuthoritative($authoritative)
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

    private function getPackagesInteractively(IOInterface $io, InputInterface $input, OutputInterface $output, Composer $composer, array $packages)
    {
        if (!$input->isInteractive()) {
            throw new \InvalidArgumentException('--interactive cannot be used in non-interactive terminals.');
        }

        $requires = array_merge(
            $composer->getPackage()->getRequires(),
            $composer->getPackage()->getDevRequires()
        );
        $autocompleterValues = array();
        foreach ($requires as $require) {
            $autocompleterValues[strtolower($require->getTarget())] = $require->getTarget();
        }

        $installedPackages = $composer->getRepositoryManager()->getLocalRepository()->getPackages();
        foreach ($installedPackages as $package) {
            $autocompleterValues[$package->getName()] = $package->getPrettyName();
        }

        $helper = $this->getHelper('question');
        $question = new Question('<comment>Enter package name: </comment>', null);

        $io->writeError('<info>Press enter without value to end submission</info>');

        do {
            $autocompleterValues = array_diff($autocompleterValues, $packages);
            $question->setAutocompleterValues($autocompleterValues);
            $addedPackage = $helper->ask($input, $output, $question);

            if (!is_string($addedPackage) || empty($addedPackage)) {
                break;
            }

            $addedPackage = strtolower($addedPackage);
            if (!in_array($addedPackage, $packages)) {
                $packages[] = $addedPackage;
            }
        } while (true);

        $packages = array_filter($packages);
        if (!$packages) {
            throw new \InvalidArgumentException('You must enter minimum one package.');
        }

        $table = new Table($output);
        $table->setHeaders(array('Selected packages'));
        foreach ($packages as $package) {
            $table->addRow(array($package));
        }
        $table->render();

        if ($io->askConfirmation(sprintf(
            'Would you like to continue and update the above package%s [<comment>yes</comment>]? ',
            1 === count($packages) ? '' : 's'
        ), true)) {
            return $packages;
        }

        throw new \RuntimeException('Installation aborted.');
    }
}
