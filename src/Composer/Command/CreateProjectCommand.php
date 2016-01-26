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

use Composer\Config;
use Composer\Factory;
use Composer\Installer;
use Composer\Installer\ProjectInstaller;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\Package\Version\VersionSelector;
use Composer\Repository\ComposerRepository;
use Composer\Repository\CompositeRepository;
use Composer\Repository\FilesystemRepository;
use Composer\Repository\InstalledFilesystemRepository;
use Composer\Script\ScriptEvents;
use Composer\Util\Silencer;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Composer\Json\JsonFile;
use Composer\Config\JsonConfigSource;
use Composer\Util\Filesystem;
use Composer\Package\Version\VersionParser;

/**
 * Install a package as new project into new directory.
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Tobias Munk <schmunk@usrbin.de>
 * @author Nils Adermann <naderman@naderman.de>
 */
class CreateProjectCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('create-project')
            ->setDescription('Create new project from a package into given directory.')
            ->setDefinition(array(
                new InputArgument('package', InputArgument::OPTIONAL, 'Package name to be installed'),
                new InputArgument('directory', InputArgument::OPTIONAL, 'Directory where the files should be created'),
                new InputArgument('version', InputArgument::OPTIONAL, 'Version, will default to latest'),
                new InputOption('stability', 's', InputOption::VALUE_REQUIRED, 'Minimum-stability allowed (unless a version is specified).'),
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
                new InputOption('prefer-dist', null, InputOption::VALUE_NONE, 'Forces installation from package dist even for dev versions.'),
                new InputOption('repository-url', null, InputOption::VALUE_REQUIRED, 'Pick a different repository url to look for the package.'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Enables installation of require-dev packages (enabled by default, only present for BC).'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables installation of require-dev packages.'),
                new InputOption('no-plugins', null, InputOption::VALUE_NONE, 'Whether to disable plugins.'),
                new InputOption('no-custom-installers', null, InputOption::VALUE_NONE, 'DEPRECATED: Use no-plugins instead.'),
                new InputOption('no-scripts', null, InputOption::VALUE_NONE, 'Whether to prevent execution of all defined scripts in the root package.'),
                new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
                new InputOption('keep-vcs', null, InputOption::VALUE_NONE, 'Whether to prevent deletion vcs folder.'),
                new InputOption('no-install', null, InputOption::VALUE_NONE, 'Whether to skip installation of the package dependencies.'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore platform requirements (php & ext- packages).'),
            ))
            ->setHelp(<<<EOT
The <info>create-project</info> command creates a new project from a given
package into a new directory. If executed without params and in a directory
with a composer.json file it installs the packages for the current project.

You can use this command to bootstrap new projects or setup a clean
version-controlled installation for developers of your project.

<info>php composer.phar create-project vendor/project target-directory [version]</info>

You can also specify the version with the package name using = or : as separator.

To install unstable packages, either specify the version you want, or use the
--stability=dev (where dev can be one of RC, beta, alpha or dev).

To setup a developer workable version you should create the project using the source
controlled code by appending the <info>'--prefer-source'</info> flag.

To install a package from another repository than the default one you
can pass the <info>'--repository-url=https://myrepository.org'</info> flag.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = Factory::createConfig();
        $io = $this->getIO();

        $this->updatePreferredOptions($config, $input, $preferSource, $preferDist, true);

        if ($input->getOption('no-custom-installers')) {
            $io->writeError('<warning>You are using the deprecated option "no-custom-installers". Use "no-plugins" instead.</warning>');
            $input->setOption('no-plugins', true);
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
            $input->getOption('repository-url'),
            $input->getOption('no-plugins'),
            $input->getOption('no-scripts'),
            $input->getOption('keep-vcs'),
            $input->getOption('no-progress'),
            $input->getOption('no-install'),
            $input->getOption('ignore-platform-reqs')
        );
    }

    public function installProject(IOInterface $io, Config $config, InputInterface $input, $packageName, $directory = null, $packageVersion = null, $stability = 'stable', $preferSource = false, $preferDist = false, $installDevPackages = false, $repositoryUrl = null, $disablePlugins = false, $noScripts = false, $keepVcs = false, $noProgress = false, $noInstall = false, $ignorePlatformReqs = false)
    {
        $oldCwd = getcwd();

        // we need to manually load the configuration to pass the auth credentials to the io interface!
        $io->loadConfiguration($config);

        if ($packageName !== null) {
            $installedFromVcs = $this->installRootPackage($io, $config, $packageName, $directory, $packageVersion, $stability, $preferSource, $preferDist, $installDevPackages, $repositoryUrl, $disablePlugins, $noScripts, $keepVcs, $noProgress);
        } else {
            $installedFromVcs = false;
        }

        $composer = Factory::create($io, null, $disablePlugins);
        $composer->getDownloadManager()->setOutputProgress(!$noProgress);

        $fs = new Filesystem();

        if ($noScripts === false) {
            // dispatch event
            $composer->getEventDispatcher()->dispatchScript(ScriptEvents::POST_ROOT_PACKAGE_INSTALL, $installDevPackages);
        }

        $rootPackageConfig = $composer->getConfig();
        $this->updatePreferredOptions($rootPackageConfig, $input, $preferSource, $preferDist);

        // install dependencies of the created project
        if ($noInstall === false) {
            $installer = Installer::create($io, $composer);
            $installer->setPreferSource($preferSource)
                ->setPreferDist($preferDist)
                ->setDevMode($installDevPackages)
                ->setRunScripts(!$noScripts)
                ->setIgnorePlatformRequirements($ignorePlatformReqs);

            if ($disablePlugins) {
                $installer->disablePlugins();
            }

            $status = $installer->run();
            if (0 !== $status) {
                return $status;
            }
        }

        $hasVcs = $installedFromVcs;
        if (!$keepVcs && $installedFromVcs
            && (
                !$io->isInteractive()
                || $io->askConfirmation('<info>Do you want to remove the existing VCS (.git, .svn..) history?</info> [<comment>Y,n</comment>]? ', true)
            )
        ) {
            $finder = new Finder();
            $finder->depth(0)->directories()->in(getcwd())->ignoreVCS(false)->ignoreDotFiles(false);
            foreach (array('.svn', '_svn', 'CVS', '_darcs', '.arch-params', '.monotone', '.bzr', '.git', '.hg') as $vcsName) {
                $finder->name($vcsName);
            }

            try {
                $dirs = iterator_to_array($finder);
                unset($finder);
                foreach ($dirs as $dir) {
                    if (!$fs->removeDirectory($dir)) {
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

        if ($noScripts === false) {
            // dispatch event
            $composer->getEventDispatcher()->dispatchScript(ScriptEvents::POST_CREATE_PROJECT_CMD, $installDevPackages);
        }

        chdir($oldCwd);
        $vendorComposerDir = $composer->getConfig()->get('vendor-dir').'/composer';
        if (is_dir($vendorComposerDir) && $fs->isDirEmpty($vendorComposerDir)) {
            Silencer::call('rmdir', $vendorComposerDir);
            $vendorDir = $composer->getConfig()->get('vendor-dir');
            if (is_dir($vendorDir) && $fs->isDirEmpty($vendorDir)) {
                Silencer::call('rmdir', $vendorDir);
            }
        }

        return 0;
    }

    protected function installRootPackage(IOInterface $io, Config $config, $packageName, $directory = null, $packageVersion = null, $stability = 'stable', $preferSource = false, $preferDist = false, $installDevPackages = false, $repositoryUrl = null, $disablePlugins = false, $noScripts = false, $keepVcs = false, $noProgress = false)
    {
        if (null === $repositoryUrl) {
            $sourceRepo = new CompositeRepository(Factory::createDefaultRepositories($io, $config));
        } elseif ("json" === pathinfo($repositoryUrl, PATHINFO_EXTENSION) && file_exists($repositoryUrl)) {
            $json = new JsonFile($repositoryUrl, Factory::createRemoteFilesystem($io, $config));
            $data = $json->read();
            if (!empty($data['packages']) || !empty($data['includes']) || !empty($data['provider-includes'])) {
                $sourceRepo = new ComposerRepository(array('url' => 'file://' . strtr(realpath($repositoryUrl), '\\', '/')), $io, $config);
            } else {
                $sourceRepo = new FilesystemRepository($json);
            }
        } elseif (0 === strpos($repositoryUrl, 'http')) {
            $sourceRepo = new ComposerRepository(array('url' => $repositoryUrl), $io, $config);
        } else {
            throw new \InvalidArgumentException("Invalid repository url given. Has to be a .json file or an http url.");
        }

        $parser = new VersionParser();
        $requirements = $parser->parseNameVersionPairs(array($packageName));
        $name = strtolower($requirements[0]['name']);
        if (!$packageVersion && isset($requirements[0]['version'])) {
            $packageVersion = $requirements[0]['version'];
        }

        if (null === $stability) {
            if (preg_match('{^[^,\s]*?@('.implode('|', array_keys(BasePackage::$stabilities)).')$}i', $packageVersion, $match)) {
                $stability = $match[1];
            } else {
                $stability = VersionParser::parseStability($packageVersion);
            }
        }

        $stability = VersionParser::normalizeStability($stability);

        if (!isset(BasePackage::$stabilities[$stability])) {
            throw new \InvalidArgumentException('Invalid stability provided ('.$stability.'), must be one of: '.implode(', ', array_keys(BasePackage::$stabilities)));
        }

        $pool = new Pool($stability);
        $pool->addRepository($sourceRepo);

        // using those 3 constants to build a version without the 'extra' bit that can contain garbage
        $phpVersion = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION;

        // find the latest version if there are multiple
        $versionSelector = new VersionSelector($pool);
        $package = $versionSelector->findBestCandidate($name, $packageVersion, $phpVersion, $stability);

        if (!$package) {
            throw new \InvalidArgumentException("Could not find package $name" . ($packageVersion ? " with version $packageVersion." : " with stability $stability."));
        }

        if (null === $directory) {
            $parts = explode("/", $name, 2);
            $directory = getcwd() . DIRECTORY_SEPARATOR . array_pop($parts);
        }

        // handler Ctrl+C for unix-like systems
        if (function_exists('pcntl_signal')) {
            declare(ticks=100);
            pcntl_signal(SIGINT, function () use ($directory) {
                $fs = new Filesystem();
                $fs->removeDirectory($directory);
                exit(130);
            });
        }

        $io->writeError('<info>Installing ' . $package->getName() . ' (' . $package->getFullPrettyVersion(false) . ')</info>');

        if ($disablePlugins) {
            $io->writeError('<info>Plugins have been disabled.</info>');
        }

        if (0 === strpos($package->getPrettyVersion(), 'dev-') && in_array($package->getSourceType(), array('git', 'hg'))) {
            $package->setSourceReference(substr($package->getPrettyVersion(), 4));
        }

        $dm = $this->createDownloadManager($io, $config);
        $dm->setPreferSource($preferSource)
            ->setPreferDist($preferDist)
            ->setOutputProgress(!$noProgress);

        $projectInstaller = new ProjectInstaller($directory, $dm);
        $im = $this->createInstallationManager();
        $im->addInstaller($projectInstaller);
        $im->install(new InstalledFilesystemRepository(new JsonFile('php://memory')), new InstallOperation($package));
        $im->notifyInstalls($io);

        $installedFromVcs = 'source' === $package->getInstallationSource();

        $io->writeError('<info>Created project in ' . $directory . '</info>');
        chdir($directory);

        $_SERVER['COMPOSER_ROOT_VERSION'] = $package->getPrettyVersion();
        putenv('COMPOSER_ROOT_VERSION='.$_SERVER['COMPOSER_ROOT_VERSION']);

        return $installedFromVcs;
    }

    protected function createDownloadManager(IOInterface $io, Config $config)
    {
        $factory = new Factory();

        return $factory->createDownloadManager($io, $config);
    }

    protected function createInstallationManager()
    {
        return new InstallationManager();
    }

    /**
     * Updated preferSource or preferDist based on the preferredInstall config option
     * @param Config         $config
     * @param InputInterface $input
     * @param bool           $preferSource
     * @param bool           $preferDist
     */
    protected function updatePreferredOptions(Config $config, InputInterface $input, &$preferSource, &$preferDist, $keepVcsRequiresPreferSource = false)
    {
        $preferSource = false;
        $preferDist = false;

        switch ($config->get('preferred-install')) {
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

        if ($input->getOption('prefer-source') || $input->getOption('prefer-dist') || ($keepVcsRequiresPreferSource && $input->getOption('keep-vcs'))) {
            $preferSource = $input->getOption('prefer-source') || ($keepVcsRequiresPreferSource && $input->getOption('keep-vcs'));
            $preferDist = $input->getOption('prefer-dist');
        }
    }
}
