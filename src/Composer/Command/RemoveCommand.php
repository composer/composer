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

use Composer\Config\JsonConfigSource;
use Composer\DependencyResolver\Request;
use Composer\Installer;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Json\JsonFile;
use Composer\Factory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Package\BasePackage;

/**
 * @author Pierre du Plessis <pdples@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class RemoveCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('remove')
            ->setDescription('Removes a package from the require or require-dev.')
            ->setDefinition(array(
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Packages that should be removed.'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Removes a package from the require-dev section.'),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Outputs the operations but will not execute anything (implicitly enables --verbose).'),
                new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
                new InputOption('no-update', null, InputOption::VALUE_NONE, 'Disables the automatic update of the dependencies.'),
                new InputOption('no-scripts', null, InputOption::VALUE_NONE, 'Skips the execution of all scripts defined in composer.json file.'),
                new InputOption('update-no-dev', null, InputOption::VALUE_NONE, 'Run the dependency update with the --no-dev option.'),
                new InputOption('update-with-dependencies', null, InputOption::VALUE_NONE, 'Allows inherited dependencies to be updated with explicit dependencies. (Deprecrated, is now default behavior)'),
                new InputOption('no-update-with-dependencies', null, InputOption::VALUE_NONE, 'Does not allow inherited dependencies to be updated with explicit dependencies.'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore platform requirements (php & ext- packages).'),
                new InputOption('optimize-autoloader', 'o', InputOption::VALUE_NONE, 'Optimize autoloader during autoloader dump'),
                new InputOption('classmap-authoritative', 'a', InputOption::VALUE_NONE, 'Autoload classes from the classmap only. Implicitly enables `--optimize-autoloader`.'),
                new InputOption('apcu-autoloader', null, InputOption::VALUE_NONE, 'Use APCu to cache found/not-found classes.'),
            ))
            ->setHelp(
                <<<EOT
The <info>remove</info> command removes a package from the current
list of installed packages

<info>php composer.phar remove</info>

Read more at https://getcomposer.org/doc/03-cli.md#remove
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $packages = $input->getArgument('packages');
        $packages = array_map('strtolower', $packages);

        $file = Factory::getComposerFile();

        $jsonFile = new JsonFile($file);
        $composer = $jsonFile->read();
        $composerBackup = file_get_contents($jsonFile->getPath());

        $json = new JsonConfigSource($jsonFile);

        $type = $input->getOption('dev') ? 'require-dev' : 'require';
        $altType = !$input->getOption('dev') ? 'require-dev' : 'require';
        $io = $this->getIO();

        if ($input->getOption('update-with-dependencies')) {
            $io->writeError('<warning>You are using the deprecated option "update-with-dependencies". This is now default behaviour. The --no-update-with-dependencies option can be used to remove a package without its dependencies.</warning>');
        }

        // make sure name checks are done case insensitively
        foreach (array('require', 'require-dev') as $linkType) {
            if (isset($composer[$linkType])) {
                foreach ($composer[$linkType] as $name => $version) {
                    $composer[$linkType][strtolower($name)] = $name;
                }
            }
        }

        $dryRun = $input->getOption('dry-run');
        $toRemove = array();
        foreach ($packages as $package) {
            if (isset($composer[$type][$package])) {
                if ($dryRun) {
                    $toRemove[$type][] = $composer[$type][$package];
                } else {
                    $json->removeLink($type, $composer[$type][$package]);
                }
            } elseif (isset($composer[$altType][$package])) {
                $io->writeError('<warning>' . $composer[$altType][$package] . ' could not be found in ' . $type . ' but it is present in ' . $altType . '</warning>');
                if ($io->isInteractive()) {
                    if ($io->askConfirmation('Do you want to remove it from ' . $altType . ' [<comment>yes</comment>]? ', true)) {
                        if ($dryRun) {
                            $toRemove[$altType][] = $composer[$altType][$package];
                        } else {
                            $json->removeLink($altType, $composer[$altType][$package]);
                        }
                    }
                }
            } elseif (isset($composer[$type]) && $matches = preg_grep(BasePackage::packageNameToRegexp($package), array_keys($composer[$type]))) {
                foreach ($matches as $matchedPackage) {
                    if ($dryRun) {
                        $toRemove[$type][] = $matchedPackage;
                    } else {
                        $json->removeLink($type, $matchedPackage);
                    }
                }
            } elseif (isset($composer[$altType]) && $matches = preg_grep(BasePackage::packageNameToRegexp($package), array_keys($composer[$altType]))) {
                foreach ($matches as $matchedPackage) {
                    $io->writeError('<warning>' . $matchedPackage . ' could not be found in ' . $type . ' but it is present in ' . $altType . '</warning>');
                    if ($io->isInteractive()) {
                        if ($io->askConfirmation('Do you want to remove it from ' . $altType . ' [<comment>yes</comment>]? ', true)) {
                            if ($dryRun) {
                                $toRemove[$altType][] = $matchedPackage;
                            } else {
                                $json->removeLink($altType, $matchedPackage);
                            }
                        }
                    }
                }
            } else {
                $io->writeError('<warning>'.$package.' is not required in your composer.json and has not been removed</warning>');
            }
        }

        if ($input->getOption('no-update')) {
            return 0;
        }

        // Update packages
        $this->resetComposer();
        $composer = $this->getComposer(true, $input->getOption('no-plugins'));

        if ($dryRun) {
            $rootPackage = $composer->getPackage();
            $links = array(
                'require' => $rootPackage->getRequires(),
                'require-dev' => $rootPackage->getDevRequires(),
            );
            foreach ($toRemove as $type => $packages) {
                foreach ($packages as $package) {
                    unset($links[$type][$package]);
                }
            }
            $rootPackage->setRequires($links['require']);
            $rootPackage->setDevRequires($links['require-dev']);
        }

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'remove', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $install = Installer::create($io, $composer);

        $updateDevMode = !$input->getOption('update-no-dev');
        $optimize = $input->getOption('optimize-autoloader') || $composer->getConfig()->get('optimize-autoloader');
        $authoritative = $input->getOption('classmap-authoritative') || $composer->getConfig()->get('classmap-authoritative');
        $apcu = $input->getOption('apcu-autoloader') || $composer->getConfig()->get('apcu-autoloader');

        $install
            ->setVerbose($input->getOption('verbose'))
            ->setDevMode($updateDevMode)
            ->setOptimizeAutoloader($optimize)
            ->setClassMapAuthoritative($authoritative)
            ->setApcuAutoloader($apcu)
            ->setUpdate(true)
            ->setUpdateAllowList($packages)
            ->setUpdateAllowTransitiveDependencies($input->getOption('no-update-with-dependencies') ? Request::UPDATE_ONLY_LISTED : Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS_NO_ROOT_REQUIRE)
            ->setIgnorePlatformRequirements($input->getOption('ignore-platform-reqs'))
            ->setRunScripts(!$input->getOption('no-scripts'))
            ->setDryRun($dryRun)
        ;

        $status = $install->run();
        if ($status !== 0) {
            $io->writeError("\n".'<error>Removal failed, reverting '.$file.' to its original content.</error>');
            file_put_contents($jsonFile->getPath(), $composerBackup);
        }

        return $status;
    }
}
