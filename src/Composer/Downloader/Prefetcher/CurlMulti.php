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

class CurlMulti
{
    const MAX_CONNECTIONS = 6;

    /** @var resource<curl_multi> */
    private $mh;

    /** @var resource<curl_share> */
    private $sh;

    /** @var resource<curl>[] */
    private $unused = array();

    /** @var resource<curl>[] */
    private $using = array();

    /** @var CopyRequest[] */
    private $requests;

    /** @var CopyRequest[] */
    private $runningRequests;

    /** @var bool */
    private $permanent = true;

    private $blackhole;

    /**
     * @param bool $permanent
     */
    public function __construct($permanent = true)
    {
        static $mh_cache, $sh_cache, $ch_cache;

        if (!$permanent || !$mh_cache) {
            $mh_cache = curl_multi_init();

            $ch_cache = array();
            for ($i = 0; $i < self::MAX_CONNECTIONS; ++$i) {
                $ch_cache[] = curl_init();
            }
            // @codeCoverageIgnoreStart
            if (function_exists('curl_share_init')) {
                $sh_cache = curl_share_init();
                curl_share_setopt($sh_cache, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);

                foreach ($ch_cache as $ch) {
                    curl_setopt($ch, CURLOPT_SHARE, $sh_cache);
                }
            }
            // @codeCoverageIgnoreEnd
        }

        $this->mh = $mh_cache;
        $this->sh = $sh_cache;
        $this->unused = $ch_cache;
        $this->permanent = $permanent;

        // for PHP<5.5 @see getFinishedResults()
        $this->blackhole = fopen('php://temp', 'wb');
    }

    /**
     * @codeCoverageIgnore
     */
    public function __destruct()
    {
        foreach ($this->using as $ch) {
            curl_multi_remove_handle($this->mh, $ch);
            $this->unused[] = $ch;
        }

        if ($this->permanent) {
            return; //don't close connection
        }

        foreach ($this->unused as $ch) {
            curl_close($ch);
        }

        curl_multi_close($this->mh);
    }

    /**
     * @param CopyRequest[] $requests
     */
    public function setRequests(array $requests)
    {
        $this->requests = $requests;
    }

    public function setupEventLoop()
    {
        while (count($this->unused) > 0 && count($this->requests) > 0) {
            $request = array_pop($this->requests);
            $ch = array_pop($this->unused);
            $index = (int)$ch;

            $this->using[$index] = $ch;
            $this->runningRequests[$index] = $request;

            curl_setopt_array($ch, $request->getCurlOptions());
            curl_multi_add_handle($this->mh, $ch);
        }
    }

    public function wait()
    {
        $expectRunning = count($this->using);
        $running = 0;
        $retryCnt = 0;

        do {
            do {
                $stat = curl_multi_exec($this->mh, $running);
            } while ($stat === CURLM_CALL_MULTI_PERFORM);
            if (-1 === curl_multi_select($this->mh)) {
                // @codeCoverageIgnoreStart
                if ($retryCnt++ > 100) {
                    throw new FetchException('curl_multi_select failure');
                }
                // @codeCoverageIgnoreEnd
                usleep(100000);
            }
        } while ($running > 0 && $running >= $expectRunning);
    }

    public function getFinishedResults()
    {
        $urls = array();
        $successCnt = $failureCnt = 0;
        do {
            if ($raised = curl_multi_info_read($this->mh, $remains)) {
                $ch = $raised['handle'];
                $errno = curl_errno($ch);
                $error = curl_error($ch);
                $info = curl_getinfo($ch);
                curl_setopt($ch, CURLOPT_FILE, $this->blackhole); //release file pointer
                $index = (int)$ch;
                $request = $this->runningRequests[$index];
                if (CURLE_OK === $errno && !$error && ('http' !== substr($info['url'], 0, 4) || 200 === $info['http_code'])) {
                    ++$successCnt;
                    $request->makeSuccess();
                    $urls[] = $request->getMaskedURL();
                } else {
                    ++$failureCnt;
                }
                unset($this->using[$index], $this->runningRequests[$index], $request);
                curl_multi_remove_handle($this->mh, $ch);
                $this->unused[] = $ch;
            }
        } while ($remains > 0);

        return array(
            'successCnt' => $successCnt,
            'failureCnt' => $failureCnt,
            'urls' => $urls,
        );
    }

    public function remain()
    {
        return count($this->runningRequests) > 0;
    }
}
