<?php declare(strict_types=1);

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
use Composer\Pcre\Preg;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Script\ScriptEvents;
use Composer\Util\Platform;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ReinstallCommand extends BaseCommand
{
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        $this->completeInstalledPackage($input, $suggestions) || $this->completePreferInstall($input, $suggestions);
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('reinstall')
            ->setDescription('Uninstalls and reinstalls the given package names')
            ->setDefinition(array(
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
                new InputOption('prefer-dist', null, InputOption::VALUE_NONE, 'Forces installation from package dist (default behavior).'),
                new InputOption('prefer-install', null, InputOption::VALUE_REQUIRED, 'Forces installation from package dist|source|auto (auto chooses source for dev versions, dist for the rest).'),
                new InputOption('no-autoloader', null, InputOption::VALUE_NONE, 'Skips autoloader generation'),
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();

        $composer = $this->requireComposer();

        $localRepo = $composer->getRepositoryManager()->getLocalRepository();
        $packagesToReinstall = array();
        $packageNamesToReinstall = array();
        foreach ($input->getArgument('packages') as $pattern) {
            $patternRegexp = BasePackage::packageNameToRegexp($pattern);
            $matched = false;
            foreach ($localRepo->getCanonicalPackages() as $package) {
                if (Preg::isMatch($patternRegexp, $package->getName())) {
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
        usort($uninstallOperations, function ($a, $b) use ($installOrder): int {
            return $installOrder[$b->getPackage()->getName()] - $installOrder[$a->getPackage()->getName()];
        });

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'reinstall', $input, $output);
        $eventDispatcher = $composer->getEventDispatcher();
        $eventDispatcher->dispatch($commandEvent->getName(), $commandEvent);

        $config = $composer->getConfig();
        list($preferSource, $preferDist) = $this->getPreferredInstallOptions($config, $input);

        $installationManager = $composer->getInstallationManager();
        $downloadManager = $composer->getDownloadManager();
        $package = $composer->getPackage();

        $installationManager->setOutputProgress(!$input->getOption('no-progress'));
        if ($input->getOption('no-plugins')) {
            $installationManager->disablePlugins();
        }

        $downloadManager->setPreferSource($preferSource);
        $downloadManager->setPreferDist($preferDist);

        $devMode = $localRepo->getDevMode() !== null ? $localRepo->getDevMode() : true;

        Platform::putEnv('COMPOSER_DEV_MODE', $devMode ? '1' : '0');
        $eventDispatcher->dispatchScript(ScriptEvents::PRE_INSTALL_CMD, $devMode);

        $installationManager->execute($localRepo, $uninstallOperations, $devMode);
        $installationManager->execute($localRepo, $installOperations, $devMode);

        if (!$input->getOption('no-autoloader')) {
            $optimize = $input->getOption('optimize-autoloader') || $config->get('optimize-autoloader');
            $authoritative = $input->getOption('classmap-authoritative') || $config->get('classmap-authoritative');
            $apcuPrefix = $input->getOption('apcu-autoloader-prefix');
            $apcu = $apcuPrefix !== null || $input->getOption('apcu-autoloader') || $config->get('apcu-autoloader');

            $generator = $composer->getAutoloadGenerator();
            $generator->setClassMapAuthoritative($authoritative);
            $generator->setApcu($apcu, $apcuPrefix);
            $generator->setPlatformRequirementFilter($this->getPlatformRequirementFilter($input));
            $generator->dump($config, $localRepo, $package, $installationManager, 'composer', $optimize);
        }

        $eventDispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, $devMode);

        return 0;
    }
}
