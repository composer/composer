<?php


namespace Composer\Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\Silencer;
use Composer\Util\Platform;
use InvalidArgumentException;

class ExtensionInstaller implements InstallerInterface
{
    protected $composer;
    protected $downloadManager;
    protected $io;
    protected $pickle = 'pickle';
    protected $process;
    protected $cacheDir;

    public function __construct(IOInterface $io, Composer $composer)
    {
        $this->composer = $composer;
        $this->downloadManager = $composer->getDownloadManager();
        $this->io = $io;

        $this->cacheDir = rtrim($composer->getConfig()->get('cache-file-dir'), '/');
        if (($pickle = getenv('COMPOSER_PECL_PATH'))) {
            $this->pickle = escapeshellcmd($pickle);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supports($packageType)
    {
        return $packageType === 'extension';
    }

    /**
     * {@inheritdoc}
     */
    public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        // TODO: implement proper way to check extension status
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $package;
        // TODO: Implement install() method.
    }

    /**
     * {@inheritdoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        // TODO: Implement update() method.
    }

    /**
     * {@inheritdoc}
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        // TODO: Implement uninstall() method.
    }

    /**
     * {@inheritdoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
        // TODO: Implement getInstallPath() method.
    }
}
