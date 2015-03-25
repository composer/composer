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
            ->setDescription('Adds required packages to your composer.json and installs them')
            ->setDefinition(array(
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Required package name optionally including a version constraint, e.g. foo/bar or foo/bar:1.0.0 or foo/bar=1.0.0 or "foo/bar 1.0.0"'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Add requirement to require-dev.'),
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
                new InputOption('prefer-dist', null, InputOption::VALUE_NONE, 'Forces installation from package dist even for dev versions.'),
                new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
                new InputOption('no-update', null, InputOption::VALUE_NONE, 'Disables the automatic update of the dependencies.'),
                new InputOption('update-no-dev', null, InputOption::VALUE_NONE, 'Run the dependency update with the --no-dev option.'),
                new InputOption('update-with-dependencies', null, InputOption::VALUE_NONE, 'Allows inherited dependencies to be updated with explicit dependencies.'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore platform requirements (php & ext- packages).'),
                new InputOption('sort-packages', null, InputOption::VALUE_NONE, 'Sorts packages when adding/updating a new dependency'),
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

        $newlyCreated = !file_exists($file);
        if (!file_exists($file) && !file_put_contents($file, "{\n}\n")) {
            $this->getIO()->writeError('<error>'.$file.' could not be created.</error>');

            return 1;
        }
        if (!is_readable($file)) {
            $this->getIO()->writeError('<error>'.$file.' is not readable.</error>');

            return 1;
        }
        if (!is_writable($file)) {
            $this->getIO()->writeError('<error>'.$file.' is not writable.</error>');

            return 1;
        }

        $json = new JsonFile($file);
        $composerDefinition = $json->read();
        $composerBackup = file_get_contents($json->getPath());

        $composer = $this->getComposer();
        $repos = $composer->getRepositoryManager()->getRepositories();

        $this->repos = new CompositeRepository(array_merge(
            array(new PlatformRepository),
            $repos
        ));

        $requirements = $this->determineRequirements($input, $output, $input->getArgument('packages'));

        $requireKey = $input->getOption('dev') ? 'require-dev' : 'require';
        $removeKey = $input->getOption('dev') ? 'require' : 'require-dev';
        $baseRequirements = array_key_exists($requireKey, $composerDefinition) ? $composerDefinition[$requireKey] : array();
        $requirements = $this->formatRequirements($requirements);

        // validate requirements format
        $versionParser = new VersionParser();
        foreach ($requirements as $constraint) {
            $versionParser->parseConstraints($constraint);
        }

        $sortPackages = $input->getOption('sort-packages');

        if (!$this->updateFileCleanly($json, $baseRequirements, $requirements, $requireKey, $removeKey, $sortPackages)) {
            foreach ($requirements as $package => $version) {
                $baseRequirements[$package] = $version;

                if (isset($composerDefinition[$removeKey][$package])) {
                    unset($composerDefinition[$removeKey][$package]);
                }
            }

            $composerDefinition[$requireKey] = $baseRequirements;
            $json->write($composerDefinition);
        }

        $this->getIO()->writeError('<info>'.$file.' has been '.($newlyCreated ? 'created' : 'updated').'</info>');

        if ($input->getOption('no-update')) {
            return 0;
        }
        $updateDevMode = !$input->getOption('update-no-dev');

        // Update packages
        $this->resetComposer();
        $composer = $this->getComposer();
        $composer->getDownloadManager()->setOutputProgress(!$input->getOption('no-progress'));
        $io = $this->getIO();

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'require', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $install = Installer::create($io, $composer);

        $install
            ->setVerbose($input->getOption('verbose'))
            ->setPreferSource($input->getOption('prefer-source'))
            ->setPreferDist($input->getOption('prefer-dist'))
            ->setDevMode($updateDevMode)
            ->setUpdate(true)
            ->setUpdateWhitelist(array_keys($requirements))
            ->setWhitelistDependencies($input->getOption('update-with-dependencies'))
            ->setIgnorePlatformRequirements($input->getOption('ignore-platform-reqs'))
        ;

        $status = $install->run();
        if ($status !== 0) {
            if ($newlyCreated) {
                $this->getIO()->writeError("\n".'<error>Installation failed, deleting '.$file.'.</error>');
                unlink($json->getPath());
            } else {
                $this->getIO()->writeError("\n".'<error>Installation failed, reverting '.$file.' to its original content.</error>');
                file_put_contents($json->getPath(), $composerBackup);
            }
        }

        return $status;
    }

    private function updateFileCleanly($json, array $base, array $new, $requireKey, $removeKey, $sortPackages)
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
