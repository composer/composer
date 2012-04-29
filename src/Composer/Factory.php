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
use Composer\Util\RemoteFilesystem;

/**
 * Creates an configured instance of composer.
 *
 * @author Ryan Weaver <ryan@knplabs.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Igor Wiedler <igor@wiedler.ch>
 */
class Factory
{
    public static function createConfig()
    {
        // load main Composer configuration
        if (!$home = getenv('COMPOSER_HOME')) {
            if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
                $home = getenv('APPDATA') . '/Composer';
            } else {
                $home = getenv('HOME') . '/.composer';
            }
        }

        // Protect directory against web access
        if (!file_exists($home . '/.htaccess')) {
            if (!is_dir($home)) {
                @mkdir($home, 0777, true);
            }
            @file_put_contents($home . '/.htaccess', 'Deny from all');
        }

        $config = new Config();

        $file = new JsonFile($home.'/config.json');
        if ($file->exists()) {
            $config->merge($file->read());
        }

        // add home dir to the config
        $config->merge(array('config' => array('home' => $home)));

        return $config;
    }

    /**
     * Creates a Composer instance
     *
     * @param IOInterface $io IO instance
     * @param mixed $localConfig either a configuration array or a filename to read from, if null it will read from the default filename
     * @return Composer
     */
    public function createComposer(IOInterface $io, $localConfig = null)
    {
        // load Composer configuration
        if (null === $localConfig) {
            $localConfig = getenv('COMPOSER') ?: 'composer.json';
        }

        if (is_string($localConfig)) {
            $composerFile = $localConfig;
            $file = new JsonFile($localConfig, new RemoteFilesystem($io));

            if (!$file->exists()) {
                if ($localConfig === 'composer.json') {
                    $message = 'Composer could not find a composer.json file in '.getcwd();
                } else {
                    $message = 'Composer could not find the config file: '.$localConfig;
                }
                $instructions = 'To initialize a project, please create a composer.json file as described in the http://getcomposer.org/ "Getting Started" section';
                throw new \InvalidArgumentException($message.PHP_EOL.$instructions);
            }

            $file->validateSchema(JsonFile::LAX_SCHEMA);
            $localConfig = $file->read();
        }

        // Configuration defaults
        $config = static::createConfig();
        $config->merge($localConfig);

        $vendorDir = $config->get('vendor-dir');
        $binDir = $config->get('bin-dir');

        // setup process timeout
        ProcessExecutor::setTimeout((int) $config->get('process-timeout'));

        // initialize repository manager
        $rm = $this->createRepositoryManager($io, $config);

        // load default repository unless it's explicitly disabled
        $localConfig = $this->addPackagistRepository($localConfig);

        // load local repository
        $this->addLocalRepository($rm, $vendorDir);

        // load package
        $loader  = new Package\Loader\RootPackageLoader($rm);
        $package = $loader->load($localConfig);

        // initialize download manager
        $dm = $this->createDownloadManager($io);

        // initialize installation manager
        $im = $this->createInstallationManager($rm, $dm, $vendorDir, $binDir, $io);

        // purge packages if they have been deleted on the filesystem
        $this->purgePackages($rm, $im);

        // initialize composer
        $composer = new Composer();
        $composer->setConfig($config);
        $composer->setPackage($package);
        $composer->setRepositoryManager($rm);
        $composer->setDownloadManager($dm);
        $composer->setInstallationManager($im);

        // init locker if possible
        if (isset($composerFile)) {
            $lockFile = "json" === pathinfo($composerFile, PATHINFO_EXTENSION)
                ? substr($composerFile, 0, -4).'lock'
                : $composerFile . '.lock';
            $locker = new Package\Locker(new JsonFile($lockFile, new RemoteFilesystem($io)), $rm, md5_file($composerFile));
            $composer->setLocker($locker);
        }

        return $composer;
    }

    protected function createRepositoryManager(IOInterface $io, Config $config)
    {
        $rm = new RepositoryManager($io, $config);
        $rm->setRepositoryClass('composer', 'Composer\Repository\ComposerRepository');
        $rm->setRepositoryClass('vcs', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('package', 'Composer\Repository\PackageRepository');
        $rm->setRepositoryClass('pear', 'Composer\Repository\PearRepository');
        $rm->setRepositoryClass('git', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('svn', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('hg', 'Composer\Repository\VcsRepository');

        return $rm;
    }

    protected function addLocalRepository(RepositoryManager $rm, $vendorDir)
    {
        // TODO BC feature, remove after May 30th
        if (file_exists($vendorDir.'/.composer/installed.json')) {
            if (!is_dir($vendorDir.'/composer')) { mkdir($vendorDir.'/composer/', 0777, true); }
            rename($vendorDir.'/.composer/installed.json', $vendorDir.'/composer/installed.json');
        }
        if (file_exists($vendorDir.'/.composer/installed_dev.json')) {
            if (!is_dir($vendorDir.'/composer')) { mkdir($vendorDir.'/composer/', 0777, true); }
            rename($vendorDir.'/.composer/installed_dev.json', $vendorDir.'/composer/installed_dev.json');
        }
        if (file_exists($vendorDir.'/installed.json')) {
            if (!is_dir($vendorDir.'/composer')) { mkdir($vendorDir.'/composer/', 0777, true); }
            rename($vendorDir.'/installed.json', $vendorDir.'/composer/installed.json');
        }
        if (file_exists($vendorDir.'/installed_dev.json')) {
            if (!is_dir($vendorDir.'/composer')) { mkdir($vendorDir.'/composer/', 0777, true); }
            rename($vendorDir.'/installed_dev.json', $vendorDir.'/composer/installed_dev.json');
        }
        $rm->setLocalRepository(new Repository\InstalledFilesystemRepository(new JsonFile($vendorDir.'/composer/installed.json')));
        $rm->setLocalDevRepository(new Repository\InstalledFilesystemRepository(new JsonFile($vendorDir.'/composer/installed_dev.json')));
    }

    protected function addPackagistRepository(array $localConfig)
    {
        $loadPackagist = true;
        $packagistConfig = array(
            'type' => 'composer',
            'url' => 'http://packagist.org'
        );

        if (isset($localConfig['repositories'])) {
            foreach ($localConfig['repositories'] as $key => $repo) {
                if (isset($repo['packagist'])) {
                    if (true === $repo['packagist']) {
                        $localConfig['repositories'][$key] = $packagistConfig;
                    }

                    $loadPackagist = false;
                    break;
                }
            }
        } else {
            $localConfig['repositories'] = array();
        }

        if ($loadPackagist) {
            $localConfig['repositories'][] = $packagistConfig;
        }

        return $localConfig;
    }

    public function createDownloadManager(IOInterface $io)
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
        $im->addInstaller(new Installer\LibraryInstaller($vendorDir, $binDir, $dm, $io, null));
        $im->addInstaller(new Installer\InstallerInstaller($vendorDir, $binDir, $dm, $io, $im, $rm->getLocalRepositories()));
        $im->addInstaller(new Installer\MetapackageInstaller($io));

        return $im;
    }

    protected function purgePackages(Repository\RepositoryManager $rm, Installer\InstallationManager $im)
    {
        foreach ($rm->getLocalRepositories() as $repo) {
            foreach ($repo->getPackages() as $package) {
                if (!$im->isPackageInstalled($repo, $package)) {
                    $repo->removePackage($package);
                }
            }
        }
    }

    /**
     * @param IOInterface $io IO instance
     * @param mixed $config either a configuration array or a filename to read from, if null it will read from the default filename
     * @return Composer
     */
    static public function create(IOInterface $io, $config = null)
    {
        $factory = new static();

        return $factory->createComposer($io, $config);
    }
}
