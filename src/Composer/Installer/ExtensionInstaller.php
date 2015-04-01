<?php

/*
 * This file is part of Composer.
 *
 * (c) Pierre Joye <pierre.php@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;

/**
 * Extension installation manager.
 *
 * @author Pierre Joye <pierre.php@gmail.com>
 */
class ExtensionInstaller implements InstallerInterface
{
    protected $composer;
    protected $vendorDir;
    protected $binDir;
    protected $downloadManager;
    protected $io;
    protected $type;
    protected $filesystem;
    protected $pickle = 'pickle';

    /**
     * Initializes library installer.
     *
     * @param IOInterface $io
     * @param Composer    $composer
     * @param string      $type
     * @param Filesystem  $filesystem
     */
    public function __construct(IOInterface $io, Composer $composer, $type = 'extension', Filesystem $filesystem = null)
    {
        $this->composer = $composer;
        $this->downloadManager = $composer->getDownloadManager();
        $this->io = $io;
        $this->type = $type;

        $this->filesystem = $filesystem ?: new Filesystem();
        $this->vendorDir = rtrim($composer->getConfig()->get('vendor-dir'), '/');
        $this->binDir = rtrim($composer->getConfig()->get('bin-dir'), '/');
        if (($pickle = getenv('COMPOSER_PICKLE_PATH'))) {
            $this->pickle = $pickle;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return $packageType === $this->type || null === $this->type;
    }

    /**
     * {@inheritDoc}
     */
    public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        return false;
        //return $repo->hasPackage($package) && is_readable($this->getInstallPath($package));
    }

    /**
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {       
        $this->io->write("Pickle: fetching " . $package->getName());

        $dist_url = $package->getDistURL();
        if (strtolower(substr($dist_url, -3)) == "zip") {
            $extract_dir = $this->uncompress($package);
            $json = $this->findComposerJson($extract_dir);
        } else {
            $pkg_dir = $this->createTempDir();
            $this->downloadManager->download($package, $pkg_dir);
        }
        $process = new ProcessExecutor($this->io);
        /* Add interactions */
        $cmd = sprintf('%s install -q -n --save-logs=%s %s', $this->pickle, ProcessExecutor::escape($pkg_dir . 'logs'), ProcessExecutor::escape($pkg_dir));
        $process->execute($cmd);
        return;
    }

    protected function createTempDir()
    {
        $tmpdir = sys_get_temp_dir();
        if (!$tmpdir) {
            Throw new \ErrorException("cannot get the temporary directory");
        }
        $lockfile = tempnam($tmpdir, 'pickle');
        return $lockfile . '_dir';
    }
    
    protected function uncompress(PackageInterface $package)
    {
        $extract_dir = $this->createTempDir();
        $dist_url = $package->getDistUrl();
        $zip = new \ZipArchive;
        if ($zip->open($dist_url) === TRUE) {
            $zip->extractTo($extract_dir);
            $zip->close();
        } else {
            Throw new \ErrorException("cannot get the temporary directory (ZipArchive error: " . $zip->status . ")");
        }
        return $extract_dir;
    }

    protected function findComposerJson($basedir)
    {
        if (!file_exists($basedir . "/composer.json")) {
            $json = glob($basedir . "/*/composer.json");
            if (isset($json[0])) {
                $json = $json[0];
            }
        } else {
            $json = $basedir . "/composer.json";
        }
        return $json;
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        /* there is no update method so far, just install it over */
        $this->install($repo, $package);
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        throw new \InvalidArgumentException('no uninstall supported: '.$package);
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
/*
        $targetDir = $package->getTargetDir();

        return $this->getPackageBasePath($package) . ($targetDir ? '/'.$targetDir : '');
*/
    }

}
