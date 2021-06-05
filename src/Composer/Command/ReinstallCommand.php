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

use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Transaction;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ReinstallCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('reinstall')
            ->setDescription('Uninstalls and reinstalls the given package names')
            ->setDefinition(array(
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
                new InputOption('prefer-dist', null, InputOption::VALUE_NONE, 'Forces installation from package dist (default behavior).'),
                new InputOption('prefer-install', null, InputOption::VALUE_REQUIRED, 'Forces installation from package dist|source|auto (auto chooses source for dev versions, dist for the rest).'),
                new InputOption('no-autoloader', null, InputOption::VALUE_NONE, 'Skips autoloader generation'),
                new InputOption('no-scripts', null, InputOption::VALUE_NONE, 'Skips the execution of all scripts defined in composer.json file.'),
                new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
                new InputOption('optimize-autoloader', 'o', InputOption::VALUE_NONE, 'Optimize autoloader during autoloader dump'),
                new InputOption('classmap-authoritative', 'a', InputOption::VALUE_NONE, 'Autoload classes from the classmap only. Implicitly enables `--optimize-autoloader`.'),
                new InputOption('apcu-autoloader', null, InputOption::VALUE_NONE, 'Use APCu to cache found/not-found classes.'),
                new InputOption('apcu-autoloader-prefix', null, InputOption::VALUE_REQUIRED, 'Use a custom prefix for the APCu autoloader cache. Implicitly enables --apcu-autoloader'),
                new InputOption('ignore-platform-req', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore a specific platform requirement (php & ext- packages).'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore all platform requirements (php & ext- packages).'),
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'List of package names to reinstall, can include a wildcard (*) to match any substring.'),
            ))
            ->setHelp(
                <<<EOT
The <info>reinstall</info> command looks up installed packages by name,
uninstalls them and reinstalls them. This lets you do a clean install
of a package if you messed with its files, or if you wish to change
the installation type using --prefer-install.

<info>php composer.phar reinstall acme/foo "acme/bar-*"</info>

Read more at https://getcomposer.org/doc/03-cli.md#reinstall
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = $this->getIO();

        $composer = $this->getComposer(true, $input->getOption('no-plugins'));
        $composer->getEventDispatcher()->setRunScripts(!$input->getOption('no-scripts'));

        $localRepo = $composer->getRepositoryManager()->getLocalRepository();
        $packagesToReinstall = array();
        $packageNamesToReinstall = array();
        foreach ($input->getArgument('packages') as $pattern) {
            $patternRegexp = BasePackage::packageNameToRegexp($pattern);
            $matched = false;
            foreach ($localRepo->getCanonicalPackages() as $package) {
                if (preg_match($patternRegexp, $package->getName())) {
                    $matched = true;
                    $packagesToReinstall[] = $package;
                    $packageNamesToReinstall[] = $package->getName();
                }
            }

            if (!$matched) {
                $io->writeError('<warning>Pattern "' . $pattern . '" does not match any currently installed packages.</warning>');
            }
        }

        if (!$packagesToReinstall) {
            $io->writeError('<warning>Found no packages to reinstall, aborting.</warning>');

            return 1;
        }

        $uninstallOperations = array();
        foreach ($packagesToReinstall as $package) {
            $uninstallOperations[] = new UninstallOperation($package);
        }

        // make sure we have a list of install operations ordered by dependency/plugins
        $presentPackages = $localRepo->getPackages();
        $resultPackages = $presentPackages;
        foreach ($presentPackages as $index => $package) {
            if (in_array($package->getName(), $packageNamesToReinstall, true)) {
                unset($presentPackages[$index]);
            }
        }
        $transaction = new Transaction($presentPackages, $resultPackages);
        $installOperations = $transaction->getOperations();

        // reverse-sort the uninstalls based on the install order
        $installOrder = array();
        foreach ($installOperations as $index => $op) {
            if ($op instanceof InstallOperation && !$op->getPackage() instanceof AliasPackage) {
                $installOrder[$op->getPackage()->getName()] = $index;
            }
        }
        usort($uninstallOperations, function ($a, $b) use ($installOrder) {
            return $installOrder[$b->getPackage()->getName()] - $installOrder[$a->getPackage()->getName()];
        });

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'reinstall', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $config = $composer->getConfig();
        list($preferSource, $preferDist) = $this->getPreferredInstallOptions($config, $input);

        $installationManager = $composer->getInstallationManager();
        $downloadManager = $composer->getDownloadManager();
        $package = $composer->getPackage();

        $ignorePlatformReqs = $input->getOption('ignore-platform-reqs') ?: ($input->getOption('ignore-platform-req') ?: false);

        $installationManager->setOutputProgress(!$input->getOption('no-progress'));
        if ($input->getOption('no-plugins')) {
            $installationManager->disablePlugins();
        }

        $downloadManager->setPreferSource($preferSource);
        $downloadManager->setPreferDist($preferDist);

        $installationManager->execute($localRepo, $uninstallOperations, true);
        $installationManager->execute($localRepo, $installOperations, true);

        if (!$input->getOption('no-autoloader')) {
            $optimize = $input->getOption('optimize-autoloader') || $config->get('optimize-autoloader');
            $authoritative = $input->getOption('classmap-authoritative') || $config->get('classmap-authoritative');
            $apcuPrefix = $input->getOption('apcu-autoloader-prefix');
            $apcu = $apcuPrefix !== null || $input->getOption('apcu-autoloader') || $config->get('apcu-autoloader');

            $generator = $composer->getAutoloadGenerator();
            $generator->setClassMapAuthoritative($authoritative);
            $generator->setApcu($apcu, $apcuPrefix);
            $generator->setIgnorePlatformRequirements($ignorePlatformReqs);
            $generator->dump($config, $localRepo, $package, $installationManager, 'composer', $optimize);
        }

        return 0;
    }
}
