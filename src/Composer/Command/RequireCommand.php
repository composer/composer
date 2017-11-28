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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Factory;
use Composer\Installer;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;

/**
 * @author Jérémy Romey <jeremy@free-agent.fr>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class RequireCommand extends InitCommand
{
    protected function configure()
    {
        $this
            ->setName('require')
            ->setDescription('Adds required packages to your composer.json and installs them.')
            ->setDefinition(array(
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Required package name optionally including a version constraint, e.g. foo/bar or foo/bar:1.0.0 or foo/bar=1.0.0 or "foo/bar 1.0.0"'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Add requirement to require-dev.'),
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
                new InputOption('prefer-dist', null, InputOption::VALUE_NONE, 'Forces installation from package dist even for dev versions.'),
                new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
                new InputOption('no-suggest', null, InputOption::VALUE_NONE, 'Do not show package suggestions.'),
                new InputOption('no-update', null, InputOption::VALUE_NONE, 'Disables the automatic update of the dependencies.'),
                new InputOption('no-scripts', null, InputOption::VALUE_NONE, 'Skips the execution of all scripts defined in composer.json file.'),
                new InputOption('update-no-dev', null, InputOption::VALUE_NONE, 'Run the dependency update with the --no-dev option.'),
                new InputOption('update-with-dependencies', null, InputOption::VALUE_NONE, 'Allows inherited dependencies to be updated with explicit dependencies.'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore platform requirements (php & ext- packages).'),
                new InputOption('prefer-stable', null, InputOption::VALUE_NONE, 'Prefer stable versions of dependencies.'),
                new InputOption('prefer-lowest', null, InputOption::VALUE_NONE, 'Prefer lowest versions of dependencies.'),
                new InputOption('sort-packages', null, InputOption::VALUE_NONE, 'Sorts packages when adding/updating a new dependency'),
                new InputOption('optimize-autoloader', 'o', InputOption::VALUE_NONE, 'Optimize autoloader during autoloader dump'),
                new InputOption('classmap-authoritative', 'a', InputOption::VALUE_NONE, 'Autoload classes from the classmap only. Implicitly enables `--optimize-autoloader`.'),
                new InputOption('apcu-autoloader', null, InputOption::VALUE_NONE, 'Use APCu to cache found/not-found classes.'),
            ))
            ->setHelp(<<<EOT
The require command adds required packages to your composer.json and installs them.

If you do not specify a version constraint, composer will choose a suitable one based on the available package versions.

If you do not want to install the new dependencies immediately you can call it with --no-update

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $file = Factory::getComposerFile();
        $io = $this->getIO();

        $newlyCreated = !file_exists($file);
        if ($newlyCreated && !file_put_contents($file, "{\n}\n")) {
            $io->writeError('<error>'.$file.' could not be created.</error>');

            return 1;
        }
        if (!is_readable($file)) {
            $io->writeError('<error>'.$file.' is not readable.</error>');

            return 1;
        }
        if (!is_writable($file)) {
            $io->writeError('<error>'.$file.' is not writable.</error>');

            return 1;
        }

        if (filesize($file) === 0) {
            file_put_contents($file, "{\n}\n");
        }

        $json = new JsonFile($file);
        $composerBackup = file_get_contents($json->getPath());

        $composer = $this->getComposer(true, $input->getOption('no-plugins'));
        $repos = $composer->getRepositoryManager()->getRepositories();

        $platformOverrides = $composer->getConfig()->get('platform') ?: array();
        // initialize $this->repos as it is used by the parent InitCommand
        $this->repos = new CompositeRepository(array_merge(
            array(new PlatformRepository(array(), $platformOverrides)),
            $repos
        ));

        if ($composer->getPackage()->getPreferStable()) {
            $preferredStability = 'stable';
        } else {
            $preferredStability = $composer->getPackage()->getMinimumStability();
        }

        $phpVersion = $this->repos->findPackage('php', '*')->getVersion();
        $requirements = $this->determineRequirements($input, $output, $input->getArgument('packages'), $phpVersion, $preferredStability);

        $requireKey = $input->getOption('dev') ? 'require-dev' : 'require';
        $removeKey = $input->getOption('dev') ? 'require' : 'require-dev';
        $requirements = $this->formatRequirements($requirements);

        // validate requirements format
        $versionParser = new VersionParser();
        foreach ($requirements as $constraint) {
            $versionParser->parseConstraints($constraint);
        }

        $sortPackages = $input->getOption('sort-packages') || $composer->getConfig()->get('sort-packages');

        if (!$this->updateFileCleanly($json, $requirements, $requireKey, $removeKey, $sortPackages)) {
            $composerDefinition = $json->read();
            foreach ($requirements as $package => $version) {
                $composerDefinition[$requireKey][$package] = $version;
                unset($composerDefinition[$removeKey][$package]);
            }
            $json->write($composerDefinition);
        }

        $io->writeError('<info>'.$file.' has been '.($newlyCreated ? 'created' : 'updated').'</info>');

        if ($input->getOption('no-update')) {
            return 0;
        }
        $updateDevMode = !$input->getOption('update-no-dev');
        $optimize = $input->getOption('optimize-autoloader') || $composer->getConfig()->get('optimize-autoloader');
        $authoritative = $input->getOption('classmap-authoritative') || $composer->getConfig()->get('classmap-authoritative');
        $apcu = $input->getOption('apcu-autoloader') || $composer->getConfig()->get('apcu-autoloader');

        // Update packages
        $this->resetComposer();
        $composer = $this->getComposer(true, $input->getOption('no-plugins'));
        $composer->getDownloadManager()->setOutputProgress(!$input->getOption('no-progress'));

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'require', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $install = Installer::create($io, $composer);

        $install
            ->setVerbose($input->getOption('verbose'))
            ->setPreferSource($input->getOption('prefer-source'))
            ->setPreferDist($input->getOption('prefer-dist'))
            ->setDevMode($updateDevMode)
            ->setRunScripts(!$input->getOption('no-scripts'))
            ->setSkipSuggest($input->getOption('no-suggest'))
            ->setOptimizeAutoloader($optimize)
            ->setClassMapAuthoritative($authoritative)
            ->setApcuAutoloader($apcu)
            ->setUpdate(true)
            ->setUpdateWhitelist(array_keys($requirements))
            ->setWhitelistDependencies($input->getOption('update-with-dependencies'))
            ->setIgnorePlatformRequirements($input->getOption('ignore-platform-reqs'))
            ->setPreferStable($input->getOption('prefer-stable'))
            ->setPreferLowest($input->getOption('prefer-lowest'))
        ;

        $status = $install->run();
        if ($status !== 0) {
            if ($newlyCreated) {
                $io->writeError("\n".'<error>Installation failed, deleting '.$file.'.</error>');
                unlink($json->getPath());
            } else {
                $io->writeError("\n".'<error>Installation failed, reverting '.$file.' to its original content.</error>');
                file_put_contents($json->getPath(), $composerBackup);
            }
        }

        return $status;
    }

    private function updateFileCleanly($json, array $new, $requireKey, $removeKey, $sortPackages)
    {
        $contents = file_get_contents($json->getPath());

        $manipulator = new JsonManipulator($contents);

        foreach ($new as $package => $constraint) {
            if (!$manipulator->addLink($requireKey, $package, $constraint, $sortPackages)) {
                return false;
            }
            if (!$manipulator->removeSubNode($removeKey, $package)) {
                return false;
            }
        }

        file_put_contents($json->getPath(), $manipulator->getContents());

        return true;
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        return;
    }
}
