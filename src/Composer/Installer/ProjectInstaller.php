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

namespace Composer\Installer;

use Composer\Composer;
use Composer\Package\PackageInterface;
use Composer\Downloader\DownloadManager;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Project Installer is used to install a single package into a directory as
 * root project.
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 */
class ProjectInstaller implements InstallerInterface
{
    /** @var Composer */
    private $composer;

    /** @var string */
    private $installPath;

    /** @var DownloadManager */
    private $downloadManager;

    /** @var Filesystem */
    private $filesystem;

    public function __construct($installPath, DownloadManager $dm, Composer $composer)
    {
        $this->composer = $composer;
        $this->installPath = rtrim(strtr($installPath, '\\', '/'), '/').'/';
        $this->downloadManager = $dm;
        $this->filesystem = new Filesystem;
    }

    /**
     * Decides if the installer supports the given type
     *
     * @param  string $packageType
     * @return bool
     */
    public function supports($packageType)
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $installPath = $this->installPath;
        if (file_exists($installPath) && !$this->filesystem->isDirEmpty($installPath)) {
            throw new \InvalidArgumentException("Project directory $installPath is not empty.");
        }
        if (!is_dir($installPath)) {
            mkdir($installPath, 0777, true);
        }
        $this->downloadManager->download($package, $installPath);
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        if ($initial->getName() !== $target->getName()) {
            throw new \InvalidArgumentException(\sprintf(
                "Project directory does not contain the same package. Expected %s, but found %s.",
                $target->getName(),
                $initial->getName()
            ));
        }

        $tmpDir = $this->composer->getConfig()->get('vendor-dir').'/composer/'.substr(md5(uniqid('', true)), 0, 8);

        $this->downloadManager->download($target, $tmpDir);

        $finder = Finder::create()
            ->ignoreVCS(false)
            ->ignoreDotFiles(false)
            ->depth(0)
            ->in($tmpDir);
        foreach ($finder as $f) {
            $this->filesystem->rename($f->getPathname(), $this->installPath . $f->getRelativePathname());
        }

        $this->filesystem->remove($tmpDir);
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        throw new \InvalidArgumentException("not supported");
    }

    /**
     * Returns the installation path of a package
     *
     * @param  PackageInterface $package
     * @return string           path
     */
    public function getInstallPath(PackageInterface $package)
    {
        return $this->installPath;
    }
}
