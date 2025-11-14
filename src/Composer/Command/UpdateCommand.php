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

use Composer\Composer;
use Composer\DependencyResolver\Request;
use Composer\Installer;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Package\Version\VersionSelector;
use Composer\Pcre\Preg;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Package\Version\VersionParser;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositorySet;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Intervals;
use Composer\Util\HttpDownloader;
use Composer\Advisory\Auditor;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Composer\Console\Input\InputOption;
use Composer\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Nils Adermann <naderman@naderman.de>
 */
class UpdateCommand extends BaseCommand
{
    use CompletionTrait;

    protected function configure(): void
    {
        $this
            ->setName('update')
            ->setAliases(['u', 'upgrade'])
            ->setDescription('Updates your dependencies to the latest version according to composer.json, and updates the composer.lock file')
            ->setDefinition([
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Packages that should be updated, if not provided all packages are.', null, $this->suggestInstalledPackage(false)),
                new InputOption('with', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Temporary version constraint to add, e.g. foo/bar:1.0.0 or foo/bar=1.0.0'),
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
                new InputOption('prefer-dist', null, InputOption::VALUE_NONE, 'Forces installation from package dist (default behavior).'),
                new InputOption('prefer-install', null, InputOption::VALUE_REQUIRED, 'Forces installation from package dist|source|auto (auto chooses source for dev versions, dist for the rest).', null, $this->suggestPreferInstall()),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Outputs the operations but will not execute anything (implicitly enables --verbose).'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'DEPRECATED: Enables installation of require-dev packages (enabled by default, only present for BC).'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables installation of require-dev packages.'),
                new InputOption('lock', null, InputOption::VALUE_NONE, 'Overwrites the lock file hash to suppress warning about the lock file being out of date without updating package versions. Package metadata like mirrors and URLs are updated if they changed.'),
                new InputOption('no-install', null, InputOption::VALUE_NONE, 'Skip the install step after updating the composer.lock file.'),
                new InputOption('no-audit', null, InputOption::VALUE_NONE, 'Skip the audit step after updating the composer.lock file (can also be set via the COMPOSER_NO_AUDIT=1 env var).'),
                new InputOption('audit-format', null, InputOption::VALUE_REQUIRED, 'Audit output format. Must be "table", "plain", "json", or "summary".', Auditor::FORMAT_SUMMARY, Auditor::FORMATS),
                new InputOption('no-security-blocking', null, InputOption::VALUE_NONE, 'Allows installing packages with security advisories or that are abandoned (can also be set via the COMPOSER_NO_SECURITY_BLOCKING=1 env var).'),
                new InputOption('no-autoloader', null, InputOption::VALUE_NONE, 'Skips autoloader generation'),
                new InputOption('no-suggest', null, InputOption::VALUE_NONE, 'DEPRECATED: This flag does not exist anymore.'),
                new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
                new InputOption('with-dependencies', 'w', InputOption::VALUE_NONE, 'Update also dependencies of packages in the argument list, except those which are root requirements (can also be set via the COMPOSER_WITH_DEPENDENCIES=1 env var).'),
                new InputOption('with-all-dependencies', 'W', InputOption::VALUE_NONE, 'Update also dependencies of packages in the argument list, including those which are root requirements (can also be set via the COMPOSER_WITH_ALL_DEPENDENCIES=1 env var).'),
                new InputOption('verbose', 'v|vv|vvv', InputOption::VALUE_NONE, 'Shows more details including new commits pulled in when updating packages.'),
                new InputOption('optimize-autoloader', 'o', InputOption::VALUE_NONE, 'Optimize autoloader during autoloader dump.'),
                new InputOption('classmap-authoritative', 'a', InputOption::VALUE_NONE, 'Autoload classes from the classmap only. Implicitly enables `--optimize-autoloader`.'),
                new InputOption('apcu-autoloader', null, InputOption::VALUE_NONE, 'Use APCu to cache found/not-found classes.'),
                new InputOption('apcu-autoloader-prefix', null, InputOption::VALUE_REQUIRED, 'Use a custom prefix for the APCu autoloader cache. Implicitly enables --apcu-autoloader'),
                new InputOption('ignore-platform-req', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore a specific platform requirement (php & ext- packages).'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore all platform requirements (php & ext- packages).'),
                new InputOption('prefer-stable', null, InputOption::VALUE_NONE, 'Prefer stable versions of dependencies (can also be set via the COMPOSER_PREFER_STABLE=1 env var).'),
                new InputOption('prefer-lowest', null, InputOption::VALUE_NONE, 'Prefer lowest versions of dependencies (can also be set via the COMPOSER_PREFER_LOWEST=1 env var).'),
                new InputOption('minimal-changes', 'm', InputOption::VALUE_NONE, 'Only perform absolutely necessary changes to dependencies. If packages cannot be kept at their currently locked version they are updated. For partial updates the allow-listed packages are always updated fully. (can also be set via the COMPOSER_MINIMAL_CHANGES=1 env var).'),
                new InputOption('patch-only', null, InputOption::VALUE_NONE, 'Only allow patch version updates for currently installed dependencies.'),
                new InputOption('interactive', 'i', InputOption::VALUE_NONE, 'Interactive interface with autocompletion to select the packages to update.'),
                new InputOption('root-reqs', null, InputOption::VALUE_NONE, 'Restricts the update to your first degree dependencies.'),
                new InputOption('bump-after-update', null, InputOption::VALUE_OPTIONAL, 'Runs bump after performing the update.', false, ['dev', 'no-dev', 'all']),
            ])
            ->setHelp(
                <<<EOT
The <info>update</info> command reads the composer.json file from the
current directory, processes it, and updates, removes or installs all the
dependencies.

<info>php composer.phar update</info>

To limit the update operation to a few packages, you can list the package(s)
you want to update as such:

<info>php composer.phar update vendor/package1 foo/mypackage [...]</info>

You may also use an asterisk (*) pattern to limit the update operation to package(s)
from a specific vendor:

<info>php composer.phar update vendor/package1 foo/* [...]</info>

To run an update with more restrictive constraints you can use:

<info>php composer.phar update --with vendor/package:1.0.*</info>

To run a partial update with more restrictive constraints you can use the shorthand:

<info>php composer.phar update vendor/package:1.0.*</info>

To select packages names interactively with auto-completion use <info>-i</info>.

Read more at https://getcomposer.org/doc/03-cli.md#update-u-upgrade
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        if ($input->getOption('dev')) {
            $io->writeError('<warning>You are using the deprecated option "--dev". It has no effect and will break in Composer 3.</warning>');
        }
        if ($input->getOption('no-suggest')) {
            $io->writeError('<warning>You are using the deprecated option "--no-suggest". It has no effect and will break in Composer 3.</warning>');
        }

        $composer = $this->requireComposer();

        if (!HttpDownloader::isCurlEnabled()) {
            $io->writeError('<warning>Composer is operating significantly slower than normal because you do not have the PHP curl extension enabled.</warning>');
        }

        $packages = $input->getArgument('packages');
        $reqs = $this->formatRequirements($input->getOption('with'));

        // extract --with shorthands from the allowlist
        if (count($packages) > 0) {
            $allowlistPackagesWithRequirements = array_filter($packages, static function ($pkg): bool {
                return Preg::isMatch('{\S+[ =:]\S+}', $pkg);
            });
            foreach ($this->formatRequirements($allowlistPackagesWithRequirements) as $package => $constraint) {
                $reqs[$package] = $constraint;
            }

            // replace the foo/bar:req by foo/bar in the allowlist
            foreach ($allowlistPackagesWithRequirements as $package) {
                $packageName = Preg::replace('{^([^ =:]+)[ =:].*$}', '$1', $package);
                $index = array_search($package, $packages);
                $packages[$index] = $packageName;
            }
        }

        $rootPackage = $composer->getPackage();
        $rootPackage->setReferences(RootPackageLoader::extractReferences($reqs, $rootPackage->getReferences()));
        $rootPackage->setStabilityFlags(RootPackageLoader::extractStabilityFlags($reqs, $rootPackage->getMinimumStability(), $rootPackage->getStabilityFlags()));

        $parser = new VersionParser;
        $temporaryConstraints = [];
        $rootRequirements = array_merge($rootPackage->getRequires(), $rootPackage->getDevRequires());
        foreach ($reqs as $package => $constraint) {
            $package = strtolower($package);
            $parsedConstraint = $parser->parseConstraints($constraint);
            $temporaryConstraints[$package] = $parsedConstraint;
            if (isset($rootRequirements[$package]) && !Intervals::haveIntersections($parsedConstraint, $rootRequirements[$package]->getConstraint())) {
                $io->writeError('<error>The temporary constraint "'.$constraint.'" for "'.$package.'" must be a subset of the constraint in your composer.json ('.$rootRequirements[$package]->getPrettyConstraint().')</error>');
                $io->write('<info>Run `composer require '.$package.'` or `composer require '.$package.':'.$constraint.'` instead to replace the constraint</info>');

                return self::FAILURE;
            }
        }

        if ($input->getOption('patch-only')) {
            if (!$composer->getLocker()->isLocked()) {
                throw new \InvalidArgumentException('patch-only can only be used with a lock file present');
            }
            foreach ($composer->getLocker()->getLockedRepository(true)->getCanonicalPackages() as $package) {
                if ($package->isDev()) {
                    continue;
                }
                if (!Preg::isMatch('{^(\d+\.\d+\.\d+)}', $package->getVersion(), $match)) {
                    continue;
                }
                $constraint = $parser->parseConstraints('~'.$match[1]);
                if (isset($temporaryConstraints[$package->getName()])) {
                    $temporaryConstraints[$package->getName()] = MultiConstraint::create([$temporaryConstraints[$package->getName()], $constraint], true);
                } else {
                    $temporaryConstraints[$package->getName()] = $constraint;
                }
            }
        }

        if ($input->getOption('interactive')) {
            $packages = $this->getPackagesInteractively($io, $input, $output, $composer, $packages);
        }

        if ($input->getOption('root-reqs')) {
            $requires = array_keys($rootPackage->getRequires());
            if (!$input->getOption('no-dev')) {
                $requires = array_merge($requires, array_keys($rootPackage->getDevRequires()));
            }

            if (!empty($packages)) {
                $packages = array_intersect($packages, $requires);
            } else {
                $packages = $requires;
            }
        }

        // the arguments lock/nothing/mirrors are not package names but trigger a mirror update instead
        // they are further mutually exclusive with listing actual package names
        $filteredPackages = array_filter($packages, static function ($package): bool {
            return !in_array($package, ['lock', 'nothing', 'mirrors'], true);
        });
        $updateMirrors = $input->getOption('lock') || count($filteredPackages) !== count($packages);
        $packages = $filteredPackages;

        if ($updateMirrors && !empty($packages)) {
            $io->writeError('<error>You cannot simultaneously update only a selection of packages and regenerate the lock file metadata.</error>');

            return -1;
        }

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'update', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $composer->getInstallationManager()->setOutputProgress(!$input->getOption('no-progress'));

        $install = Installer::create($io, $composer);

        $config = $composer->getConfig();
        [$preferSource, $preferDist] = $this->getPreferredInstallOptions($config, $input);

        $optimize = $input->getOption('optimize-autoloader') || $config->get('optimize-autoloader');
        $authoritative = $input->getOption('classmap-authoritative') || $config->get('classmap-authoritative');
        $apcuPrefix = $input->getOption('apcu-autoloader-prefix');
        $apcu = $apcuPrefix !== null || $input->getOption('apcu-autoloader') || $config->get('apcu-autoloader');
        $minimalChanges = $input->getOption('minimal-changes') || $config->get('update-with-minimal-changes');

        $updateAllowTransitiveDependencies = Request::UPDATE_ONLY_LISTED;
        if ($input->getOption('with-all-dependencies')) {
            $updateAllowTransitiveDependencies = Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS;
        } elseif ($input->getOption('with-dependencies')) {
            $updateAllowTransitiveDependencies = Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS_NO_ROOT_REQUIRE;
        }

        $install
            ->setDryRun($input->getOption('dry-run'))
            ->setVerbose($input->getOption('verbose'))
            ->setPreferSource($preferSource)
            ->setPreferDist($preferDist)
            ->setDevMode(!$input->getOption('no-dev'))
            ->setDumpAutoloader(!$input->getOption('no-autoloader'))
            ->setOptimizeAutoloader($optimize)
            ->setClassMapAuthoritative($authoritative)
            ->setApcuAutoloader($apcu, $apcuPrefix)
            ->setUpdate(true)
            ->setInstall(!$input->getOption('no-install'))
            ->setUpdateMirrors($updateMirrors)
            ->setUpdateAllowList($packages)
            ->setUpdateAllowTransitiveDependencies($updateAllowTransitiveDependencies)
            ->setPlatformRequirementFilter($this->getPlatformRequirementFilter($input))
            ->setPreferStable($input->getOption('prefer-stable'))
            ->setPreferLowest($input->getOption('prefer-lowest'))
            ->setTemporaryConstraints($temporaryConstraints)
            ->setAudit(!$input->getOption('no-audit'))
            ->setAuditFormat($this->getAuditFormat($input))
            ->setAuditConfig($this->createAuditConfig($composer->getConfig(), $input))
            ->setMinimalUpdate($minimalChanges)
        ;

        if ($input->getOption('no-plugins')) {
            $install->disablePlugins();
        }

        $result = $install->run();

        if ($result === 0 && !$input->getOption('lock')) {
            $bumpAfterUpdate = $input->getOption('bump-after-update');
            if (false === $bumpAfterUpdate) {
                $bumpAfterUpdate = $composer->getConfig()->get('bump-after-update');
            }

            if (false !== $bumpAfterUpdate) {
                $io->writeError('<info>Bumping dependencies</info>');
                $bumpCommand = new BumpCommand();
                $bumpCommand->setComposer($composer);
                $result = $bumpCommand->doBump(
                    $io,
                    $bumpAfterUpdate === 'dev',
                    $bumpAfterUpdate === 'no-dev',
                    $input->getOption('dry-run'),
                    $input->getArgument('packages'),
                    '--bump-after-update=dev'
                );
            }
        }

        return $result;
    }

    /**
     * @param array<string> $packages
     * @return array<string>
     */
    private function getPackagesInteractively(IOInterface $io, InputInterface $input, OutputInterface $output, Composer $composer, array $packages): array
    {
        if (!$input->isInteractive()) {
            throw new \InvalidArgumentException('--interactive cannot be used in non-interactive terminals.');
        }

        $platformReqFilter = $this->getPlatformRequirementFilter($input);
        $stabilityFlags = $composer->getPackage()->getStabilityFlags();
        $requires = array_merge(
            $composer->getPackage()->getRequires(),
            $composer->getPackage()->getDevRequires()
        );

        $filter = \count($packages) > 0 ? BasePackage::packageNamesToRegexp($packages) : null;

        $io->writeError('<info>Loading packages that can be updated...</info>');
        $autocompleterValues = [];
        $installedPackages = $composer->getLocker()->isLocked() ? $composer->getLocker()->getLockedRepository(true)->getPackages() : $composer->getRepositoryManager()->getLocalRepository()->getPackages();
        $versionSelector = $this->createVersionSelector($composer);
        foreach ($installedPackages as $package) {
            if ($filter !== null && !Preg::isMatch($filter, $package->getName())) {
                continue;
            }
            $currentVersion = $package->getPrettyVersion();
            $constraint = isset($requires[$package->getName()]) ? $requires[$package->getName()]->getPrettyConstraint() : null;
            $stability = isset($stabilityFlags[$package->getName()]) ? (string) array_search($stabilityFlags[$package->getName()], BasePackage::STABILITIES, true) : $composer->getPackage()->getMinimumStability();
            $latestVersion = $versionSelector->findBestCandidate($package->getName(), $constraint, $stability, $platformReqFilter);
            if ($latestVersion !== false && ($package->getVersion() !== $latestVersion->getVersion() || $latestVersion->isDev())) {
                $autocompleterValues[$package->getName()] = '<comment>' . $currentVersion . '</comment> => <comment>' . $latestVersion->getPrettyVersion() . '</comment>';
            }
        }
        if (0 === \count($installedPackages)) {
            foreach ($requires as $req => $constraint) {
                if (PlatformRepository::isPlatformPackage($req)) {
                    continue;
                }
                $autocompleterValues[$req] = '';
            }
        }

        if (0 === \count($autocompleterValues)) {
            throw new \RuntimeException('Could not find any package with new versions available');
        }

        $packages = $io->select(
            'Select packages: (Select more than one value separated by comma) ',
            $autocompleterValues,
            false,
            1,
            'No package named "%s" is installed.',
            true
        );

        $table = new Table($output);
        $table->setHeaders(['Selected packages']);
        foreach ($packages as $package) {
            $table->addRow([$package]);
        }
        $table->render();

        if ($io->askConfirmation(sprintf(
            'Would you like to continue and update the above package%s [<comment>yes</comment>]? ',
            1 === count($packages) ? '' : 's'
        ))) {
            return $packages;
        }

        throw new \RuntimeException('Installation aborted.');
    }

    private function createVersionSelector(Composer $composer): VersionSelector
    {
        $repositorySet = new RepositorySet();
        $repositorySet->addRepository(new CompositeRepository(array_filter($composer->getRepositoryManager()->getRepositories(), static function (RepositoryInterface $repository) {
            return !$repository instanceof PlatformRepository;
        })));

        return new VersionSelector($repositorySet);
    }
}
