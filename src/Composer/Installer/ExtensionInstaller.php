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
    protected $downloadManager;
    protected $io;
    protected $pickle = 'pickle';
    protected $process;
    /**
     * Initializes library installer.
     *
     * @param IOInterface $io
     * @param Composer    $composer
     * @param string      $type
     * @param Filesystem  $filesystem
     */
    public function __construct(IOInterface $io, Composer $composer)
    {
        $this->composer = $composer;
        $this->downloadManager = $composer->getDownloadManager();
        $this->io = $io;

        if (($pickle = getenv('COMPOSER_PICKLE_PATH'))) {
            $this->pickle = escapeshellcmd($pickle);
        }
        $this->process = new ProcessExecutor($this->io);

        $res = $this->process->execute($this->pickle . ' --version', $output);
        if ($res != 0) {
            Throw new \ErrorException("Error while calling pickle command: $res");
        }
        $verpos = strpos($output, 'version');
        $version = trim(substr($output, $verpos + 7));
        if (!version_compare('0.4.0', $version, '>=')) {
            Throw new \ErrorException("pickle 0.4.0 required.");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return $packageType === 'extension';
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

        $distUrl = $package->getDistURL();
        if (strtolower(substr($distUrl, -3)) == "zip") {
            $extractDir = $this->uncompress($package);
            $json = $this->findComposerJson($extractDir);   
        } else {
            $pkgDir = $this->createTempDir();
            $this->downloadManager->download($package, $pkgDir);
        }

        /* Add interactions */
        $cmd = sprintf('%s install -q -n --save-logs=%s %s', $this->pickle, ProcessExecutor::escape($pkgDir . 'logs'), ProcessExecutor::escape($pkgDir));
        $this->process->execute($cmd);
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
        $extractDir = $this->createTempDir();
        $distUrl = $package->getDistUrl();
        $zip = new \ZipArchive;
        if ($zip->open($distUrl) === TRUE) {
            $zip->extractTo($extractDir);
            $zip->close();
        } else {
            Throw new \ErrorException("cannot get the temporary directory (ZipArchive error: " . $zip->status . ")");
        }
        return $extractDir;
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
