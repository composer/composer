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
 */
class RemoveCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('remove')
            ->setDescription('Removes a package from the require or require-dev')
            ->setDefinition(array(
                new InputArgument('packages', InputArgument::IS_ARRAY, 'Packages that should be removed, if not provided all packages are.'),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Outputs the operations but will not execute anything (implicitly enables --verbose).'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Removes a package from the require-dev section'),
                new InputOption('verbose', 'v|vv|vvv', InputOption::VALUE_NONE, 'Shows more details including new commits pulled in when updating packages.'),
                new InputOption('no-update', null, InputOption::VALUE_NONE, 'Disables the automatic update of the dependencies.')
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
        $composer = $this->getComposer();
        $packages = $input->getArgument('packages');

        $io = $this->getIO();

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'remove', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $file = Factory::getComposerFile();

        $json = new JsonFile($file);
        $composerBackup = file_get_contents($json->getPath());

        $json = new JsonConfigSource($json);

        $type = $input->getOption('dev') ? 'require-dev' : 'require';

        foreach ($packages as $package) {
            $json->removeLink($type, $package);
        }

        if ($input->getOption('no-update')) {
            if ($input->getOption('dry-run')) {
                file_put_contents($json->getPath(), $composerBackup);
            }

            return 0;
        }

        $composer = Factory::create($io);

        $install = Installer::create($io, $composer);

        $install
            ->setDryRun($input->getOption('dry-run'))
            ->setVerbose($input->getOption('verbose'))
            ->setDevMode($input->getOption('dev'))
            ->setUpdate(true)
            ->setUpdateWhitelist($packages)
        ;

        if (!$install->run()) {
            $output->writeln("\n".'<error>Remove failed, reverting '.$file.' to its original content.</error>');
            file_put_contents($json->getPath(), $composerBackup);

            return 1;
        }

        return 0;
    }
}
