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
use Composer\IO\IOInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Symfony\Component\Process\Process;

/**
 * PHP Extension Installer.
 *
 * @author Igor Wiedler <igor@wiedler.ch>
 */
class ExtensionInstaller extends LibraryInstaller
{
    protected $extDir;
    protected $extOptions;

    /**
     * {@inheritDoc}
     */
    public function __construct(IOInterface $io, Composer $composer, Filesystem $filesystem = null)
    {
        parent::__construct($io, $composer, 'extension', $filesystem);

        $this->extDir = rtrim($composer->getConfig()->get('ext-dir'), '/');
        $this->extOptions = $composer->getConfig()->get('ext-options');
    }

    /**
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::install($repo, $package);

        $this->compileExtension($package);
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        parent::update($repo, $initial, $target);

        $this->cleanExtension($target);
        $this->compileExtension($target);
    }

    protected function initializeExtDir()
    {
        $this->filesystem->ensureDirectoryExists($this->extDir);
        $this->extDir = realpath($this->extDir);
    }

    private function compileExtension(PackageInterface $package)
    {
        $this->initializeExtDir();

        $flags = isset($this->extOptions[$package->getName()]) ? $this->extOptions[$package->getName()] : '';
        $path = $this->getInstallPath($package);

        if (defined('HHVM_VERSION')) {
            $command = sprintf('hphpize && cmake %s . && make', escapeshellarg($flags));
        } else {
            $command = sprintf('phpize && ./configure %s && make && make install', escapeshellarg($flags));
        }

        $process = new Process($command, $path);
        $io = $this->io;
        $status = $process->run(function ($stream, $data) use ($io) {
            $io->write($data, false);
        });

        if (0 !== $status) {
            throw new \RuntimeException("Could not compile extension ".$package->getName());
        }

        $extensions = [];

        if (defined('HHVM_VERSION')) {
            foreach (new \FilesystemIterator($this->getInstallPath($package)) as $file) {
                if ($file->getExtension() == 'so') {
                    $extensions[] = $file->getBasename();
                    copy($file, $this->extDir.'/'.$file->getBasename());
                }
            }
        } else {
            $modulesDir = $this->getInstallPath($package).'/modules';
            foreach (new \FilesystemIterator($modulesDir) as $file) {
                if ($file->getExtension() == 'so') {
                    $extensions[] = $file->getBasename();
                }
                copy($file, $this->extDir.'/'.$file->getBasename());
            }
        }

        $content = implode('', array_map(function ($extension) {
            return 'extension='.$extension."\n";
        }, $extensions));

        file_put_contents($this->extDir.'/extensions.ini', $content);
    }

    private function cleanExtension(PackageInterface $package)
    {
        $this->initializeExtDir();

        $path = $this->getInstallPath($package);
        $command = 'make clean';

        $process = new Process($command, $path);
        $process->run();
    }
}
