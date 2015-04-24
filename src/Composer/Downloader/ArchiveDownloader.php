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

namespace Composer\Downloader;

use Composer\Package\PackageInterface;
use Symfony\Component\Finder\Finder;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Base downloader for archives
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
abstract class ArchiveDownloader extends FileDownloader
{
    /**
     * {@inheritDoc}
     */
    public function download(PackageInterface $package, $path, $loop = null)
    {
        $temporaryDir = $this->config->get('vendor-dir').'/composer/'.substr(md5(uniqid('', true)), 0, 8);

        return $this->retryArchiveDownload(3, $temporaryDir, $package, $path, $loop);
    }

    private function retryArchiveDownload($retries, $temporaryDir, $package, $path, $loop)
    {
        try {
            $result = parent::download($package, $path, $loop);
            if ($result instanceof PromiseInterface) {
                return $result->then(function ($fileName) use ($temporaryDir, $package, $path, $loop) {
                    $this->onDownload($fileName, $temporaryDir, $package, $path, $loop);
                })->then(null, function (\Exception $e) use ($retries, $temporaryDir, $package, $path, $loop) {
                    $this->onException($e, $retries, $temporaryDir, $package, $path, $loop);
                });
            } else {
                return $this->onDownload($result, $temporaryDir, $package, $path, $loop);
            }
        } catch (\Exception $e) {
            $this->onException($e, $retries, $temporaryDir, $package, $path, $loop);
        }
    }

    private function onDownload($fileName, $temporaryDir, $package, $path, $future) {
        if ($this->io->isVerbose()) {
            $this->io->writeError('    Extracting archive');
        }

        $this->filesystem->ensureDirectoryExists($temporaryDir);
        try {
            $this->extract($fileName, $temporaryDir);
        } catch (\Exception $e) {
            // remove cache if the file was corrupted
            parent::clearCache($package, $path);
            throw $e;
        }

        $this->filesystem->unlink($fileName);

        $contentDir = $this->getFolderContent($temporaryDir);

        // only one dir in the archive, extract its contents out of it
        if (1 === count($contentDir) && is_dir(reset($contentDir))) {
            $contentDir = $this->getFolderContent((string) reset($contentDir));
        }

        // move files back out of the temp dir
        foreach ($contentDir as $file) {
            $file = (string) $file;
            $this->filesystem->rename($file, $path . '/' . basename($file));
        }

        $this->filesystem->removeDirectory($temporaryDir);
        if ($this->filesystem->isDirEmpty($this->config->get('vendor-dir').'/composer/')) {
            $this->filesystem->removeDirectory($this->config->get('vendor-dir').'/composer/');
        }
        if ($this->filesystem->isDirEmpty($this->config->get('vendor-dir'))) {
            $this->filesystem->removeDirectory($this->config->get('vendor-dir'));
        }

        $this->io->writeError('');

        return $fileName;
    }

    private function onException(\Exception $e, $retries, $temporaryDir, $package, $path, $loop) {
        // clean up
        $this->filesystem->removeDirectory($path);
        $this->filesystem->removeDirectory($temporaryDir);

        // retry downloading if we have an invalid zip file
        if ($retries-- && $e instanceof \UnexpectedValueException && class_exists('ZipArchive') && $e->getCode() === \ZipArchive::ER_NOZIP) {
            $this->io->writeError('    Invalid zip file, retrying...');
            if ($loop) {
                $deferred = new Deferred();
                $loop->addTimer(0.5, function () use ($deferred, $retries, $temporaryDir, $package, $path, $loop) {
                    $result = $this->retryArchiveDownload($retries, $temporaryDir, $package, $path, $loop);
                    if ($result instanceof PromiseInterface) {
                        $result->then(array($deferred, 'resolve'), array($deferred, 'reject'));
                    } else {
                        $deferred->resolve($result);
                    }
                });

                return $deferred->promise();
            } else {
                usleep(500000);
                return $this->retryArchiveDownload($retries, $temporaryDir, $package, $path, $loop);
            }
        }
        throw $e;
    }

    /**
     * {@inheritdoc}
     */
    protected function getFileName(PackageInterface $package, $path)
    {
        return rtrim($path.'/'.md5($path.spl_object_hash($package)).'.'.pathinfo(parse_url($package->getDistUrl(), PHP_URL_PATH), PATHINFO_EXTENSION), '.');
    }

    /**
     * {@inheritdoc}
     */
    protected function processUrl(PackageInterface $package, $url)
    {
        if ($package->getDistReference() && strpos($url, 'github.com')) {
            if (preg_match('{^https?://(?:www\.)?github\.com/([^/]+)/([^/]+)/(zip|tar)ball/(.+)$}i', $url, $match)) {
                // update legacy github archives to API calls with the proper reference
                $url = 'https://api.github.com/repos/' . $match[1] . '/'. $match[2] . '/' . $match[3] . 'ball/' . $package->getDistReference();
            } elseif ($package->getDistReference() && preg_match('{^https?://(?:www\.)?github\.com/([^/]+)/([^/]+)/archive/.+\.(zip|tar)(?:\.gz)?$}i', $url, $match)) {
                // update current github web archives to API calls with the proper reference
                $url = 'https://api.github.com/repos/' . $match[1] . '/'. $match[2] . '/' . $match[3] . 'ball/' . $package->getDistReference();
            } elseif ($package->getDistReference() && preg_match('{^https?://api\.github\.com/repos/([^/]+)/([^/]+)/(zip|tar)ball(?:/.+)?$}i', $url, $match)) {
                // update api archives to the proper reference
                $url = 'https://api.github.com/repos/' . $match[1] . '/'. $match[2] . '/' . $match[3] . 'ball/' . $package->getDistReference();
            }
        }

        if (!extension_loaded('openssl') && (0 === strpos($url, 'https:') || 0 === strpos($url, 'http://github.com'))) {
            throw new \RuntimeException('You must enable the openssl extension to download files via https');
        }

        return parent::processUrl($package, $url);
    }

    /**
     * Extract file to directory
     *
     * @param string $file Extracted file
     * @param string $path Directory
     *
     * @throws \UnexpectedValueException If can not extract downloaded file to path
     */
    abstract protected function extract($file, $path);

    /**
     * Returns the folder content, excluding dotfiles
     *
     * @param  string         $dir Directory
     * @return \SplFileInfo[]
     */
    private function getFolderContent($dir)
    {
        $finder = Finder::create()
            ->ignoreVCS(false)
            ->ignoreDotFiles(false)
            ->depth(0)
            ->in($dir);

        return iterator_to_array($finder);
    }
}
