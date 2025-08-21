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

use Composer\DependencyResolver\Request;
use Composer\Package\AliasPackage;
use Composer\Package\CompletePackageInterface;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Package\Locker;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionBumper;
use Composer\Package\Version\VersionSelector;
use Composer\Pcre\Preg;
use Composer\Repository\RepositorySet;
use Composer\Util\Filesystem;
use Composer\Util\PackageSorter;
use Seld\Signal\SignalHandler;
use Symfony\Component\Console\Input\InputInterface;
use Composer\Console\Input\InputArgument;
use Composer\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Factory;
use Composer\Installer;
use Composer\Installer\InstallerEvents;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\Version\VersionParser;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\BasePackage;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\IO\IOInterface;
use Composer\Advisory\Auditor;
use Composer\Util\Silencer;

/**
 * @author Jérémy Romey <jeremy@free-agent.fr>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class RequireCommand extends BaseCommand
{
    use CompletionTrait;
    use PackageDiscoveryTrait;

    /** @var bool */
    private $newlyCreated;
    /** @var bool */
    private $firstRequire;
    /** @var JsonFile */
    private $json;
    /** @var string */
    private $file;
    /** @var string */
    private $composerBackup;
    /** @var string file name */
    private $lock;
    /** @var ?string contents before modification if the lock file exists */
    private $lockBackup;
    /** @var bool */
    private $dependencyResolutionCompleted = false;

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('require')
            ->setAliases(['r'])
            ->setDescription('Adds required packages to your composer.json and installs them')
            ->setDefinition([
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Optional package name can also include a version constraint, e.g. foo/bar or foo/bar:1.0.0 or foo/bar=1.0.0 or "foo/bar 1.0.0"', null, $this->suggestAvailablePackageInclPlatform()),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Add requirement to require-dev.'),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Outputs the operations but will not execute anything (implicitly enables --verbose).'),
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
                new InputOption('prefer-dist', null, InputOption::VALUE_NONE, 'Forces installation from package dist (default behavior).'),
                new InputOption('prefer-install', null, InputOption::VALUE_REQUIRED, 'Forces installation from package dist|source|auto (auto chooses source for dev versions, dist for the rest).', null, $this->suggestPreferInstall()),
                new InputOption('fixed', null, InputOption::VALUE_NONE, 'Write fixed version to the composer.json.'),
                new InputOption('no-suggest', null, InputOption::VALUE_NONE, 'DEPRECATED: This flag does not exist anymore.'),
                new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
                new InputOption('no-update', null, InputOption::VALUE_NONE, 'Disables the automatic update of the dependencies (implies --no-install).'),
                new InputOption('no-install', null, InputOption::VALUE_NONE, 'Skip the install step after updating the composer.lock file.'),
                new InputOption('no-audit', null, InputOption::VALUE_NONE, 'Skip the audit step after updating the composer.lock file (can also be set via the COMPOSER_NO_AUDIT=1 env var).'),
                new InputOption('audit-format', null, InputOption::VALUE_REQUIRED, 'Audit output format. Must be "table", "plain", "json", or "summary".', Auditor::FORMAT_SUMMARY, Auditor::FORMATS),
                new InputOption('update-no-dev', null, InputOption::VALUE_NONE, 'Run the dependency update with the --no-dev option.'),
                new InputOption('update-with-dependencies', 'w', InputOption::VALUE_NONE, 'Allows inherited dependencies to be updated, except those that are root requirements (can also be set via the COMPOSER_WITH_DEPENDENCIES=1 env var).'),
                new InputOption('update-with-all-dependencies', 'W', InputOption::VALUE_NONE, 'Allows all inherited dependencies to be updated, including those that are root requirements (can also be set via the COMPOSER_WITH_ALL_DEPENDENCIES=1 env var).'),
                new InputOption('with-dependencies', null, InputOption::VALUE_NONE, 'Alias for --update-with-dependencies'),
                new InputOption('with-all-dependencies', null, InputOption::VALUE_NONE, 'Alias for --update-with-all-dependencies'),
                new InputOption('ignore-platform-req', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore a specific platform requirement (php & ext- packages).'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore all platform requirements (php & ext- packages).'),
                new InputOption('prefer-stable', null, InputOption::VALUE_NONE, 'Prefer stable versions of dependencies (can also be set via the COMPOSER_PREFER_STABLE=1 env var).'),
                new InputOption('prefer-lowest', null, InputOption::VALUE_NONE, 'Prefer lowest versions of dependencies (can also be set via the COMPOSER_PREFER_LOWEST=1 env var).'),
                new InputOption('minimal-changes', 'm', InputOption::VALUE_NONE, 'During an update with -w/-W, only perform absolutely necessary changes to transitive dependencies (can also be set via the COMPOSER_MINIMAL_CHANGES=1 env var).'),
                new InputOption('sort-packages', null, InputOption::VALUE_NONE, 'Sorts packages when adding/updating a new dependency'),
                new InputOption('optimize-autoloader', 'o', InputOption::VALUE_NONE, 'Optimize autoloader during autoloader dump'),
                new InputOption('classmap-authoritative', 'a', InputOption::VALUE_NONE, 'Autoload classes from the classmap only. Implicitly enables `--optimize-autoloader`.'),
                new InputOption('apcu-autoloader', null, InputOption::VALUE_NONE, 'Use APCu to cache found/not-found classes.'),
                new InputOption('apcu-autoloader-prefix', null, InputOption::VALUE_REQUIRED, 'Use a custom prefix for the APCu autoloader cache. Implicitly enables --apcu-autoloader'),
            ])
            ->setHelp(
                <<<EOT
The require command adds required packages to your composer.json and installs them.

If you do not specify a package, composer will prompt you to search for a package, and given results, provide a list of
matches to require.

If you do not specify a version constraint, composer will choose a suitable one based on the available package versions.

If you do not want to install the new dependencies immediately you can call it with --no-update

Read more at https://getcomposer.org/doc/03-cli.md#require-r
EOT
            )
        ;
    }

    /**
     * @throws \Seld\JsonLint\ParsingException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->file = Factory::getComposerFile();
        $io = $this->getIO();

        if ($input->getOption('no-suggest')) {
            $io->writeError('<warning>You are using the deprecated option "--no-suggest". It has no effect and will break in Composer 3.</warning>');
        }

        $this->newlyCreated = !file_exists($this->file);
        if ($this->newlyCreated && !file_put_contents($this->file, "{\n}\n")) {
            $io->writeError('<error>'.$this->file.' could not be created.</error>');

            return 1;
        }
        if (!Filesystem::isReadable($this->file)) {
            $io->writeError('<error>'.$this->file.' is not readable.</error>');

            return 1;
        }

        if (filesize($this->file) === 0) {
            file_put_contents($this->file, "{\n}\n");
        }

        $this->json = new JsonFile($this->file);
        $this->lock = Factory::getLockFile($this->file);
        $this->composerBackup = file_get_contents($this->json->getPath());
        $this->lockBackup = file_exists($this->lock) ? file_get_contents($this->lock) : null;

        $signalHandler = SignalHandler::create([SignalHandler::SIGINT, SignalHandler::SIGTERM, SignalHandler::SIGHUP], function (string $signal, SignalHandler $handler) {
            $this->getIO()->writeError('Received '.$signal.', aborting', true, IOInterface::DEBUG);
            $this->revertComposerFile();
            $handler->exitWithLastSignal();
        });

        // check for writability by writing to the file as is_writable can not be trusted on network-mounts
        // see https://github.com/composer/composer/issues/8231 and https://bugs.php.net/bug.php?id=68926
        if (!is_writable($this->file) && false === Silencer::call('file_put_contents', $this->file, $this->composerBackup)) {
            $io->writeError('<error>'.$this->file.' is not writable.</error>');

            return 1;
        }

        if ($input->getOption('fixed') === true) {
            $config = $this->json->read();

            $packageType = empty($config['type']) ? 'library' : $config['type'];

            /**
             * @see https://github.com/composer/composer/pull/8313#issuecomment-532637955
             */
            if ($packageType !== 'project' && !$input->getOption('dev')) {
                $io->writeError('<error>The "--fixed" option is only allowed for packages with a "project" type or for dev dependencies to prevent possible misuses.</error>');

                if (!isset($config['type'])) {
                    $io->writeError('<error>If your package is not a library, you can explicitly specify the "type" by using "composer config type project".</error>');
                }

                return 1;
            }
        }

        $composer = $this->requireComposer();
        $repos = $composer->getRepositoryManager()->getRepositories();

        $platformOverrides = $composer->getConfig()->get('platform');
        // initialize $this->repos as it is used by the PackageDiscoveryTrait
        $this->repos = new CompositeRepository(array_merge(
            [$platformRepo = new PlatformRepository([], $platformOverrides)],
            $repos
        ));

        if ($composer->getPackage()->getPreferStable()) {
            $preferredStability = 'stable';
        } else {
            $preferredStability = $composer->getPackage()->getMinimumStability();
        }

        try {
            $requirements = $this->determineRequirements(
                $input,
                $output,
                $input->getArgument('packages'),
                $platformRepo,
                $preferredStability,
                $input->getOption('no-update'), // if there is no update, we need to use the best possible version constraint directly as we cannot rely on the solver to guess the best constraint
                $input->getOption('fixed')
            );
        } catch (\Exception $e) {
            if ($this->newlyCreated) {
                $this->revertComposerFile();

                throw new \RuntimeException('No composer.json present in the current directory ('.$this->file.'), this may be the cause of the following exception.', 0, $e);
            }

            throw $e;
        }

        $requirements = $this->formatRequirements($requirements);

        if (!$input->getOption('dev') && $io->isInteractive() && !$composer->isGlobal()) {
            $devPackages = [];
            $devTags = ['dev', 'testing', 'static analysis'];
            $currentRequiresByKey = $this->getPackagesByRequireKey();
            foreach ($requirements as $name => $version) {
                // skip packages which are already in the composer.json as those have already been decided
                if (isset($currentRequiresByKey[$name])) {
                    continue;
                }

                $pkg = PackageSorter::getMostCurrentVersion($this->getRepos()->findPackages($name));
                if ($pkg instanceof CompletePackageInterface) {
                    $pkgDevTags = array_intersect($devTags, array_map('strtolower', $pkg->getKeywords()));
                    if (count($pkgDevTags) > 0) {
                        $devPackages[] = $pkgDevTags;
                    }
                }
            }

            if (count($devPackages) === count($requirements)) {
                $plural = count($requirements) > 1 ? 's' : '';
                $plural2 = count($requirements) > 1 ? 'are' : 'is';
                $plural3 = count($requirements) > 1 ? 'they are' : 'it is';
                $pkgDevTags = array_unique(array_merge(...$devPackages));
                $io->warning('The package'.$plural.' you required '.$plural2.' recommended to be placed in require-dev (because '.$plural3.' tagged as "'.implode('", "', $pkgDevTags).'") but you did not use --dev.');
                if ($io->askConfirmation('<info>Do you want to re-run the command with --dev?</> [<comment>yes</>]? ')) {
                    $input->setOption('dev', true);
                }
            }

            unset($devPackages, $pkgDevTags);
        }

        $requireKey = $input->getOption('dev') ? 'require-dev' : 'require';
        $removeKey = $input->getOption('dev') ? 'require' : 'require-dev';

        // check which requirements need the version guessed
        $requirementsToGuess = [];
        foreach ($requirements as $package => $constraint) {
            if ($constraint === 'guess') {
                $requirements[$package] = '*';
                $requirementsToGuess[] = $package;
            }
        }

        // validate requirements format
        $versionParser = new VersionParser();
        foreach ($requirements as $package => $constraint) {
            if (strtolower($package) === $composer->getPackage()->getName()) {
                $io->writeError(sprintf('<error>Root package \'%s\' cannot require itself in its composer.json</error>', $package));

                return 1;
            }
            if ($constraint === 'self.version') {
                continue;
            }
            $versionParser->parseConstraints($constraint);
        }

        $inconsistentRequireKeys = $this->getInconsistentRequireKeys($requirements, $requireKey);
        if (count($inconsistentRequireKeys) > 0) {
            foreach ($inconsistentRequireKeys as $package) {
                $io->warning(sprintf(
                    '%s is currently present in the %s key and you ran the command %s the --dev flag, which will move it to the %s key.',
                    $package,
                    $removeKey,
                    $input->getOption('dev') ? 'with' : 'without',
                    $requireKey
                ));
            }

            if ($io->isInteractive()) {
                if (!$io->askConfirmation(sprintf('<info>Do you want to move %s?</info> [<comment>no</comment>]? ', count($inconsistentRequireKeys) > 1 ? 'these requirements' : 'this requirement'), false)) {
                    if (!$io->askConfirmation(sprintf('<info>Do you want to re-run the command %s --dev?</info> [<comment>yes</comment>]? ', $input->getOption('dev') ? 'without' : 'with'), true)) {
                        return 0;
                    }

                    $input->setOption('dev', true);
                    [$requireKey, $removeKey] = [$removeKey, $requireKey];
                }
            }
        }

        $sortPackages = $input->getOption('sort-packages') || $composer->getConfig()->get('sort-packages');

        $this->firstRequire = $this->newlyCreated;
        if (!$this->firstRequire) {
            $composerDefinition = $this->json->read();
            if (count($composerDefinition['require'] ?? []) === 0 && count($composerDefinition['require-dev'] ?? []) === 0) {
                $this->firstRequire = true;
            }
        }

        if (!$input->getOption('dry-run')) {
            $this->updateFile($this->json, $requirements, $requireKey, $removeKey, $sortPackages);
        }

        $io->writeError('<info>'.$this->file.' has been '.($this->newlyCreated ? 'created' : 'updated').'</info>');

        if ($input->getOption('no-update')) {
            return 0;
        }

        $composer->getPluginManager()->deactivateInstalledPlugins();

        try {
            $result = $this->doUpdate($input, $output, $io, $requirements, $requireKey, $removeKey);
            if ($result === 0 && count($requirementsToGuess) > 0) {
                $result = $this->updateRequirementsAfterResolution($requirementsToGuess, $requireKey, $removeKey, $sortPackages, $input->getOption('dry-run'), $input->getOption('fixed'));
            }

            return $result;
        } catch (\Exception $e) {
            if (!$this->dependencyResolutionCompleted) {
                $this->revertComposerFile();
            }
            throw $e;
        } finally {
            if ($input->getOption('dry-run') && $this->newlyCreated) {
                @unlink($this->json->getPath());
            }

            $signalHandler->unregister();
        }
    }

    /**
     * @param array<string, string> $newRequirements
     * @return string[]
     */
    private function getInconsistentRequireKeys(array $newRequirements, string $requireKey): array
    {
        $requireKeys = $this->getPackagesByRequireKey();
        $inconsistentRequirements = [];
        foreach ($requireKeys as $package => $packageRequireKey) {
            if (!isset($newRequirements[$package])) {
                continue;
            }
            if ($requireKey !== $packageRequireKey) {
                $inconsistentRequirements[] = $package;
            }
        }

        return $inconsistentRequirements;
    }

    /**
     * @return array<string, string>
     */
    private function getPackagesByRequireKey(): array
    {
        $composerDefinition = $this->json->read();
        $require = [];
        $requireDev = [];

        if (isset($composerDefinition['require'])) {
            $require = $composerDefinition['require'];
        }

        if (isset($composerDefinition['require-dev'])) {
            $requireDev = $composerDefinition['require-dev'];
        }

        return array_merge(
            array_fill_keys(array_keys($require), 'require'),
            array_fill_keys(array_keys($requireDev), 'require-dev')
        );
    }

    /**
     * @param array<string, string> $requirements
     * @param 'require'|'require-dev' $requireKey
     * @param 'require'|'require-dev' $removeKey
     * @throws \Exception
     */
    private function doUpdate(InputInterface $input, OutputInterface $output, IOInterface $io, array $requirements, string $requireKey, string $removeKey): int
    {
        // Update packages
        $this->resetComposer();
        $composer = $this->requireComposer();

        $this->dependencyResolutionCompleted = false;
        $composer->getEventDispatcher()->addListener(InstallerEvents::PRE_OPERATIONS_EXEC, function (): void {
            $this->dependencyResolutionCompleted = true;
        }, 10000);

        if ($input->getOption('dry-run')) {
            $rootPackage = $composer->getPackage();
            $links = [
                'require' => $rootPackage->getRequires(),
                'require-dev' => $rootPackage->getDevRequires(),
            ];
            $loader = new ArrayLoader();
            $newLinks = $loader->parseLinks($rootPackage->getName(), $rootPackage->getPrettyVersion(), BasePackage::$supportedLinkTypes[$requireKey]['method'], $requirements);
            $links[$requireKey] = array_merge($links[$requireKey], $newLinks);
            foreach ($requirements as $package => $constraint) {
                unset($links[$removeKey][$package]);
            }
            $rootPackage->setRequires($links['require']);
            $rootPackage->setDevRequires($links['require-dev']);

            // extract stability flags & references as they weren't present when loading the unmodified composer.json
            $references = $rootPackage->getReferences();
            $references = RootPackageLoader::extractReferences($requirements, $references);
            $rootPackage->setReferences($references);
            $stabilityFlags = $rootPackage->getStabilityFlags();
            $stabilityFlags = RootPackageLoader::extractStabilityFlags($requirements, $rootPackage->getMinimumStability(), $stabilityFlags);
            $rootPackage->setStabilityFlags($stabilityFlags);
            unset($stabilityFlags, $references);
        }

        $updateDevMode = !$input->getOption('update-no-dev');
        $optimize = $input->getOption('optimize-autoloader') || $composer->getConfig()->get('optimize-autoloader');
        $authoritative = $input->getOption('classmap-authoritative') || $composer->getConfig()->get('classmap-authoritative');
        $apcuPrefix = $input->getOption('apcu-autoloader-prefix');
        $apcu = $apcuPrefix !== null || $input->getOption('apcu-autoloader') || $composer->getConfig()->get('apcu-autoloader');

        $updateAllowTransitiveDependencies = Request::UPDATE_ONLY_LISTED;
        $flags = '';
        if ($input->getOption('update-with-all-dependencies') || $input->getOption('with-all-dependencies')) {
            $updateAllowTransitiveDependencies = Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS;
            $flags .= ' --with-all-dependencies';
        } elseif ($input->getOption('update-with-dependencies') || $input->getOption('with-dependencies')) {
            $updateAllowTransitiveDependencies = Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS_NO_ROOT_REQUIRE;
            $flags .= ' --with-dependencies';
        }

        $io->writeError('<info>Running composer update '.implode(' ', array_keys($requirements)).$flags.'</info>');

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'require', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $composer->getInstallationManager()->setOutputProgress(!$input->getOption('no-progress'));

        $install = Installer::create($io, $composer);

        [$preferSource, $preferDist] = $this->getPreferredInstallOptions($composer->getConfig(), $input);

        $install
            ->setDryRun($input->getOption('dry-run'))
            ->setVerbose($input->getOption('verbose'))
            ->setPreferSource($preferSource)
            ->setPreferDist($preferDist)
            ->setDevMode($updateDevMode)
            ->setOptimizeAutoloader($optimize)
            ->setClassMapAuthoritative($authoritative)
            ->setApcuAutoloader($apcu, $apcuPrefix)
            ->setUpdate(true)
            ->setInstall(!$input->getOption('no-install'))
            ->setUpdateAllowTransitiveDependencies($updateAllowTransitiveDependencies)
            ->setPlatformRequirementFilter($this->getPlatformRequirementFilter($input))
            ->setPreferStable($input->getOption('prefer-stable'))
            ->setPreferLowest($input->getOption('prefer-lowest'))
            ->setAudit(!$input->getOption('no-audit'))
            ->setAuditFormat($this->getAuditFormat($input))
            ->setMinimalUpdate($input->getOption('minimal-changes'))
        ;

        // if no lock is present, or the file is brand new, we do not do a
        // partial update as this is not supported by the Installer
        if (!$this->firstRequire && $composer->getLocker()->isLocked()) {
            $install->setUpdateAllowList(array_keys($requirements));
        }

        $status = $install->run();
        if ($status !== 0 && $status !== Installer::ERROR_AUDIT_FAILED) {
            if ($status === Installer::ERROR_DEPENDENCY_RESOLUTION_FAILED) {
                foreach ($this->normalizeRequirements($input->getArgument('packages')) as $req) {
                    if (!isset($req['version'])) {
                        $io->writeError('You can also try re-running composer require with an explicit version constraint, e.g. "composer require '.$req['name'].':*" to figure out if any version is installable, or "composer require '.$req['name'].':^2.1" if you know which you need.');
                        break;
                    }
                }
            }
            $this->revertComposerFile();
        }

        return $status;
    }

    /**
     * @param list<string> $requirementsToUpdate
     */
    private function updateRequirementsAfterResolution(array $requirementsToUpdate, string $requireKey, string $removeKey, bool $sortPackages, bool $dryRun, bool $fixed): int
    {
        $composer = $this->requireComposer();
        $locker = $composer->getLocker();
        $requirements = [];
        $versionSelector = new VersionSelector(new RepositorySet());
        $repo = $locker->isLocked() ? $composer->getLocker()->getLockedRepository(true) : $composer->getRepositoryManager()->getLocalRepository();
        foreach ($requirementsToUpdate as $packageName) {
            $package = $repo->findPackage($packageName, '*');
            while ($package instanceof AliasPackage) {
                $package = $package->getAliasOf();
            }

            if (!$package instanceof PackageInterface) {
                continue;
            }

            if ($fixed) {
                $requirements[$packageName] = $package->getPrettyVersion();
            } else {
                $requirements[$packageName] = $versionSelector->findRecommendedRequireVersion($package);
            }
            $this->getIO()->writeError(sprintf(
                'Using version <info>%s</info> for <info>%s</info>',
                $requirements[$packageName],
                $packageName
            ));

            if (Preg::isMatch('{^dev-(?!main$|master$|trunk$|latest$)}', $requirements[$packageName])) {
                $this->getIO()->warning('Version '.$requirements[$packageName].' looks like it may be a feature branch which is unlikely to keep working in the long run and may be in an unstable state');
                if ($this->getIO()->isInteractive() && !$this->getIO()->askConfirmation('Are you sure you want to use this constraint (<comment>y</comment>) or would you rather abort (<comment>n</comment>) the whole operation [<comment>y,n</comment>]? ')) {
                    $this->revertComposerFile();

                    return 1;
                }
            }
        }

        if (!$dryRun) {
            $this->updateFile($this->json, $requirements, $requireKey, $removeKey, $sortPackages);
            if ($locker->isLocked() && $composer->getConfig()->get('lock')) {
                $stabilityFlags = RootPackageLoader::extractStabilityFlags($requirements, $composer->getPackage()->getMinimumStability(), []);
                $locker->updateHash($this->json, function (array $lockData) use ($stabilityFlags) {
                    foreach ($stabilityFlags as $packageName => $flag) {
                        $lockData['stability-flags'][$packageName] = $flag;
                    }

                    return $lockData;
                });
            }
        }

        return 0;
    }

    /**
     * @param array<string, string> $new
     */
    private function updateFile(JsonFile $json, array $new, string $requireKey, string $removeKey, bool $sortPackages): void
    {
        if ($this->updateFileCleanly($json, $new, $requireKey, $removeKey, $sortPackages)) {
            return;
        }

        $composerDefinition = $this->json->read();
        foreach ($new as $package => $version) {
            $composerDefinition[$requireKey][$package] = $version;
            unset($composerDefinition[$removeKey][$package]);
            if (isset($composerDefinition[$removeKey]) && count($composerDefinition[$removeKey]) === 0) {
                unset($composerDefinition[$removeKey]);
            }
        }
        $this->json->write($composerDefinition);
    }

    /**
     * @param array<string, string> $new
     */
    private function updateFileCleanly(JsonFile $json, array $new, string $requireKey, string $removeKey, bool $sortPackages): bool
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

        $manipulator->removeMainKeyIfEmpty($removeKey);

        file_put_contents($json->getPath(), $manipulator->getContents());

        return true;
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
    }

    private function revertComposerFile(): void
    {
        $io = $this->getIO();

        if ($this->newlyCreated) {
            $io->writeError("\n".'<error>Installation failed, deleting '.$this->file.'.</error>');
            unlink($this->json->getPath());
            if (file_exists($this->lock)) {
                unlink($this->lock);
            }
        } else {
            $msg = ' to its ';
            if ($this->lockBackup) {
                $msg = ' and '.$this->lock.' to their ';
            }
            $io->writeError("\n".'<error>Installation failed, reverting '.$this->file.$msg.'original content.</error>');
            file_put_contents($this->json->getPath(), $this->composerBackup);
            if ($this->lockBackup) {
                file_put_contents($this->lock, $this->lockBackup);
            }
        }
    }
}
