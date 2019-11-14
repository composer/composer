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
use Composer\Util\Http\Response;
use Composer\Composer;
use Composer\Package\Version\VersionParser;
use Composer\Semver\Constraint\Constraint;
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
    private $runningJobs = 0;
    private $maxJobs = 10;
    private $lastProgress;
    private $disableTls = false;
    private $curl;
    private $rfs;
    private $idGen = 0;
    private $disabled;

    /**
     * @param IOInterface $io         The IO instance
     * @param Config      $config     The config
     * @param array       $options    The options
     * @param bool        $disableTls
     */
    public function __construct(IOInterface $io, Config $config, array $options = array(), $disableTls = false)
    {
        $this->io = $io;

        $this->disabled = (bool) getenv('COMPOSER_DISABLE_NETWORK');

        // Setup TLS options
        // The cafile option can be set via config.json
        if ($disableTls === false) {
            $this->options = StreamContextFactory::getTlsDefaults($options, $io);
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
     * @return void
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
            'origin' => Url::getOrigin($this->config, $request['url']),
        );

        // capture username/password from URL if there is one
        if (preg_match('{^https?://([^:/]+):([^@/]+)@([^/]+)}i', $request['url'], $match)) {
            $this->io->setAuthentication($job['origin'], rawurldecode($match[1]), rawurldecode($match[2]));
        }

        $rfs = $this->rfs;

        if ($this->curl && preg_match('{^https?://}i', $job['request']['url'])) {
            $resolver = function ($resolve, $reject) use (&$job) {
                $job['status'] = HttpDownloader::STATUS_QUEUED;
                $job['resolve'] = $resolve;
                $job['reject'] = $reject;
            };
        } else {
            $resolver = function ($resolve, $reject) use (&$job, $rfs) {
                // start job
                $url = $job['request']['url'];
                $options = $job['request']['options'];

                $job['status'] = HttpDownloader::STATUS_STARTED;

                if ($job['request']['copyTo']) {
                    $result = $rfs->copy($job['origin'], $url, $job['request']['copyTo'], false /* TODO progress */, $options);

                    $headers = $rfs->getLastHeaders();
                    $response = new Http\Response($job['request'], $rfs->findStatusCode($headers), $headers, $job['request']['copyTo'].'~');

                    $resolve($response);
                } else {
                    $body = $rfs->getContents($job['origin'], $url, false /* TODO progress */, $options);
                    $headers = $rfs->getLastHeaders();
                    $response = new Http\Response($job['request'], $rfs->findStatusCode($headers), $headers, $body);

                    $resolve($response);
                }
            };
        }

        $downloader = $this;
        $io = $this->io;

        $canceler = function () {};

        $promise = new Promise($resolver, $canceler);
        $promise->then(function ($response) use (&$job, $downloader) {
            $job['status'] = HttpDownloader::STATUS_COMPLETED;
            $job['response'] = $response;

            // TODO 3.0 this should be done directly on $this when PHP 5.3 is dropped
            $downloader->markJobDone();
            $downloader->scheduleNextJob();

            return $response;
        }, function ($e) use ($io, &$job, $downloader) {
            $job['status'] = HttpDownloader::STATUS_FAILED;
            $job['exception'] = $e;

            $downloader->markJobDone();
            $downloader->scheduleNextJob();

            throw $e;
        });
        $this->jobs[$job['id']] =& $job;

        if ($this->runningJobs < $this->maxJobs) {
            $this->startJob($job['id']);
        }

        return array($job, $promise);
    }

    private function startJob($id)
    {
        $job =& $this->jobs[$id];
        if ($job['status'] !== self::STATUS_QUEUED) {
            return;
        }

        // start job
        $job['status'] = self::STATUS_STARTED;
        $this->runningJobs++;

        $resolve = $job['resolve'];
        $reject = $job['reject'];
        $url = $job['request']['url'];
        $options = $job['request']['options'];
        $origin = $job['origin'];

        if ($this->disabled) {
            if (isset($job['request']['options']['http']['header']) && false !== stripos(implode('', $job['request']['options']['http']['header']), 'if-modified-since')) {
                $resolve(new Response(array('url' => $url), 304, array(), ''));
            } else {
                $e = new TransportException('Network disabled, request canceled: '.$url, 499);
                $e->setStatusCode(499);
                $reject($e);
            }
            return;
        }

        if ($job['request']['copyTo']) {
            $this->curl->download($resolve, $reject, $origin, $url, $options, $job['request']['copyTo']);
        } else {
            $this->curl->download($resolve, $reject, $origin, $url, $options);
        }
    }

    /**
     * @private
     */
    public function markJobDone()
    {
        $this->runningJobs--;
    }

    /**
     * @private
     */
    public function scheduleNextJob()
    {
        foreach ($this->jobs as $job) {
            if ($job['status'] === self::STATUS_QUEUED) {
                $this->startJob($job['id']);
                if ($this->runningJobs >= $this->maxJobs) {
                    return;
                }
            }
        }
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

    public static function outputWarnings(IOInterface $io, $url, $data)
    {
        foreach (array('warning', 'info') as $type) {
            if (empty($data[$type])) {
                continue;
            }

            if (!empty($data[$type . '-versions'])) {
                $versionParser = new VersionParser();
                $constraint = $versionParser->parseConstraints($data[$type . '-versions']);
                $composer = new Constraint('==', $versionParser->normalize(Composer::getVersion()));
                if (!$constraint->matches($composer)) {
                    continue;
                }
            }

            $io->writeError('<'.$type.'>'.ucfirst($type).' from '.$url.': '.$data[$type].'</'.$type.'>');
        }
    }
}
