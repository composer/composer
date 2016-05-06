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

namespace Composer\Downloader\Prefetcher;

use Composer\Downloader\FileDownloader;
use Composer\IO;
use Composer\Config;
use Composer\Package;
use Composer\DependencyResolver\Operation;

class Prefetcher
{
    /**
     * @param IO\IOInterface $io
     * @param CopyRequest[] $requests
     */
    public function fetchAll(IO\IOInterface $io, array $requests)
    {
        $successCnt = $failureCnt = 0;
        $totalCnt = count($requests);

        $multi = new CurlMulti;
        $multi->setRequests($requests);
        try {
            do {
                $multi->setupEventLoop();
                $multi->wait();

                $result = $multi->getFinishedResults();
                $successCnt += $result['successCnt'];
                $failureCnt += $result['failureCnt'];
                foreach ($result['urls'] as $url) {
                    $io->writeError("    <comment>$successCnt/$totalCnt</comment>:\t$url");
                }
            } while ($multi->remain());
        } catch (FetchException $e) {
            // do nothing
        }

        $skippedCnt = $totalCnt - $successCnt - $failureCnt;
        $io->writeError("    Finished: <comment>success:$successCnt, skipped:$skippedCnt, failure:$failureCnt, total: $totalCnt</comment>");
    }

    /**
     * @param IO\IOInterface $io
     * @param Config $config
     * @param Operation\OperationInterface[] $ops
     */
    public function fetchAllFromOperations(IO\IOInterface $io, Config $config, array $ops)
    {
        $cachedir = rtrim($config->get('cache-files-dir'), '\/');
        $requests = array();
        foreach ($ops as $op) {
            switch ($op->getJobType()) {
                case 'install':
                    $p = $op->getPackage();
                    break;
                case 'update':
                    $p = $op->getTargetPackage();
                    break;
                default:
                    continue 2;
            }

            $url = $this->getUrlFromPackage($p);
            if (!$url) {
                continue;
            }

            $destination = $cachedir . DIRECTORY_SEPARATOR . FileDownloader::getCacheKey($p, $url);
            if (file_exists($destination)) {
                continue;
            }
            $useRedirector = (bool)preg_match('%^(?:https|git)://github\.com%', $p->getSourceUrl());
            try {
                $request = new CopyRequest($url, $destination, $useRedirector, $io, $config);
                $requests[] = $request;
            } catch (FetchException $e) {
                // do nothing
            }
        }

        if (count($requests) > 0) {
            $this->fetchAll($io, $requests);
        }
    }

    private static function getUrlFromPackage(Package\PackageInterface $package)
    {
        $url = $package->getDistUrl();
        if (!$url) {
            return false;
        }
        if ($package->getDistMirrors()) {
            $url = current($package->getDistUrls());
        }
        if (!parse_url($url, PHP_URL_HOST)) {
            return false;
        }
        return $url;
    }

}
