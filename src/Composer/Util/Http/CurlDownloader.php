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

namespace Composer\Util\Http;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Downloader\TransportException;
use Composer\CaBundle\CaBundle;
use Psr\Log\LoggerInterface;
use React\Promise\Promise;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Nicolas Grekas <p@tchwork.com>
 */
class CurlDownloader
{
    private $multiHandle;
    private $shareHandle;
    private $jobs = array();
    private $io;
    private $selectTimeout = 5.0;
    protected $multiErrors = array(
        CURLM_BAD_HANDLE      => array('CURLM_BAD_HANDLE', 'The passed-in handle is not a valid CURLM handle.'),
        CURLM_BAD_EASY_HANDLE => array('CURLM_BAD_EASY_HANDLE', "An easy handle was not good/valid. It could mean that it isn't an easy handle at all, or possibly that the handle already is in used by this or another multi handle."),
        CURLM_OUT_OF_MEMORY   => array('CURLM_OUT_OF_MEMORY', 'You are doomed.'),
        CURLM_INTERNAL_ERROR  => array('CURLM_INTERNAL_ERROR', 'This can only be returned if libcurl bugs. Please report it to us!')
    );

    private static $options = array(
        'http' => array(
            'method' => CURLOPT_CUSTOMREQUEST,
            'content' => CURLOPT_POSTFIELDS,
            'proxy' => CURLOPT_PROXY,
        ),
        'ssl' => array(
            'ciphers' => CURLOPT_SSL_CIPHER_LIST,
            'cafile' => CURLOPT_CAINFO,
            'capath' => CURLOPT_CAPATH,
        ),
    );

    private static $timeInfo = array(
        'total_time' => true,
        'namelookup_time' => true,
        'connect_time' => true,
        'pretransfer_time' => true,
        'starttransfer_time' => true,
        'redirect_time' => true,
    );

    public function __construct(IOInterface $io, Config $config, array $options = array(), $disableTls = false)
    {
        $this->io = $io;

        $this->multiHandle = $mh = curl_multi_init();
        if (function_exists('curl_multi_setopt')) {
            curl_multi_setopt($mh, CURLMOPT_PIPELINING, /*CURLPIPE_HTTP1 | CURLPIPE_MULTIPLEX*/ 3);
            if (defined('CURLMOPT_MAX_HOST_CONNECTIONS')) {
                curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, 8);
            }
        }

        if (function_exists('curl_share_init')) {
            $this->shareHandle = $sh = curl_share_init();
            curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
            curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
            curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
        }
    }

    public function download($resolve, $reject, $origin, $url, $options, $copyTo = null)
    {
        $ch = curl_init();
        $hd = fopen('php://temp/maxmemory:32768', 'w+b');

        // TODO auth & other context
        // TODO cleanup

        if ($copyTo && !$fd = @fopen($copyTo.'~', 'w+b')) {
            // TODO throw here probably?
            $copyTo = null;
        }
        if (!$copyTo) {
            $fd = @fopen('php://temp/maxmemory:524288', 'w+b');
        }

        if (!isset($options['http']['header'])) {
            $options['http']['header'] = array();
        }

        $headers = array_diff($options['http']['header'], array('Connection: close'));

        // TODO
        $degradedMode = false;
        if ($degradedMode) {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
        } else {
            $headers[] = 'Connection: keep-alive';
            $version = curl_version();
            $features = $version['features'];
            if (0 === strpos($url, 'https://') && \defined('CURL_VERSION_HTTP2') && \defined('CURL_HTTP_VERSION_2_0') && (CURL_VERSION_HTTP2 & $features)) {
                curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
            }
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        //curl_setopt($ch, CURLOPT_DNS_USE_GLOBAL_CACHE, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // TODO increase
        curl_setopt($ch, CURLOPT_WRITEHEADER, $hd);
        curl_setopt($ch, CURLOPT_FILE, $fd);
        if (function_exists('curl_share_init')) {
            curl_setopt($ch, CURLOPT_SHARE, $this->shareHandle);
        }

        foreach (self::$options as $type => $curlOptions) {
            foreach ($curlOptions as $name => $curlOption) {
                if (isset($options[$type][$name])) {
                    curl_setopt($ch, $curlOption, $options[$type][$name]);
                }
            }
        }

        $progress = array_diff_key(curl_getinfo($ch), self::$timeInfo);

        $this->jobs[(int) $ch] = array(
            'progress' => $progress,
            'ch' => $ch,
            //'callback' => $params['notification'],
            'file' => $copyTo,
            'hd' => $hd,
            'fd' => $fd,
            'resolve' => $resolve,
            'reject' => $reject,
        );

        $this->io->write('Downloading '.$url, true, IOInterface::DEBUG);

        $this->checkCurlResult(curl_multi_add_handle($this->multiHandle, $ch));
        //$params['notification'](STREAM_NOTIFY_RESOLVE, STREAM_NOTIFY_SEVERITY_INFO, '', 0, 0, 0, false);
    }

    public function tick()
    {
        // TODO check we have active handles before doing this
        if (!$this->jobs) {
            return;
        }

        $active = true;
        try {
            $this->checkCurlResult(curl_multi_exec($this->multiHandle, $active));
            if (-1 === curl_multi_select($this->multiHandle, $this->selectTimeout)) {
                // sleep in case select returns -1 as it can happen on old php versions or some platforms where curl does not manage to do the select
                usleep(150);
            }

            while ($progress = curl_multi_info_read($this->multiHandle)) {
                $h = $progress['handle'];
                $i = (int) $h;
                if (!isset($this->jobs[$i])) {
                    continue;
                }
                $progress = array_diff_key(curl_getinfo($h), self::$timeInfo);
                $job = $this->jobs[$i];
                unset($this->jobs[$i]);
                curl_multi_remove_handle($this->multiHandle, $h);
                $error = curl_error($h);
                $errno = curl_errno($h);
                curl_close($h);

                try {
                    //$this->onProgress($h, $job['callback'], $progress, $job['progress']);
                    if ('' !== $error) {
                        throw new TransportException(curl_error($h));
                    }

                    if ($job['file']) {
                        if (CURLE_OK === $errno) {
                            fclose($job['fd']);
                            rename($job['file'].'~', $job['file']);
                            call_user_func($job['resolve'], true);
                        }
                        // TODO otherwise show error?
                    } else {
                        rewind($job['hd']);
                        $headers = explode("\r\n", rtrim(stream_get_contents($job['hd'])));
                        fclose($job['hd']);
                        rewind($job['fd']);
                        $contents = stream_get_contents($job['fd']);
                        fclose($job['fd']);
                        $this->io->writeError('['.$progress['http_code'].'] '.$progress['url'], true, IOInterface::DEBUG);
                        call_user_func($job['resolve'], new Response(array('url' => $progress['url']), $progress['http_code'], $headers, $contents));
                    }
                } catch (TransportException $e) {
                    fclose($job['hd']);
                    fclose($job['fd']);
                    if ($job['file']) {
                        @unlink($job['file'].'~');
                    }
                    call_user_func($job['reject'], $e);
                }
            }

            foreach ($this->jobs as $i => $h) {
                if (!isset($this->jobs[$i])) {
                    continue;
                }
                $h = $this->jobs[$i]['ch'];
                $progress = array_diff_key(curl_getinfo($h), self::$timeInfo);

                if ($this->jobs[$i]['progress'] !== $progress) {
                    $previousProgress = $this->jobs[$i]['progress'];
                    $this->jobs[$i]['progress'] = $progress;
                    try {
                        //$this->onProgress($h, $this->jobs[$i]['callback'], $progress, $previousProgress);
                    } catch (TransportException $e) {
                        var_dump('Caught '.$e->getMessage());die;
                        unset($this->jobs[$i]);
                        curl_multi_remove_handle($this->multiHandle, $h);
                        curl_close($h);

                        fclose($job['hd']);
                        fclose($job['fd']);
                        if ($job['file']) {
                            @unlink($job['file'].'~');
                        }
                        call_user_func($job['reject'], $e);
                    }
                }
            }
        } catch (\Exception $e) {
            var_dump('Caught2', get_class($e), $e->getMessage(), $e);die;
        }

// TODO finalize / resolve
//            if ($copyTo && !isset($this->exceptions[(int) $ch])) {
//                $fd = fopen($copyTo, 'rb');
//            }
//
    }

    private function onProgress($ch, callable $notify, array $progress, array $previousProgress)
    {
        if (300 <= $progress['http_code'] && $progress['http_code'] < 400) {
            return;
        }
        if (!$previousProgress['http_code'] && $progress['http_code'] && $progress['http_code'] < 200 || 400 <= $progress['http_code']) {
            $code = 403 === $progress['http_code'] ? STREAM_NOTIFY_AUTH_RESULT : STREAM_NOTIFY_FAILURE;
            $notify($code, STREAM_NOTIFY_SEVERITY_ERR, curl_error($ch), $progress['http_code'], 0, 0, false);
        }
        if ($previousProgress['download_content_length'] < $progress['download_content_length']) {
            $notify(STREAM_NOTIFY_FILE_SIZE_IS, STREAM_NOTIFY_SEVERITY_INFO, '', 0, 0, (int) $progress['download_content_length'], false);
        }
        if ($previousProgress['size_download'] < $progress['size_download']) {
            $notify(STREAM_NOTIFY_PROGRESS, STREAM_NOTIFY_SEVERITY_INFO, '', 0, (int) $progress['size_download'], (int) $progress['download_content_length'], false);
        }
    }

    private function checkCurlResult($code)
    {
        if ($code != CURLM_OK && $code != CURLM_CALL_MULTI_PERFORM) {
            throw new \RuntimeException(isset($this->multiErrors[$code])
                ? "cURL error: {$code} ({$this->multiErrors[$code][0]}): cURL message: {$this->multiErrors[$code][1]}"
                : 'Unexpected cURL error: ' . $code
            );
        }
    }
}
