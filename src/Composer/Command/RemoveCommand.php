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
use Composer\Installer;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Json\JsonFile;
use Composer\Factory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Pierre du Plessis <pdples@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class RemoveCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('remove')
            ->setDescription('Removes a package from the require or require-dev')
            ->setDefinition(array(
                new InputArgument('packages', InputArgument::IS_ARRAY, 'Packages that should be removed.'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Removes a package from the require-dev section.'),
                new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
                new InputOption('no-update', null, InputOption::VALUE_NONE, 'Disables the automatic update of the dependencies.'),
                new InputOption('update-no-dev', null, InputOption::VALUE_NONE, 'Run the dependency update with the --no-dev option.'),
                new InputOption('update-with-dependencies', null, InputOption::VALUE_NONE, 'Allows inherited dependencies to be updated with explicit dependencies.'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore platform requirements (php & ext- packages).'),
            ))
            ->setHelp(<<<EOT
The <info>remove</info> command removes a package from the current
list of installed packages

<info>php composer.phar remove</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $packages = $input->getArgument('packages');

        $file = Factory::getComposerFile();

        $jsonFile = new JsonFile($file);
        $composer = $jsonFile->read();
        $composerBackup = file_get_contents($jsonFile->getPath());

        $json = new JsonConfigSource($jsonFile);

        $type = $input->getOption('dev') ? 'require-dev' : 'require';
        $altType = !$input->getOption('dev') ? 'require-dev' : 'require';

        foreach ($packages as $package) {
            if (isset($composer[$type][$package])) {
                $json->removeLink($type, $package);
            } elseif (isset($composer[$altType][$package])) {
                $output->writeln('<warning>'.$package.' could not be found in '.$type.' but it is present in '.$altType.'</warning>');
                $dialog = $this->getHelperSet()->get('dialog');
                if ($this->getIO()->isInteractive()) {
                    if ($dialog->askConfirmation($output, $dialog->getQuestion('Do you want to remove it from '.$altType, 'yes', '?'), true)) {
                        $json->removeLink($altType, $package);
                    }
                }
            } else {
                $output->writeln('<warning>'.$package.' is not required in your composer.json and has not been removed</warning>');
            }
        }

        if ($input->getOption('no-update')) {
            return 0;
        }

        // Update packages
        $composer = $this->getComposer();
        $composer->getDownloadManager()->setOutputProgress(!$input->getOption('no-progress'));
        $io = $this->getIO();

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'remove', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $install = Installer::create($io, $composer);

        $updateDevMode = !$input->getOption('update-no-dev');
        $install
            ->setVerbose($input->getOption('verbose'))
            ->setDevMode($updateDevMode)
            ->setUpdate(true)
            ->setUpdateWhitelist($packages)
            ->setWhitelistDependencies($input->getOption('update-with-dependencies'))
            ->setIgnorePlatformRequirements($input->getOption('ignore-platform-reqs'))
        ;

        $status = $install->run();
        if ($status !== 0) {
            $output->writeln("\n".'<error>Removal failed, reverting '.$file.' to its original content.</error>');
            file_put_contents($jsonFile->getPath(), $composerBackup);
        }

        return $status;
    }
}
