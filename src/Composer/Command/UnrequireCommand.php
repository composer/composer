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

/**
 * @author Stefano Varesi <stefano.varesi@gmail.com>
 */
class UnrequireCommand extends InitCommand
{
    protected function configure()
    {
        $this
            ->setName('unrequire')
            ->setDescription('Removes packages from your composer.json and uninstalls them')
            ->setDefinition(array(
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Unrequired package without a version constraint, e.g. foo/bar"'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Remove the requirement from require-dev.'),
                new InputOption('no-update', null, InputOption::VALUE_NONE, 'Disables the automatic update of the dependencies.'),
                new InputOption('update-no-dev', null, InputOption::VALUE_NONE, 'Run the dependency update with the --no-dev option.'),
            ))
            ->setHelp(<<<EOT
The unrequire command removes no more required packages from your composer.json and uninstalls them

If you do not want to uninstall the dependencies immediately you can call it with --no-update

EOT
            )
        ;
    }

    protected function normalizeRequirements(array $requirements)
    {
        $parser = new VersionParser();

        $requirements = $parser->parseNameVersionPairs($requirements);

        // override any given version constraint with the meta-constraint @dev; a version constraint is not needed and
        // it is not required that the user explicit it, but having it defined simplifies the dependency management
        foreach ($requirements as &$requirement) {
            $requirement['version'] = '@dev';
        }

        return $requirements;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $file = Factory::getComposerFile();

        if (!file_exists($file) && !file_put_contents($file, "{\n}\n")) {
            $output->writeln('<error>'.$file.' could not be created.</error>');

            return 1;
        }
        if (!is_readable($file)) {
            $output->writeln('<error>'.$file.' is not readable.</error>');

            return 1;
        }
        if (!is_writable($file)) {
            $output->writeln('<error>'.$file.' is not writable.</error>');

            return 1;
        }

        $json = new JsonFile($file);
        $composer = $json->read();
        $composerBackup = file_get_contents($json->getPath());

        $requirements = $this->determineRequirements($input, $output, $input->getArgument('packages'));

        $requireKey = $input->getOption('dev') ? 'require-dev' : 'require';
        $removeKey = $input->getOption('dev') ? 'require' : 'require-dev';
        $baseRequirements = array_key_exists($requireKey, $composer) ? $composer[$requireKey] : array();
        $requirements = $this->formatRequirements($requirements);

        // validate requirements format
        $versionParser = new VersionParser();
        foreach ($requirements as $constraint) {
            $versionParser->parseConstraints($constraint);
        }

        if (!$this->updateFileCleanly($json, $requirements, $requireKey)) {
            foreach ($requirements as $package => $version) {
                $baseRequirements[$package] = $version;

                if (isset($composer[$removeKey][$package])) {
                    unset($composer[$removeKey][$package]);
                }
            }

            $composer[$requireKey] = $baseRequirements;
            $json->write($composer);
        }

        $output->writeln('<info>'.$file.' has been updated</info>');

        if ($input->getOption('no-update')) {
            return 0;
        }
        $updateDevMode = !$input->getOption('update-no-dev');

        // Update packages
        $composer = $this->getComposer();
        $io = $this->getIO();

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'require', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $install = Installer::create($io, $composer);

        $install
            ->setVerbose($input->getOption('verbose'))
            ->setDevMode($updateDevMode)
            ->setUpdate(true)
            ->setUpdateWhitelist(array_keys($requirements))
            // do not leave orphaned dependencies in composer.lock
            ->setWhitelistDependencies(true);
        ;

        $status = $install->run();
        if ($status !== 0) {
            $output->writeln("\n".'<error>Installation failed, reverting '.$file.' to its original content.</error>');
            file_put_contents($json->getPath(), $composerBackup);
        }

        return $status;
    }

    private function updateFileCleanly(JsonFile $json, array $toBeRemoved, $requireKey)
    {
        $contents = file_get_contents($json->getPath());

        $manipulator = new JsonManipulator($contents);

        // remove any given package from the file
        foreach ($toBeRemoved as $package => $constraint) {
            if (!$manipulator->removeSubNode($requireKey, $package)) {
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
