<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Downloader;

use Composer\Config;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionGuesser;
use Composer\Package\Version\VersionParser;
use Composer\Util\ProcessExecutor;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use React\Promise\PromiseInterface;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
abstract class VcsDownloader implements DownloaderInterface, ChangeReportInterface, VcsCapableDownloaderInterface
{
    /** @var IOInterface */
    protected $io;
    /** @var Config */
    protected $config;
    /** @var ProcessExecutor */
    protected $process;
    /** @var Filesystem */
    protected $filesystem;
    /** @var array<string, true> */
    protected $hasCleanedChanges = array();

    public function __construct(IOInterface $io, Config $config, ProcessExecutor $process = null, Filesystem $fs = null)
    {
        $this->io = $io;
        $this->config = $config;
        $this->process = $process ?? new ProcessExecutor($io);
        $this->filesystem = $fs ?? new Filesystem($this->process);
    }

    /**
     * @inheritDoc
     */
    public function getInstallationSource(): string
    {
        return 'source';
    }

    /**
     * @inheritDoc
     */
    public function download(PackageInterface $package, string $path, PackageInterface $prevPackage = null): PromiseInterface
    {
        if (!$package->getSourceReference()) {
            throw new \InvalidArgumentException('Package '.$package->getPrettyName().' is missing reference information');
        }

        $urls = $this->prepareUrls($package->getSourceUrls());

        while ($url = array_shift($urls)) {
            try {
                return $this->doDownload($package, $path, $url, $prevPackage);
            } catch (\Exception $e) {
                // rethrow phpunit exceptions to avoid hard to debug bug failures
                if ($e instanceof \PHPUnit\Framework\Exception) {
                    throw $e;
                }
                if ($this->io->isDebug()) {
                    $this->io->writeError('Failed: ['.$e::class.'] '.$e->getMessage());
                } elseif (count($urls)) {
                    $this->io->writeError('    Failed, trying the next URL');
                }
                if (!count($urls)) {
                    throw $e;
                }
            }
        }

        return \React\Promise\resolve(null);
    }

    /**
     * @inheritDoc
     */
    public function prepare(string $type, PackageInterface $package, string $path, PackageInterface $prevPackage = null): PromiseInterface
    {
        if ($type === 'update') {
            $this->cleanChanges($prevPackage, $path, true);
            $this->hasCleanedChanges[$prevPackage->getUniqueName()] = true;
        } elseif ($type === 'install') {
            $this->filesystem->emptyDirectory($path);
        } elseif ($type === 'uninstall') {
            $this->cleanChanges($package, $path, false);
        }

        return \React\Promise\resolve(null);
    }

    /**
     * @inheritDoc
     */
    public function cleanup(string $type, PackageInterface $package, string $path, PackageInterface $prevPackage = null): PromiseInterface
    {
        if ($type === 'update' && isset($this->hasCleanedChanges[$prevPackage->getUniqueName()])) {
            $this->reapplyChanges($path);
            unset($this->hasCleanedChanges[$prevPackage->getUniqueName()]);
        }

        return \React\Promise\resolve(null);
    }

    /**
     * @inheritDoc
     */
    public function install(PackageInterface $package, string $path): PromiseInterface
    {
        if (!$package->getSourceReference()) {
            throw new \InvalidArgumentException('Package '.$package->getPrettyName().' is missing reference information');
        }

        $this->io->writeError("  - " . InstallOperation::format($package).': ', false);

        $urls = $this->prepareUrls($package->getSourceUrls());
        while ($url = array_shift($urls)) {
            try {
                $this->doInstall($package, $path, $url);
                break;
            } catch (\Exception $e) {
                // rethrow phpunit exceptions to avoid hard to debug bug failures
                if ($e instanceof \PHPUnit\Framework\Exception) {
                    throw $e;
                }
                if ($this->io->isDebug()) {
                    $this->io->writeError('Failed: ['.$e::class.'] '.$e->getMessage());
                } elseif (count($urls)) {
                    $this->io->writeError('    Failed, trying the next URL');
                }
                if (!count($urls)) {
                    throw $e;
                }
            }
        }

        return \React\Promise\resolve(null);
    }

    /**
     * @inheritDoc
     */
    public function update(PackageInterface $initial, PackageInterface $target, string $path): PromiseInterface
    {
        if (!$target->getSourceReference()) {
            throw new \InvalidArgumentException('Package '.$target->getPrettyName().' is missing reference information');
        }

        $this->io->writeError("  - " . UpdateOperation::format($initial, $target).': ', false);

        $urls = $this->prepareUrls($target->getSourceUrls());

        $exception = null;
        while ($url = array_shift($urls)) {
            try {
                $this->doUpdate($initial, $target, $path, $url);

                $exception = null;
                break;
            } catch (\Exception $exception) {
                // rethrow phpunit exceptions to avoid hard to debug bug failures
                if ($exception instanceof \PHPUnit\Framework\Exception) {
                    throw $exception;
                }
                if ($this->io->isDebug()) {
                    $this->io->writeError('Failed: ['.$exception::class.'] '.$exception->getMessage());
                } elseif (count($urls)) {
                    $this->io->writeError('    Failed, trying the next URL');
                }
            }
        }

        // print the commit logs if in verbose mode and VCS metadata is present
        // because in case of missing metadata code would trigger another exception
        if (!$exception && $this->io->isVerbose() && $this->hasMetadataRepository($path)) {
            $message = 'Pulling in changes:';
            $logs = $this->getCommitLogs($initial->getSourceReference(), $target->getSourceReference(), $path);

            if ('' === trim($logs)) {
                $message = 'Rolling back changes:';
                $logs = $this->getCommitLogs($target->getSourceReference(), $initial->getSourceReference(), $path);
            }

            if ('' !== trim($logs)) {
                $logs = implode("\n", array_map(function ($line): string {
                    return '      ' . $line;
                }, explode("\n", $logs)));

                // escape angle brackets for proper output in the console
                $logs = str_replace('<', '\<', $logs);

                $this->io->writeError('    '.$message);
                $this->io->writeError($logs);
            }
        }

        if (!$urls && $exception) {
            throw $exception;
        }

        return \React\Promise\resolve(null);
    }

    /**
     * @inheritDoc
     */
    public function remove(PackageInterface $package, string $path): PromiseInterface
    {
        $this->io->writeError("  - " . UninstallOperation::format($package));

        $promise = $this->filesystem->removeDirectoryAsync($path);

        return $promise->then(function (bool $result) use ($path) {
            if (!$result) {
                throw new \RuntimeException('Could not completely delete '.$path.', aborting.');
            }
        });
    }

    /**
     * @inheritDoc
     */
    public function getVcsReference(PackageInterface $package, string $path): ?string
    {
        $parser = new VersionParser;
        $guesser = new VersionGuesser($this->config, $this->process, $parser);
        $dumper = new ArrayDumper;

        $packageConfig = $dumper->dump($package);
        if ($packageVersion = $guesser->guessVersion($packageConfig, $path)) {
            return $packageVersion['commit'];
        }

        return null;
    }

    /**
     * Prompt the user to check if changes should be stashed/removed or the operation aborted
     *
     * @param  PackageInterface  $package
     * @param  string            $path
     * @param  bool              $update  if true (update) the changes can be stashed and reapplied after an update,
     *                                    if false (remove) the changes should be assumed to be lost if the operation is not aborted
     *
     * @return PromiseInterface
     *
     * @throws \RuntimeException in case the operation must be aborted
     */
    protected function cleanChanges(PackageInterface $package, string $path, bool $update): PromiseInterface
    {
        // the default implementation just fails if there are any changes, override in child classes to provide stash-ability
        if (null !== $this->getLocalChanges($package, $path)) {
            throw new \RuntimeException('Source directory ' . $path . ' has uncommitted changes.');
        }

        return \React\Promise\resolve(null);
    }

    /**
     * Reapply previously stashes changes if applicable, only called after an update (regardless if successful or not)
     *
     * @param string $path
     *
     * @return void
     *
     * @throws \RuntimeException in case the operation must be aborted or the patch does not apply cleanly
     */
    protected function reapplyChanges(string $path): void
    {
    }

    /**
     * Downloads data needed to run an install/update later
     *
     * @param PackageInterface      $package     package instance
     * @param string                $path        download path
     * @param string                $url         package url
     * @param PackageInterface|null $prevPackage previous package (in case of an update)
     *
     * @return PromiseInterface
     */
    abstract protected function doDownload(PackageInterface $package, string $path, string $url, PackageInterface $prevPackage = null): PromiseInterface;

    /**
     * Downloads specific package into specific folder.
     *
     * @param PackageInterface $package package instance
     * @param string           $path    download path
     * @param string           $url     package url
     *
     * @return PromiseInterface
     */
    abstract protected function doInstall(PackageInterface $package, string $path, string $url): PromiseInterface;

    /**
     * Updates specific package in specific folder from initial to target version.
     *
     * @param PackageInterface $initial initial package
     * @param PackageInterface $target  updated package
     * @param string           $path    download path
     * @param string           $url     package url
     *
     * @return PromiseInterface
     */
    abstract protected function doUpdate(PackageInterface $initial, PackageInterface $target, string $path, string $url): PromiseInterface;

    /**
     * Fetches the commit logs between two commits
     *
     * @param  string $fromReference the source reference
     * @param  string $toReference   the target reference
     * @param  string $path          the package path
     * @return string
     */
    abstract protected function getCommitLogs(string $fromReference, string $toReference, string $path): string;

    /**
     * Checks if VCS metadata repository has been initialized
     * repository example: .git|.svn|.hg
     *
     * @param  string $path
     * @return bool
     */
    abstract protected function hasMetadataRepository(string $path): bool;

    /**
     * @param string[] $urls
     *
     * @return string[]
     */
    private function prepareUrls(array $urls): array
    {
        foreach ($urls as $index => $url) {
            if (Filesystem::isLocalPath($url)) {
                // realpath() below will not understand
                // url that starts with "file://"
                $fileProtocol = 'file://';
                $isFileProtocol = false;
                if (0 === strpos($url, $fileProtocol)) {
                    $url = substr($url, strlen($fileProtocol));
                    $isFileProtocol = true;
                }

                // realpath() below will not understand %20 spaces etc.
                if (false !== strpos($url, '%')) {
                    $url = rawurldecode($url);
                }

                $urls[$index] = realpath($url);

                if ($isFileProtocol) {
                    $urls[$index] = $fileProtocol . $urls[$index];
                }
            }
        }

        return $urls;
    }
}
