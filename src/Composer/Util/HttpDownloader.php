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

namespace Composer\Util;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Downloader\TransportException;
use Composer\CaBundle\CaBundle;
use Psr\Log\LoggerInterface;
use React\Promise\Promise;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class HttpDownloader
{
    const STATUS_QUEUED = 1;
    const STATUS_STARTED = 2;
    const STATUS_COMPLETED = 3;
    const STATUS_FAILED = 4;

    private $io;
    private $config;
    private $jobs = array();
    private $options = array();
    private $index;
    private $progress;
    private $lastProgress;
    private $disableTls = false;
    private $curl;
    private $rfs;
    private $idGen = 0;

    /**
     * Constructor.
     *
     * @param IOInterface $io         The IO instance
     * @param Config      $config     The config
     * @param array       $options    The options
     * @param bool        $disableTls
     */
    public function __construct(IOInterface $io, Config $config, array $options = array(), $disableTls = false)
    {
        $this->io = $io;

        // Setup TLS options
        // The cafile option can be set via config.json
        if ($disableTls === false) {
            $logger = $io instanceof LoggerInterface ? $io : null;
            $this->options = StreamContextFactory::getTlsDefaults($options, $logger);
        } else {
            $this->disableTls = true;
        }

        // handle the other externally set options normally.
        $this->options = array_replace_recursive($this->options, $options);
        $this->config = $config;

        // TODO enable curl only on 5.6+ if older versions cause any problem
        if (extension_loaded('curl')) {
            $this->curl = new Http\CurlDownloader($io, $config, $options, $disableTls);
        }

        $this->rfs = new RemoteFilesystem($io, $config, $options, $disableTls);
    }

    public function get($url, $options = array())
    {
        list($job, $promise) = $this->addJob(array('url' => $url, 'options' => $options, 'copyTo' => false), true);
        $this->wait($job['id']);

        return $this->getResponse($job['id']);
    }

    public function add($url, $options = array())
    {
        list($job, $promise) = $this->addJob(array('url' => $url, 'options' => $options, 'copyTo' => false));

        return $promise;
    }

    public function copy($url, $to, $options = array())
    {
        list($job, $promise) = $this->addJob(array('url' => $url, 'options' => $options, 'copyTo' => $to), true);
        $this->wait($job['id']);

        return $this->getResponse($job['id']);
    }

    public function addCopy($url, $to, $options = array())
    {
        list($job, $promise) = $this->addJob(array('url' => $url, 'options' => $options, 'copyTo' => $to));

        return $promise;
    }

    /**
     * Retrieve the options set in the constructor
     *
     * @return array Options
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Merges new options
     *
     * @return array $options
     */
    public function setOptions(array $options)
    {
        $this->options = array_replace_recursive($this->options, $options);
    }

    private function addJob($request, $sync = false)
    {
        $job = array(
            'id' => $this->idGen++,
            'status' => self::STATUS_QUEUED,
            'request' => $request,
            'sync' => $sync,
        );

        $origin = Url::getOrigin($this->config, $job['request']['url']);

        // capture username/password from URL if there is one
        if (preg_match('{^https?://([^:/]+):([^@/]+)@([^/]+)}i', $request['url'], $match)) {
            $this->io->setAuthentication($origin, rawurldecode($match[1]), rawurldecode($match[2]));
        }

        $curl = $this->curl;
        $rfs = $this->rfs;
        $io = $this->io;

        if ($curl && preg_match('{^https?://}i', $job['request']['url'])) {
            $resolver = function ($resolve, $reject) use (&$job, $curl, $origin) {
                // start job
                $url = $job['request']['url'];
                $options = $job['request']['options'];

                $job['status'] = HttpDownloader::STATUS_STARTED;

                if ($job['request']['copyTo']) {
                    $curl->download($resolve, $reject, $origin, $url, $options, $job['request']['copyTo']);
                } else {
                    $curl->download($resolve, $reject, $origin, $url, $options);
                }
            };
        } else {
            $resolver = function ($resolve, $reject) use (&$job, $rfs, $curl, $origin) {
                // start job
                $url = $job['request']['url'];
                $options = $job['request']['options'];

                $job['status'] = HttpDownloader::STATUS_STARTED;

                if ($job['request']['copyTo']) {
                    $result = $rfs->copy($origin, $url, $job['request']['copyTo'], false /* TODO progress */, $options);

                    $resolve($result);
                } else {
                    $body = $rfs->getContents($origin, $url, false /* TODO progress */, $options);
                    $headers = $rfs->getLastHeaders();
                    $response = new Http\Response($job['request'], $rfs->findStatusCode($headers), $headers, $body);

                    $resolve($response);
                }
            };
        }

        $canceler = function () {};

        $promise = new Promise($resolver, $canceler);
        $promise->then(function ($response) use (&$job) {
            $job['status'] = HttpDownloader::STATUS_COMPLETED;
            $job['response'] = $response;
            // TODO look for more jobs to start once we throttle to max X jobs
        }, function ($e) use ($io, &$job) {
            // var_dump(__CLASS__ . __LINE__);
            // var_dump(get_class($e));
            // var_dump($e->getMessage());
            // die;
            $job['status'] = HttpDownloader::STATUS_FAILED;
            $job['exception'] = $e;
        });
        $this->jobs[$job['id']] =& $job;

        return array($job, $promise);
    }

    public function wait($index = null, $progress = false)
    {
        while (true) {
            if ($this->curl) {
                $this->curl->tick();
            }

            if (null !== $index) {
                if ($this->jobs[$index]['status'] === self::STATUS_COMPLETED || $this->jobs[$index]['status'] === self::STATUS_FAILED) {
                    return;
                }
            } else {
                $done = true;
                foreach ($this->jobs as $job) {
                    if (!in_array($job['status'], array(self::STATUS_COMPLETED, self::STATUS_FAILED), true)) {
                        $done = false;
                        break;
                    } elseif (!$job['sync']) {
                        unset($this->jobs[$job['id']]);
                    }
                }
                if ($done) {
                    return;
                }
            }

            usleep(1000);
        }
    }

    private function getResponse($index)
    {
        if (!isset($this->jobs[$index])) {
            throw new \LogicException('Invalid request id');
        }

        if ($this->jobs[$index]['status'] === self::STATUS_FAILED) {
            throw $this->jobs[$index]['exception'];
        }

        if (!isset($this->jobs[$index]['response'])) {
            throw new \LogicException('Response not available yet, call wait() first');
        }

        $resp = $this->jobs[$index]['response'];

        unset($this->jobs[$index]);

        return $resp;
    }
}
