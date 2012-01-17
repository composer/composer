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

namespace Composer\Console;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Finder\Finder;
use Composer\Command;
use Composer\Composer;
use Composer\Installer;
use Composer\Downloader;
use Composer\Repository;
use Composer\Package;
use Composer\Json\JsonFile;
use Composer\IO\IOInterface;
use Composer\IO\ConsoleIO;

/**
 * The console application that handles the commands
 *
 * @author Ryan Weaver <ryan@knplabs.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
class Application extends BaseApplication
{
    protected $composer;
    protected $io;

    public function __construct()
    {
        parent::__construct('Composer', Composer::VERSION);
    }

    /**
     * {@inheritDoc}
     */
    public function run(InputInterface $input = null, OutputInterface $output = null)
    {
        if (null === $output) {
            $styles['highlight'] = new OutputFormatterStyle('red');
            $styles['warning'] = new OutputFormatterStyle('black', 'yellow');
            $formatter = new OutputFormatter(null, $styles);
            $output = new ConsoleOutput(ConsoleOutput::VERBOSITY_NORMAL, null, $formatter);
        }

        return parent::run($input, $output);
    }

    /**
     * {@inheritDoc}
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $this->registerCommands();
        $this->io = new ConsoleIO($input, $output, $this->getHelperSet());

        return parent::doRun($input, $output);
    }

    /**
     * @return Composer
     */
    public function getComposer()
    {
        if (null === $this->composer) {
            $this->composer = self::bootstrapComposer($this->io);
        }

        return $this->composer;
    }

    /**
     * @return IOInterface
     */
    public function getIO()
    {
        return $this->io;
    }

    /**
     * Bootstraps a Composer instance
     *
     * @return Composer
     */
    public static function bootstrapComposer(IOInterface $io, $composerFile = null)
    {
        // load Composer configuration
        if (null === $composerFile) {
            $composerFile = getenv('COMPOSER') ?: 'composer.json';
        }

        $file = new JsonFile($composerFile);
        if (!$file->exists()) {
            if ($composerFile === 'composer.json') {
                $this->io->writeln('Composer could not find a composer.json file in '.getcwd());
            } else {
                $this->io->writeln('Composer could not find the config file: '.$composerFile);
            }
            $this->io->writeln('To initialize a project, please create a composer.json file as described on the http://packagist.org/ "Getting Started" section');
            exit(1);
        }

        // Configuration defaults
        $composerConfig = array(
            'vendor-dir' => 'vendor',
        );

        $packageConfig = $file->read();

        if (isset($packageConfig['config']) && is_array($packageConfig['config'])) {
            $packageConfig['config'] = array_merge($composerConfig, $packageConfig['config']);
        } else {
            $packageConfig['config'] = $composerConfig;
        }

        $vendorDir = getenv('COMPOSER_VENDOR_DIR') ?: $packageConfig['config']['vendor-dir'];
        if (!isset($packageConfig['config']['bin-dir'])) {
            $packageConfig['config']['bin-dir'] = $vendorDir.'/bin';
        }
        $binDir = getenv('COMPOSER_BIN_DIR') ?: $packageConfig['config']['bin-dir'];

        // initialize repository manager
        $rm = new Repository\RepositoryManager($io);
        $rm->setLocalRepository(new Repository\FilesystemRepository(new JsonFile($vendorDir.'/.composer/installed.json')));
        $rm->setRepositoryClass('composer', 'Composer\Repository\ComposerRepository');
        $rm->setRepositoryClass('vcs', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('pear', 'Composer\Repository\PearRepository');
        $rm->setRepositoryClass('package', 'Composer\Repository\PackageRepository');

        // initialize download manager
        $dm = new Downloader\DownloadManager();
        $dm->setDownloader('git',  new Downloader\GitDownloader());
        $dm->setDownloader('svn',  new Downloader\SvnDownloader());
        $dm->setDownloader('hg', new Downloader\HgDownloader());
        $dm->setDownloader('pear', new Downloader\PearDownloader($io));
        $dm->setDownloader('zip',  new Downloader\ZipDownloader($io));

        // initialize installation manager
        $im = new Installer\InstallationManager($vendorDir);
        $im->addInstaller(new Installer\LibraryInstaller($vendorDir, $binDir, $dm, $rm->getLocalRepository(), $io, null));
        $im->addInstaller(new Installer\InstallerInstaller($vendorDir, $binDir, $dm, $rm->getLocalRepository(), $io, $im));

        // load package
        $loader  = new Package\Loader\RootPackageLoader($rm);
        $package = $loader->load($packageConfig);

        // load default repository unless it's explicitly disabled
        if (!isset($packageConfig['repositories']['packagist']) || $packageConfig['repositories']['packagist'] !== false) {
            $rm->addRepository(new Repository\ComposerRepository(array('url' => 'http://packagist.org')));
        }

        // init locker
        $lockFile = substr($composerFile, -5) === '.json' ? substr($composerFile, 0, -4).'lock' : $composerFile . '.lock';
        $locker = new Package\Locker(new JsonFile($lockFile), $rm, md5_file($composerFile));

        // initialize composer
        $composer = new Composer();
        $composer->setPackage($package);
        $composer->setLocker($locker);
        $composer->setRepositoryManager($rm);
        $composer->setDownloadManager($dm);
        $composer->setInstallationManager($im);

        return $composer;
    }

    /**
     * Initializes all the composer commands
     */
    protected function registerCommands()
    {
        $this->add(new Command\AboutCommand());
        $this->add(new Command\DependsCommand());
        $this->add(new Command\InstallCommand());
        $this->add(new Command\UpdateCommand());
        $this->add(new Command\DebugPackagesCommand());
        $this->add(new Command\SearchCommand());
        $this->add(new Command\ValidateCommand());
        $this->add(new Command\ShowCommand());

        if ('phar:' === substr(__FILE__, 0, 5)) {
            $this->add(new Command\SelfUpdateCommand());
        }
    }
}
