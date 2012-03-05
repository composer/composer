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

namespace Composer;

use Composer\Json\JsonFile;
use Composer\IO\IOInterface;
use Composer\Repository\RepositoryManager;
use Composer\Util\ProcessExecutor;

/**
 * Creates an configured instance of composer.
 *
 * @author Ryan Weaver <ryan@knplabs.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Igor Wiedler <igor@wiedler.ch>
 */
class Factory
{
    /**
     * Creates a Composer instance
     *
     * @return Composer
     */
    public function createComposer(IOInterface $io, $composerFile = null)
    {
        // load Composer configuration
        if (null === $composerFile) {
            $composerFile = getenv('COMPOSER') ?: 'composer.json';
        }

        $file = new JsonFile($composerFile);
        if (!$file->exists()) {
            if ($composerFile === 'composer.json') {
                $message = 'Composer could not find a composer.json file in '.getcwd();
            } else {
                $message = 'Composer could not find the config file: '.$composerFile;
            }
            $instructions = 'To initialize a project, please create a composer.json file as described on the http://packagist.org/ "Getting Started" section';
            throw new \InvalidArgumentException($message.PHP_EOL.$instructions);
        }

        // Configuration defaults
        $composerConfig = array(
            'vendor-dir' => 'vendor',
            'process-timeout' => 300,
        );

        $packageConfig = $file->read(true);

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

        // setup process timeout
        $processTimeout = getenv('COMPOSER_PROCESS_TIMEOUT') ?: $packageConfig['config']['process-timeout'];
        ProcessExecutor::setTimeout((int) $processTimeout);

        // initialize repository manager
        $rm = $this->createRepositoryManager($io);

        // load default repository unless it's explicitly disabled
        $loadPackagist = true;
        if (isset($packageConfig['repositories'])) {
            foreach ($packageConfig['repositories'] as $repo) {
                if (isset($repo['packagist']) && $repo['packagist'] === false) {
                    $loadPackagist = false;
                }
            }
        }
        if ($loadPackagist) {
            $this->addPackagistRepository($rm);
        }

        // load local repository
        $this->addLocalRepository($rm, $vendorDir);

        // load package
        $loader  = new Package\Loader\RootPackageLoader($rm);
        $package = $loader->load($packageConfig);

        // initialize download manager
        $dm = $this->createDownloadManager($io);

        // initialize installation manager
        $im = $this->createInstallationManager($rm, $dm, $vendorDir, $binDir, $io);

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

    protected function createRepositoryManager(IOInterface $io)
    {
        $rm = new RepositoryManager($io);
        $rm->setRepositoryClass('composer', 'Composer\Repository\ComposerRepository');
        $rm->setRepositoryClass('vcs', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('pear', 'Composer\Repository\PearRepository');
        $rm->setRepositoryClass('package', 'Composer\Repository\PackageRepository');

        return $rm;
    }

    protected function addLocalRepository(RepositoryManager $rm, $vendorDir)
    {
        $rm->setLocalRepository(new Repository\InstalledFilesystemRepository(new JsonFile($vendorDir.'/.composer/installed.json')));
    }

    protected function addPackagistRepository(RepositoryManager $rm)
    {
        $rm->addRepository(new Repository\ComposerRepository(array('url' => 'http://packagist.org')));
    }

    protected function createDownloadManager(IOInterface $io)
    {
        $dm = new Downloader\DownloadManager();
        $dm->setDownloader('git', new Downloader\GitDownloader($io));
        $dm->setDownloader('svn', new Downloader\SvnDownloader($io));
        $dm->setDownloader('hg', new Downloader\HgDownloader($io));
        $dm->setDownloader('pear', new Downloader\PearDownloader($io));
        $dm->setDownloader('zip', new Downloader\ZipDownloader($io));
        $dm->setDownloader('tar', new Downloader\TarDownloader($io));
        $dm->setDownloader('phar', new Downloader\PharDownloader($io));
        $dm->setDownloader('file', new Downloader\FileDownloader($io));

        return $dm;
    }

    protected function createInstallationManager(Repository\RepositoryManager $rm, Downloader\DownloadManager $dm, $vendorDir, $binDir, IOInterface $io)
    {
        $im = new Installer\InstallationManager($vendorDir);
        $im->addInstaller(new Installer\LibraryInstaller($vendorDir, $binDir, $dm, $rm->getLocalRepository(), $io, null));
        $im->addInstaller(new Installer\InstallerInstaller($vendorDir, $binDir, $dm, $rm->getLocalRepository(), $io, $im));

        return $im;
    }

    static public function create(IOInterface $io, $composerFile = null)
    {
        $factory = new static();

        return $factory->createComposer($io, $composerFile);
    }
}
