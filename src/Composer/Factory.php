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

/**
 * Creates an configured instance of composer.
 *
 * @author Ryan Weaver <ryan@knplabs.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Factory
{
    /**
     * Creates a Composer instance
     *
     * @return Composer
     */
    public static function create($composerFile = null)
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

        $baseDir = rtrim(dirname($composerFile), '/').'/';

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

        $vendorDir = $baseDir.$vendorDir;
        $binDir = $baseDir.$binDir;

        // initialize repository manager
        $rm = new Repository\RepositoryManager();
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
        $dm->setDownloader('pear', new Downloader\PearDownloader());
        $dm->setDownloader('zip',  new Downloader\ZipDownloader());

        // initialize installation manager
        $im = new Installer\InstallationManager($vendorDir);
        $im->addInstaller(new Installer\LibraryInstaller($vendorDir, $binDir, $dm, $rm->getLocalRepository(), null));
        $im->addInstaller(new Installer\InstallerInstaller($vendorDir, $binDir, $dm, $rm->getLocalRepository(), $im));

        // load package
        $loader  = new Package\Loader\RootPackageLoader($rm);
        $package = $loader->load($packageConfig);

        // load default repository unless it's explicitly disabled
        if (!isset($packageConfig['repositories']['packagist']) || $packageConfig['repositories']['packagist'] !== false) {
            $rm->addRepository(new Repository\ComposerRepository(array('url' => 'http://packagist.org')));
        }

        // init locker
        $lockFile = substr($composerFile, -5) === '.json' ? substr($composerFile, 0, -4).'lock' : $composerFile . '.lock';
        $locker = new Package\Locker(new JsonFile($lockFile), $rm);

        // initialize composer
        $composer = new Composer();
        $composer->setPackage($package);
        $composer->setLocker($locker);
        $composer->setRepositoryManager($rm);
        $composer->setDownloadManager($dm);
        $composer->setInstallationManager($im);

        return $composer;
    }
}
