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

use Composer\DependencyResolver\Request;
use Composer\Util\Filesystem;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Factory;
use Composer\Installer;
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
use Composer\Util\Silencer;

/**
 * @author Jérémy Romey <jeremy@free-agent.fr>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class RequireCommand extends InitCommand
{
    private $newlyCreated;
    private $firstRequire;
    private $json;
    private $file;
    private $composerBackup;
    /** @var string file name */
    private $lock;
    /** @var ?string contents before modification if the lock file exists */
    private $lockBackup;

    protected function configure()
    {
        $this
            ->setName('require')
            ->setDescription('Adds required packages to your composer.json and installs them.')
            ->setDefinition(array(
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Optional package name can also include a version constraint, e.g. foo/bar or foo/bar:1.0.0 or foo/bar=1.0.0 or "foo/bar 1.0.0"'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Add requirement to require-dev.'),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Outputs the operations but will not execute anything (implicitly enables --verbose).'),
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
                new InputOption('prefer-dist', null, InputOption::VALUE_NONE, 'Forces installation from package dist (default behavior).'),
                new InputOption('prefer-install', null, InputOption::VALUE_REQUIRED, 'Forces installation from package dist|source|auto (auto chooses source for dev versions, dist for the rest).'),
                new InputOption('fixed', null, InputOption::VALUE_NONE, 'Write fixed version to the composer.json.'),
                new InputOption('no-suggest', null, InputOption::VALUE_NONE, 'DEPRECATED: This flag does not exist anymore.'),
                new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
                new InputOption('no-update', null, InputOption::VALUE_NONE, 'Disables the automatic update of the dependencies (implies --no-install).'),
                new InputOption('no-install', null, InputOption::VALUE_NONE, 'Skip the install step after updating the composer.lock file.'),
                new InputOption('no-scripts', null, InputOption::VALUE_NONE, 'Skips the execution of all scripts defined in composer.json file.'),
                new InputOption('update-no-dev', null, InputOption::VALUE_NONE, 'Run the dependency update with the --no-dev option.'),
                new InputOption('update-with-dependencies', 'w', InputOption::VALUE_NONE, 'Allows inherited dependencies to be updated, except those that are root requirements.'),
                new InputOption('update-with-all-dependencies', 'W', InputOption::VALUE_NONE, 'Allows all inherited dependencies to be updated, including those that are root requirements.'),
                new InputOption('with-dependencies', null, InputOption::VALUE_NONE, 'Alias for --update-with-dependencies'),
                new InputOption('with-all-dependencies', null, InputOption::VALUE_NONE, 'Alias for --update-with-all-dependencies'),
                new InputOption('ignore-platform-req', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore a specific platform requirement (php & ext- packages).'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore all platform requirements (php & ext- packages).'),
                new InputOption('prefer-stable', null, InputOption::VALUE_NONE, 'Prefer stable versions of dependencies.'),
                new InputOption('prefer-lowest', null, InputOption::VALUE_NONE, 'Prefer lowest versions of dependencies.'),
                new InputOption('sort-packages', null, InputOption::VALUE_NONE, 'Sorts packages when adding/updating a new dependency'),
                new InputOption('optimize-autoloader', 'o', InputOption::VALUE_NONE, 'Optimize autoloader during autoloader dump'),
                new InputOption('classmap-authoritative', 'a', InputOption::VALUE_NONE, 'Autoload classes from the classmap only. Implicitly enables `--optimize-autoloader`.'),
                new InputOption('apcu-autoloader', null, InputOption::VALUE_NONE, 'Use APCu to cache found/not-found classes.'),
                new InputOption('apcu-autoloader-prefix', null, InputOption::VALUE_REQUIRED, 'Use a custom prefix for the APCu autoloader cache. Implicitly enables --apcu-autoloader'),
            ))
            ->setHelp(
                <<<EOT
The require command adds required packages to your composer.json and installs them.

If you do not specify a package, composer will prompt you to search for a package, and given results, provide a list of
matches to require.

If you do not specify a version constraint, composer will choose a suitable one based on the available package versions.

If you do not want to install the new dependencies immediately you can call it with --no-update

Read more at https://getcomposer.org/doc/03-cli.md#require
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, array($this, 'revertComposerFile'));
            pcntl_signal(SIGTERM, array($this, 'revertComposerFile'));
            pcntl_signal(SIGHUP, array($this, 'revertComposerFile'));
        }

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

        // check for writability by writing to the file as is_writable can not be trusted on network-mounts
        // see https://github.com/composer/composer/issues/8231 and https://bugs.php.net/bug.php?id=68926
        if (!is_writable($this->file) && !Silencer::call('file_put_contents', $this->file, $this->composerBackup)) {
            $io->writeError('<error>'.$this->file.' is not writable.</error>');

            return 1;
        }

        if ($input->getOption('fixed') === true) {
            $config = $this->json->read();

            $packageType = empty($config['type']) ? 'library' : $config['type'];

            /**
             * @see https://github.com/composer/composer/pull/8313#issuecomment-532637955
             */
            if ($packageType !== 'project') {
                $io->writeError('<error>"--fixed" option is allowed for "project" package types only to prevent possible misuses.</error>');

                if (empty($config['type'])) {
                    $io->writeError('<error>If your package is not library, you should explicitly specify "type" parameter in composer.json.</error>');
                }

                return 1;
            }
        }

        $composer = $this->getComposer(true, $input->getOption('no-plugins'));
        $repos = $composer->getRepositoryManager()->getRepositories();

        $platformOverrides = $composer->getConfig()->get('platform') ?: array();
        // initialize $this->repos as it is used by the parent InitCommand
        $this->repos = new CompositeRepository(array_merge(
            array($platformRepo = new PlatformRepository(array(), $platformOverrides)),
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
                !$input->getOption('no-update'),
                $input->getOption('fixed')
            );
        } catch (\Exception $e) {
            if ($this->newlyCreated) {
                throw new \RuntimeException('No composer.json present in the current directory ('.$this->file.'), this may be the cause of the following exception.', 0, $e);
            }

            throw $e;
        }

        $requireKey = $input->getOption('dev') ? 'require-dev' : 'require';
        $removeKey = $input->getOption('dev') ? 'require' : 'require-dev';
        $requirements = $this->formatRequirements($requirements);

        // validate requirements format
        $versionParser = new VersionParser();
        foreach ($requirements as $package => $constraint) {
            if (strtolower($package) === $composer->getPackage()->getName()) {
                $io->writeError(sprintf('<error>Root package \'%s\' cannot require itself in its composer.json</error>', $package));

                return 1;
            }
            $versionParser->parseConstraints($constraint);
        }

        $inconsistentRequireKeys = $this->getInconsistentRequireKeys($requirements, $requireKey);
        if (count($inconsistentRequireKeys) > 0) {
            foreach ($inconsistentRequireKeys as $package) {
                $io->warning(sprintf(
                    '%s is currently present in the %s key and you ran the command %s the --dev flag, which would move it to the %s key.',
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

                    list($requireKey, $removeKey) = array($removeKey, $requireKey);
                }
            }
        }

        $sortPackages = $input->getOption('sort-packages') || $composer->getConfig()->get('sort-packages');

        $this->firstRequire = $this->newlyCreated;
        if (!$this->firstRequire) {
            $composerDefinition = $this->json->read();
            if (empty($composerDefinition['require']) && empty($composerDefinition['require-dev'])) {
                $this->firstRequire = true;
            }
        }

        if (!$input->getOption('dry-run') && !$this->updateFileCleanly($this->json, $requirements, $requireKey, $removeKey, $sortPackages)) {
            $composerDefinition = $this->json->read();
            foreach ($requirements as $package => $version) {
                $composerDefinition[$requireKey][$package] = $version;
                unset($composerDefinition[$removeKey][$package]);
                if (isset($composerDefinition[$removeKey]) && count($composerDefinition[$removeKey]) === 0) {
                    unset($composerDefinition[$removeKey]);
                }
            }
            $this->json->write($composerDefinition);
        }

        $io->writeError('<info>'.$this->file.' has been '.($this->newlyCreated ? 'created' : 'updated').'</info>');

        if ($input->getOption('no-update')) {
            return 0;
        }

        try {
            return $this->doUpdate($input, $output, $io, $requirements, $requireKey, $removeKey);
        } catch (\Exception $e) {
            $this->revertComposerFile(false);
            throw $e;
        }
    }

    private function getInconsistentRequireKeys(array $newRequirements, $requireKey)
    {
        $requireKeys = $this->getPackagesByRequireKey();
        $inconsistentRequirements = array();
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

    private function getPackagesByRequireKey()
    {
        $composerDefinition = $this->json->read();
        $require = array();
        $requireDev = array();

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

    private function doUpdate(InputInterface $input, OutputInterface $output, IOInterface $io, array $requirements, $requireKey, $removeKey)
    {
        // Update packages
        $this->resetComposer();
        $composer = $this->getComposer(true, $input->getOption('no-plugins'));

        if ($input->getOption('dry-run')) {
            $rootPackage = $composer->getPackage();
            $links = array(
                'require' => $rootPackage->getRequires(),
                'require-dev' => $rootPackage->getDevRequires(),
            );
            $loader = new ArrayLoader();
            $newLinks = $loader->parseLinks($rootPackage->getName(), $rootPackage->getPrettyVersion(), BasePackage::$supportedLinkTypes[$requireKey]['description'], $requirements);
            $links[$requireKey] = array_merge($links[$requireKey], $newLinks);
            foreach ($requirements as $package => $constraint) {
                unset($links[$removeKey][$package]);
            }
            $rootPackage->setRequires($links['require']);
            $rootPackage->setDevRequires($links['require-dev']);
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

        $ignorePlatformReqs = $input->getOption('ignore-platform-reqs') ?: ($input->getOption('ignore-platform-req') ?: false);
        list($preferSource, $preferDist) = $this->getPreferredInstallOptions($composer->getConfig(), $input);

        $install
            ->setDryRun($input->getOption('dry-run'))
            ->setVerbose($input->getOption('verbose'))
            ->setPreferSource($preferSource)
            ->setPreferDist($preferDist)
            ->setDevMode($updateDevMode)
            ->setRunScripts(!$input->getOption('no-scripts'))
            ->setOptimizeAutoloader($optimize)
            ->setClassMapAuthoritative($authoritative)
            ->setApcuAutoloader($apcu, $apcuPrefix)
            ->setUpdate(true)
            ->setInstall(!$input->getOption('no-install'))
            ->setUpdateAllowTransitiveDependencies($updateAllowTransitiveDependencies)
            ->setIgnorePlatformRequirements($ignorePlatformReqs)
            ->setPreferStable($input->getOption('prefer-stable'))
            ->setPreferLowest($input->getOption('prefer-lowest'))
        ;

        // if no lock is present, or the file is brand new, we do not do a
        // partial update as this is not supported by the Installer
        if (!$this->firstRequire && $composer->getLocker()->isLocked()) {
            $install->setUpdateAllowList(array_keys($requirements));
        }

        $status = $install->run();
        if ($status !== 0) {
            $this->revertComposerFile(false);
        }

        return $status;
    }

    private function updateFileCleanly($json, array $new, $requireKey, $removeKey, $sortPackages)
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

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        return;
    }

    public function revertComposerFile($hardExit = true)
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

        if ($hardExit) {
            exit(1);
        }
    }
}
