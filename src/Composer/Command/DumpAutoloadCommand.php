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

use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class DumpAutoloadCommand extends BaseCommand
{
    /**
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('dump-autoload')
            ->setAliases(array('dumpautoload'))
            ->setDescription('Dumps the autoloader.')
            ->setDefinition(array(
                new InputOption('no-scripts', null, InputOption::VALUE_NONE, 'Skips the execution of all scripts defined in composer.json file.'),
                new InputOption('optimize', 'o', InputOption::VALUE_NONE, 'Optimizes PSR0 and PSR4 packages to be loaded with classmaps too, good for production.'),
                new InputOption('classmap-authoritative', 'a', InputOption::VALUE_NONE, 'Autoload classes from the classmap only. Implicitly enables `--optimize`.'),
                new InputOption('apcu', null, InputOption::VALUE_NONE, 'Use APCu to cache found/not-found classes.'),
                new InputOption('apcu-prefix', null, InputOption::VALUE_REQUIRED, 'Use a custom prefix for the APCu autoloader cache. Implicitly enables --apcu'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Enables autoload-dev rules. Composer will by default infer this automatically according to the last install or update --no-dev state.'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables autoload-dev rules. Composer will by default infer this automatically according to the last install or update --no-dev state.'),
                new InputOption('ignore-platform-req', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore a specific platform requirement (php & ext- packages).'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore all platform requirements (php & ext- packages).'),
            ))
            ->setHelp(
                <<<EOT
<info>php composer.phar dump-autoload</info>

Read more at https://getcomposer.org/doc/03-cli.md#dump-autoload-dumpautoload-
EOT
            )
        ;
    }

    /**
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();
        $composer->getEventDispatcher()->setRunScripts(!$input->getOption('no-scripts'));

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'dump-autoload', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $installationManager = $composer->getInstallationManager();
        $localRepo = $composer->getRepositoryManager()->getLocalRepository();
        $package = $composer->getPackage();
        $config = $composer->getConfig();

        $optimize = $input->getOption('optimize') || $config->get('optimize-autoloader');
        $authoritative = $input->getOption('classmap-authoritative') || $config->get('classmap-authoritative');
        $apcuPrefix = $input->getOption('apcu-prefix');
        $apcu = $apcuPrefix !== null || $input->getOption('apcu') || $config->get('apcu-autoloader');

        if ($authoritative) {
            $this->getIO()->write('<info>Generating optimized autoload files (authoritative)</info>');
        } elseif ($optimize) {
            $this->getIO()->write('<info>Generating optimized autoload files</info>');
        } else {
            $this->getIO()->write('<info>Generating autoload files</info>');
        }

        $ignorePlatformReqs = $input->getOption('ignore-platform-reqs') ?: ($input->getOption('ignore-platform-req') ?: false);

        $generator = $composer->getAutoloadGenerator();
        if ($input->getOption('no-dev')) {
            $generator->setDevMode(false);
        }
        if ($input->getOption('dev')) {
            if ($input->getOption('no-dev')) {
                throw new \InvalidArgumentException('You can not use both --no-dev and --dev as they conflict with each other.');
            }
            $generator->setDevMode(true);
        }
        $generator->setClassMapAuthoritative($authoritative);
        $generator->setRunScripts(true);
        $generator->setApcu($apcu, $apcuPrefix);
        $generator->setIgnorePlatformRequirements($ignorePlatformReqs);
        $numberOfClasses = $generator->dump($config, $localRepo, $package, $installationManager, 'composer', $optimize);

        if ($authoritative) {
            $this->getIO()->write('<info>Generated optimized autoload files (authoritative) containing '. $numberOfClasses .' classes</info>');
        } elseif ($optimize) {
            $this->getIO()->write('<info>Generated optimized autoload files containing '. $numberOfClasses .' classes</info>');
        } else {
            $this->getIO()->write('<info>Generated autoload files</info>');
        }

        return 0;
    }
}
