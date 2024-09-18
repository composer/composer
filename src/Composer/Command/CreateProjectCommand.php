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

use Composer\Config;
use Composer\Factory;
use Composer\Filter\PlatformRequirementFilter\IgnoreAllPlatformRequirementFilter;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterInterface;
use Composer\Installer;
use Composer\Installer\ProjectInstaller;
use Composer\Installer\SuggestedPackagesReporter;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\Package\Version\VersionSelector;
use Composer\Package\AliasPackage;
use Composer\Pcre\Preg;
use Composer\Plugin\PluginBlockedException;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositorySet;
use Composer\Script\ScriptEvents;
use Composer\Util\Silencer;
use Composer\Console\Input\InputArgument;
use Seld\Signal\SignalHandler;
use Symfony\Component\Console\Input\InputInterface;
use Composer\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Composer\Json\JsonFile;
use Composer\Config\JsonConfigSource;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use Composer\Package\Version\VersionParser;
use Composer\Advisory\Auditor;

/**
 * Install a package as new project into new directory.
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Tobias Munk <schmunk@usrbin.de>
 * @author Nils Adermann <naderman@naderman.de>
 */
class CreateProjectCommand extends BaseCommand
{
    use CompletionTrait;

    /**
     * @var SuggestedPackagesReporter
     */
    protected $suggestedPackagesReporter;

    protected function configure(): void
    {
        $this
            ->setName('create-project')
            ->setDescription('Creates new project from a package into given directory')
            ->setDefinition([
                new InputArgument('package', InputArgument::OPTIONAL, 'Package name to be installed', null, $this->suggestAvailablePackage()),
                new InputArgument('directory', InputArgument::OPTIONAL, 'Directory where the files should be created'),
                new InputArgument('version', InputArgument::OPTIONAL, 'Version, will default to latest'),
                new InputOption('stability', 's', InputOption::VALUE_REQUIRED, 'Minimum-stability allowed (unless a version is specified).'),
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
                new InputOption('prefer-dist', null, InputOption::VALUE_NONE, 'Forces installation from package dist (default behavior).'),
                new InputOption('prefer-install', null, InputOption::VALUE_REQUIRED, 'Forces installation from package dist|source|auto (auto chooses source for dev versions, dist for the rest).', null, $this->suggestPreferInstall()),
                new InputOption('repository', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Add custom repositories to look the package up, either by URL or using JSON arrays'),
                new InputOption('repository-url', null, InputOption::VALUE_REQUIRED, 'DEPRECATED: Use --repository instead.'),
                new InputOption('add-repository', null, InputOption::VALUE_NONE, 'Add the custom repository in the composer.json. If a lock file is present it will be deleted and an update will be run instead of install.'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Enables installation of require-dev packages (enabled by default, only present for BC).'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables installation of require-dev packages.'),
                new InputOption('no-custom-installers', null, InputOption::VALUE_NONE, 'DEPRECATED: Use no-plugins instead.'),
                new InputOption('no-scripts', null, InputOption::VALUE_NONE, 'Whether to prevent execution of all defined scripts in the root package.'),
                new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
                new InputOption('no-secure-http', null, InputOption::VALUE_NONE, 'Disable the secure-http config option temporarily while installing the root package. Use at your own risk. Using this flag is a bad idea.'),
                new InputOption('keep-vcs', null, InputOption::VALUE_NONE, 'Whether to prevent deleting the vcs folder.'),
                new InputOption('remove-vcs', null, InputOption::VALUE_NONE, 'Whether to force deletion of the vcs folder without prompting.'),
                new InputOption('no-install', null, InputOption::VALUE_NONE, 'Whether to skip installation of the package dependencies.'),
                new InputOption('no-audit', null, InputOption::VALUE_NONE, 'Whether to skip auditing of the installed package dependencies (can also be set via the COMPOSER_NO_AUDIT=1 env var).'),
                new InputOption('audit-format', null, InputOption::VALUE_REQUIRED, 'Audit output format. Must be "table", "plain", "json" or "summary".', Auditor::FORMAT_SUMMARY, Auditor::FORMATS),
                new InputOption('ignore-platform-req', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore a specific platform requirement (php & ext- packages).'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore all platform requirements (php & ext- packages).'),
                new InputOption('ask', null, InputOption::VALUE_NONE, 'Whether to ask for project directory.'),
            ])
            ->setHelp(
                <<<EOT
The <info>create-project</info> command creates a new project from a given
package into a new directory. If executed without params and in a directory
with a composer.json file it installs the packages for the current project.

You can use this command to bootstrap new projects or setup a clean
version-controlled installation for developers of your project.

<info>php composer.phar create-project vendor/project target-directory [version]</info>

You can also specify the version with the package name using = or : as separator.

<info>php composer.phar create-project vendor/project:version target-directory</info>

To install unstable packages, either specify the version you want, or use the
--stability=dev (where dev can be one of RC, beta, alpha or dev).

To setup a developer workable version you should create the project using the source
controlled code by appending the <info>'--prefer-source'</info> flag.

To install a package from another repository than the default one you
can pass the <info>'--repository=https://myrepository.org'</info> flag.

Read more at https://getcomposer.org/doc/03-cli.md#create-project
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = Factory::createConfig();
        $io = $this->getIO();

        [$preferSource, $preferDist] = $this->getPreferredInstallOptions($config, $input, true);

        if ($input->getOption('dev')) {
            $io->writeError('<warning>You are using the deprecated option "dev". Dev packages are installed by default now.</warning>');
        }
        if ($input->getOption('no-custom-installers')) {
            $io->writeError('<warning>You are using the deprecated option "no-custom-installers". Use "no-plugins" instead.</warning>');
            $input->setOption('no-plugins', true);
        }

        if ($input->isInteractive() && $input->getOption('ask')) {
            $package = $input->getArgument('package');
            if (null === $package) {
                throw new \RuntimeException('Not enough arguments (missing: "package").');
            }
            $parts = explode("/", strtolower($package), 2);
            $input->setArgument('directory', $io->ask('New project directory [<comment>'.array_pop($parts).'</comment>]: '));
        }

        return $this->installProject(
            $io,
            $config,
            $input,
            $input->getArgument('package'),
            $input->getArgument('directory'),
            $input->getArgument('version'),
            $input->getOption('stability'),
            $preferSource,
            $preferDist,
            !$input->getOption('no-dev'),
            \count($input->getOption('repository')) > 0 ? $input->getOption('repository') : $input->getOption('repository-url'),
            $input->getOption('no-plugins'),
            $input->getOption('no-scripts'),
            $input->getOption('no-progress'),
            $input->getOption('no-install'),
            $this->getPlatformRequirementFilter($input),
            !$input->getOption('no-secure-http'),
            $input->getOption('add-repository')
        );
    }

    /**
     * @param string|array<string>|null $repositories
     *
     * @throws \Exception
     */
    public function installProject(IOInterface $io, Config $config, InputInterface $input, ?string $packageName = null, ?string $directory = null, ?string $packageVersion = null, ?string $stability = 'stable', bool $preferSource = false, bool $preferDist = false, bool $installDevPackages = false, $repositories = null, bool $disablePlugins = false, bool $disableScripts = false, bool $noProgress = false, bool $noInstall = false, ?PlatformRequirementFilterInterface $platformRequirementFilter = null, bool $secureHttp = true, bool $addRepository = false): int
    {
        $oldCwd = Platform::getCwd();

        if ($repositories !== null && !is_array($repositories)) {
            $repositories = (array) $repositories;
        }

        $platformRequirementFilter = $platformRequirementFilter ?? PlatformRequirementFilterFactory::ignoreNothing();

        // we need to manually load the configuration to pass the auth credentials to the io interface!
        $io->loadConfiguration($config);

        $this->suggestedPackagesReporter = new SuggestedPackagesReporter($io);

        if ($packageName !== null) {
            $installedFromVcs = $this->installRootPackage($input, $io, $config, $packageName, $platformRequirementFilter, $directory, $packageVersion, $stability, $preferSource, $preferDist, $installDevPackages, $repositories, $disablePlugins, $disableScripts, $noProgress, $secureHttp);
        } else {
            $installedFromVcs = false;
        }

        if ($repositories !== null && $addRepository && is_file('composer.lock')) {
            unlink('composer.lock');
        }

        $composer = $this->createComposerInstance($input, $io, null, $disablePlugins, $disableScripts);

        // add the repository to the composer.json and use it for the install run later
        if ($repositories !== null && $addRepository) {
            foreach ($repositories as $index => $repo) {
                $repoConfig = RepositoryFactory::configFromString($io, $composer->getConfig(), $repo, true);
                $composerJsonRepositoriesConfig = $composer->getConfig()->getRepositories();
                $name = RepositoryFactory::generateRepositoryName($index, $repoConfig, $composerJsonRepositoriesConfig);
                $configSource = new JsonConfigSource(new JsonFile('composer.json'));

                if (
                    (isset($repoConfig['packagist']) && $repoConfig === ['packagist' => false])
                    || (isset($repoConfig['packagist.org']) && $repoConfig === ['packagist.org' => false])
                ) {
                    $configSource->addRepository('packagist.org', false);
                } else {
                    $configSource->addRepository($name, $repoConfig, false);
                }

                $composer = $this->createComposerInstance($input, $io, null, $disablePlugins);
            }
        }

        $process = $composer->getLoop()->getProcessExecutor();
        $fs = new Filesystem($process);

        // dispatch event
        $composer->getEventDispatcher()->dispatchScript(ScriptEvents::POST_ROOT_PACKAGE_INSTALL, $installDevPackages);

        // use the new config including the newly installed project
        $config = $composer->getConfig();
        [$preferSource, $preferDist] = $this->getPreferredInstallOptions($config, $input);

        // install dependencies of the created project
        if ($noInstall === false) {
            $composer->getInstallationManager()->setOutputProgress(!$noProgress);

            $installer = Installer::create($io, $composer);
            $installer->setPreferSource($preferSource)
                ->setPreferDist($preferDist)
                ->setDevMode($installDevPackages)
                ->setPlatformRequirementFilter($platformRequirementFilter)
                ->setSuggestedPackagesReporter($this->suggestedPackagesReporter)
                ->setOptimizeAutoloader($config->get('optimize-autoloader'))
                ->setClassMapAuthoritative($config->get('classmap-authoritative'))
                ->setApcuAutoloader($config->get('apcu-autoloader'))
                ->setAudit(!$input->getOption('no-audit'))
                ->setAuditFormat($this->getAuditFormat($input));

            if (!$composer->getLocker()->isLocked()) {
                $installer->setUpdate(true);
            }

            if ($disablePlugins) {
                $installer->disablePlugins();
            }

            try {
                $status = $installer->run();
                if (0 !== $status) {
                    return $status;
                }
            } catch (PluginBlockedException $e) {
                $io->writeError('<error>Hint: To allow running the config command recommended below before dependencies are installed, run create-project with --no-install.</error>');
                $io->writeError('<error>You can then cd into '.getcwd().', configure allow-plugins, and finally run a composer install to complete the process.</error>');
                throw $e;
            }
        }

        $hasVcs = $installedFromVcs;
        if (
            !$input->getOption('keep-vcs')
            && $installedFromVcs
            && (
                $input->getOption('remove-vcs')
                || !$io->isInteractive()
                || $io->askConfirmation('<info>Do you want to remove the existing VCS (.git, .svn..) history?</info> [<comment>Y,n</comment>]? ')
            )
        ) {
            $finder = new Finder();
            $finder->depth(0)->directories()->in(Platform::getCwd())->ignoreVCS(false)->ignoreDotFiles(false);
            foreach (['.svn', '_svn', 'CVS', '_darcs', '.arch-params', '.monotone', '.bzr', '.git', '.hg', '.fslckout', '_FOSSIL_'] as $vcsName) {
                $finder->name($vcsName);
            }

            try {
                $dirs = iterator_to_array($finder);
                unset($finder);
                foreach ($dirs as $dir) {
                    if (!$fs->removeDirectory((string) $dir)) {
                        throw new \RuntimeException('Could not remove '.$dir);
                    }
                }
            } catch (\Exception $e) {
                $io->writeError('<error>An error occurred while removing the VCS metadata: '.$e->getMessage().'</error>');
            }

            $hasVcs = false;
        }

        // rewriting self.version dependencies with explicit version numbers if the package's vcs metadata is gone
        if (!$hasVcs) {
            $package = $composer->getPackage();
            $configSource = new JsonConfigSource(new JsonFile('composer.json'));
            foreach (BasePackage::$supportedLinkTypes as $type => $meta) {
                foreach ($package->{'get'.$meta['method']}() as $link) {
                    if ($link->getPrettyConstraint() === 'self.version') {
                        $configSource->addLink($type, $link->getTarget(), $package->getPrettyVersion());
                    }
                }
            }
        }

        // dispatch event
        $composer->getEventDispatcher()->dispatchScript(ScriptEvents::POST_CREATE_PROJECT_CMD, $installDevPackages);

        chdir($oldCwd);
        $vendorComposerDir = $config->get('vendor-dir').'/composer';
        if (is_dir($vendorComposerDir) && $fs->isDirEmpty($vendorComposerDir)) {
            Silencer::call('rmdir', $vendorComposerDir);
            $vendorDir = $config->get('vendor-dir');
            if (is_dir($vendorDir) && $fs->isDirEmpty($vendorDir)) {
                Silencer::call('rmdir', $vendorDir);
            }
        }

        return 0;
    }

    /**
     * @param array<string>|null $repositories
     *
     * @throws \Exception
     */
    protected function installRootPackage(InputInterface $input, IOInterface $io, Config $config, string $packageName, PlatformRequirementFilterInterface $platformRequirementFilter, ?string $directory = null, ?string $packageVersion = null, ?string $stability = 'stable', bool $preferSource = false, bool $preferDist = false, bool $installDevPackages = false, ?array $repositories = null, bool $disablePlugins = false, bool $disableScripts = false, bool $noProgress = false, bool $secureHttp = true): bool
    {
        if (!$secureHttp) {
            $config->merge(['config' => ['secure-http' => false]], Config::SOURCE_COMMAND);
        }

        $parser = new VersionParser();
        $requirements = $parser->parseNameVersionPairs([$packageName]);
        $name = strtolower($requirements[0]['name']);
        if (!$packageVersion && isset($requirements[0]['version'])) {
            $packageVersion = $requirements[0]['version'];
        }

        // if no directory was specified, use the 2nd part of the package name
        if (null === $directory) {
            $parts = explode("/", $name, 2);
            $directory = Platform::getCwd() . DIRECTORY_SEPARATOR . array_pop($parts);
        }

        $process = new ProcessExecutor($io);
        $fs = new Filesystem($process);
        if (!$fs->isAbsolutePath($directory)) {
            $directory = Platform::getCwd() . DIRECTORY_SEPARATOR . $directory;
        }

        $io->writeError('<info>Creating a "' . $packageName . '" project at "' . $fs->findShortestPath(Platform::getCwd(), $directory, true) . '"</info>');

        if (file_exists($directory)) {
            if (!is_dir($directory)) {
                throw new \InvalidArgumentException('Cannot create project directory at "'.$directory.'", it exists as a file.');
            }
            if (!$fs->isDirEmpty($directory)) {
                throw new \InvalidArgumentException('Project directory "'.$directory.'" is not empty.');
            }
        }

        if (null === $stability) {
            if (null === $packageVersion) {
                $stability = 'stable';
            } elseif (Preg::isMatchStrictGroups('{^[^,\s]*?@('.implode('|', array_keys(BasePackage::STABILITIES)).')$}i', $packageVersion, $match)) {
                $stability = $match[1];
            } else {
                $stability = VersionParser::parseStability($packageVersion);
            }
        }

        $stability = VersionParser::normalizeStability($stability);

        if (!isset(BasePackage::STABILITIES[$stability])) {
            throw new \InvalidArgumentException('Invalid stability provided ('.$stability.'), must be one of: '.implode(', ', array_keys(BasePackage::STABILITIES)));
        }

        $composer = $this->createComposerInstance($input, $io, $config->all(), $disablePlugins, $disableScripts);
        $config = $composer->getConfig();
        $rm = $composer->getRepositoryManager();

        $repositorySet = new RepositorySet($stability);
        if (null === $repositories) {
            $repositorySet->addRepository(new CompositeRepository(RepositoryFactory::defaultRepos($io, $config, $rm)));
        } else {
            foreach ($repositories as $repo) {
                $repoConfig = RepositoryFactory::configFromString($io, $config, $repo, true);
                if (
                    (isset($repoConfig['packagist']) && $repoConfig === ['packagist' => false])
                    || (isset($repoConfig['packagist.org']) && $repoConfig === ['packagist.org' => false])
                ) {
                    continue;
                }
                $repositorySet->addRepository(RepositoryFactory::createRepo($io, $config, $repoConfig, $rm));
            }
        }

        $platformOverrides = $config->get('platform');
        $platformRepo = new PlatformRepository([], $platformOverrides);

        // find the latest version if there are multiple
        $versionSelector = new VersionSelector($repositorySet, $platformRepo);
        $package = $versionSelector->findBestCandidate($name, $packageVersion, $stability, $platformRequirementFilter, 0, $io);

        if (!$package) {
            $errorMessage = "Could not find package $name with " . ($packageVersion ? "version $packageVersion" : "stability $stability");
            if (!($platformRequirementFilter instanceof IgnoreAllPlatformRequirementFilter) && $versionSelector->findBestCandidate($name, $packageVersion, $stability, PlatformRequirementFilterFactory::ignoreAll())) {
                throw new \InvalidArgumentException($errorMessage .' in a version installable using your PHP version, PHP extensions and Composer version.');
            }

            throw new \InvalidArgumentException($errorMessage .'.');
        }

        // handler Ctrl+C aborts gracefully
        @mkdir($directory, 0777, true);
        if (false !== ($realDir = realpath($directory))) {
            $signalHandler = SignalHandler::create([SignalHandler::SIGINT, SignalHandler::SIGTERM, SignalHandler::SIGHUP], function (string $signal, SignalHandler $handler) use ($realDir) {
                $this->getIO()->writeError('Received '.$signal.', aborting', true, IOInterface::DEBUG);
                $fs = new Filesystem();
                $fs->removeDirectory($realDir);
                $handler->exitWithLastSignal();
            });
        }

        // avoid displaying 9999999-dev as version if default-branch was selected
        if ($package instanceof AliasPackage && $package->getPrettyVersion() === VersionParser::DEFAULT_BRANCH_ALIAS) {
            $package = $package->getAliasOf();
        }

        $io->writeError('<info>Installing ' . $package->getName() . ' (' . $package->getFullPrettyVersion(false) . ')</info>');

        if ($disablePlugins) {
            $io->writeError('<info>Plugins have been disabled.</info>');
        }

        if ($package instanceof AliasPackage) {
            $package = $package->getAliasOf();
        }

        $dm = $composer->getDownloadManager();
        $dm->setPreferSource($preferSource)
            ->setPreferDist($preferDist);

        $projectInstaller = new ProjectInstaller($directory, $dm, $fs);
        $im = $composer->getInstallationManager();
        $im->setOutputProgress(!$noProgress);
        $im->addInstaller($projectInstaller);
        $im->execute(new InstalledArrayRepository(), [new InstallOperation($package)]);
        $im->notifyInstalls($io);

        // collect suggestions
        $this->suggestedPackagesReporter->addSuggestionsFromPackage($package);

        $installedFromVcs = 'source' === $package->getInstallationSource();

        $io->writeError('<info>Created project in ' . $directory . '</info>');
        chdir($directory);

        // ensure that the env var being set does not interfere with create-project
        // as it is probably not meant to be used here, so we do not use it if a composer.json can be found
        // in the project
        if (file_exists($directory.'/composer.json') && Platform::getEnv('COMPOSER') !== false) {
            Platform::clearEnv('COMPOSER');
        }

        Platform::putEnv('COMPOSER_ROOT_VERSION', $package->getPrettyVersion());

        // once the root project is fully initialized, we do not need to wipe everything on user abort anymore even if it happens during deps install
        if (isset($signalHandler)) {
            $signalHandler->unregister();
        }

        return $installedFromVcs;
    }
}
