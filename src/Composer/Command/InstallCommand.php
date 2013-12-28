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

use Composer\Installer;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Ryan Weaver <ryan@knplabs.com>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Nils Adermann <naderman@naderman.de>
 */
class InstallCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('install')
            ->setDescription('Installs the project dependencies from the composer.lock file if present, or falls back on the composer.json.')
            ->setDefinition(array(
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
                new InputOption('prefer-dist', null, InputOption::VALUE_NONE, 'Forces installation from package dist even for dev versions.'),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Outputs the operations but will not execute anything (implicitly enables --verbose).'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Enables installation of require-dev packages (enabled by default, only present for BC).'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables installation of require-dev packages.'),
                new InputOption('no-plugins', null, InputOption::VALUE_NONE, 'Disables all plugins.'),
                new InputOption('no-custom-installers', null, InputOption::VALUE_NONE, 'DEPRECATED: Use no-plugins instead.'),
                new InputOption('no-scripts', null, InputOption::VALUE_NONE, 'Skips the execution of all scripts defined in composer.json file.'),
                new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
                new InputOption('verbose', 'v|vv|vvv', InputOption::VALUE_NONE, 'Shows more details including new commits pulled in when updating packages.'),
                new InputOption('optimize-autoloader', 'o', InputOption::VALUE_NONE, 'Optimize autoloader during autoloader dump')
            ))
            ->setHelp(<<<EOT
The <info>install</info> command reads the composer.lock file from
the current directory, processes it, and downloads and installs all the
libraries and dependencies outlined in that file. If the file does not
exist it will look for composer.json and do the same.

<info>php composer.phar install</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('no-custom-installers')) {
            $output->writeln('<warning>You are using the deprecated option "no-custom-installers". Use "no-plugins" instead.</warning>');
            $input->setOption('no-plugins', true);
        }

        $composer = $this->getComposer(true, $input->getOption('no-plugins'));
        $composer->getDownloadManager()->setOutputProgress(!$input->getOption('no-progress'));
        $io = $this->getIO();

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'install', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $install = Installer::create($io, $composer);

        $preferSource = false;
        $preferDist = false;
        switch ($composer->getConfig()->get('preferred-install')) {
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

        $install
            ->setDryRun($input->getOption('dry-run'))
            ->setVerbose($input->getOption('verbose'))
            ->setPreferSource($preferSource)
            ->setPreferDist($preferDist)
            ->setDevMode(!$input->getOption('no-dev'))
            ->setRunScripts(!$input->getOption('no-scripts'))
            ->setOptimizeAutoloader($input->getOption('optimize-autoloader'))
        ;

        if ($input->getOption('no-plugins')) {
            $install->disablePlugins();
        }

        return $install->run();
    }
}
