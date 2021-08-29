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
use Composer\Util\Http\Response;
use Composer\Util\Http\CurlDownloader;
use Composer\Composer;
use Composer\Package\Version\VersionParser;
use Composer\Semver\Constraint\Constraint;
use Composer\Exception\IrrecoverableDownloadException;
use React\Promise\Promise;
use React\Promise\PromiseInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @phpstan-type Request array{url: string, options?: mixed[], copyTo?: ?string}
 * @phpstan-type Job array{id: int, status: int, request: Request, sync: bool, origin: string, resolve?: callable, reject?: callable, curl_id?: int, response?: Response, exception?: TransportException}
 */
class HttpDownloader
{
    const STATUS_QUEUED = 1;
    const STATUS_STARTED = 2;
    const STATUS_COMPLETED = 3;
    const STATUS_FAILED = 4;
    const STATUS_ABORTED = 5;

    /** @var IOInterface */
    private $io;
    /** @var Config */
    private $config;
    /** @var array<Job> */
    private $jobs = array();
    /** @var mixed[] */
    private $options = array();
    /** @var int */
    private $runningJobs = 0;
    /** @var int */
    private $maxJobs = 12;
    /** @var ?CurlDownloader */
    private $curl;
    /** @var ?RemoteFilesystem */
    private $rfs;
    /** @var int */
    private $idGen = 0;
    /** @var bool */
    private $disabled;
    /** @var bool */
    private $allowAsync = false;

    /**
     * @param IOInterface $io         The IO instance
     * @param Config      $config     The config
     * @param mixed[]     $options    The options
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
        }

        // handle the other externally set options normally.
        $this->options = array_replace_recursive($this->options, $options);
        $this->config = $config;

        if (self::isCurlEnabled()) {
            $this->curl = new CurlDownloader($io, $config, $options, $disableTls);
        }

        $this->rfs = new RemoteFilesystem($io, $config, $options, $disableTls);

        if (is_numeric($maxJobs = getenv('COMPOSER_MAX_PARALLEL_HTTP'))) {
            $this->maxJobs = max(1, min(50, (int) $maxJobs));
        }
    }

    /**
     * Download a file synchronously
     *
     * @param  string             $url     URL to download
     * @param  mixed[]            $options Stream context options e.g. https://www.php.net/manual/en/context.http.php
     *                                     although not all options are supported when using the default curl downloader
     * @throws TransportException
     * @return Response
     */
    public function get($url, $options = array())
    {
        list($job) = $this->addJob(array('url' => $url, 'options' => $options, 'copyTo' => null), true);
        $this->wait($job['id']);

        $response = $this->getResponse($job['id']);

        // check for failed curl response (empty body but successful looking response)
        if (
            $this->curl
            && PHP_VERSION_ID < 70000
            && $response->getBody() === null
            && $response->getStatusCode() === 200
            && $response->getHeader('content-length') !== '0'
        ) {
            $this->io->writeError('<warning>cURL downloader failed to return a response, disabling it and proceeding in slow mode.</warning>');

            $this->curl = null;

            list($job) = $this->addJob(array('url' => $url, 'options' => $options, 'copyTo' => null), true);
            $this->wait($job['id']);

            $response = $this->getResponse($job['id']);
        }

        return $response;
    }

    /**
     * Create an async download operation
     *
     * @param  string             $url     URL to download
     * @param  mixed[]            $options Stream context options e.g. https://www.php.net/manual/en/context.http.php
     *                                     although not all options are supported when using the default curl downloader
     * @throws TransportException
     * @return PromiseInterface
     */
    public function add($url, $options = array())
    {
        list(, $promise) = $this->addJob(array('url' => $url, 'options' => $options, 'copyTo' => null));

        return $promise;
    }

    /**
     * Copy a file synchronously
     *
     * @param  string             $url     URL to download
     * @param  string             $to      Path to copy to
     * @param  mixed[]            $options Stream context options e.g. https://www.php.net/manual/en/context.http.php
     *                                     although not all options are supported when using the default curl downloader
     * @throws TransportException
     * @return Response
     */
    public function copy($url, $to, $options = array())
    {
        list($job) = $this->addJob(array('url' => $url, 'options' => $options, 'copyTo' => $to), true);
        $this->wait($job['id']);

        return $this->getResponse($job['id']);
    }

    /**
     * Create an async copy operation
     *
     * @param  string             $url     URL to download
     * @param  string             $to      Path to copy to
     * @param  mixed[]            $options Stream context options e.g. https://www.php.net/manual/en/context.http.php
     *                                     although not all options are supported when using the default curl downloader
     * @throws TransportException
     * @return PromiseInterface
     */
    public function addCopy($url, $to, $options = array())
    {
        list(, $promise) = $this->addJob(array('url' => $url, 'options' => $options, 'copyTo' => $to));

        return $promise;
    }

    /**
     * Retrieve the options set in the constructor
     *
     * @return mixed[] Options
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Merges new options
     *
     * @param  mixed[] $options
     * @return void
     */
    public function setOptions(array $options)
    {
        $this->options = array_replace_recursive($this->options, $options);
    }

    /**
     * @param Request $request
     * @param bool    $sync
     *
     * @return array{Job, PromiseInterface}
     */
    private function addJob($request, $sync = false)
    {
        $request['options'] = array_replace_recursive($this->options, $request['options']);

        /** @var Job */
        $job = array(
            'id' => $this->idGen++,
            'status' => self::STATUS_QUEUED,
            'request' => $request,
            'sync' => $sync,
            'origin' => Url::getOrigin($this->config, $request['url']),
        );

        if (!$sync && !$this->allowAsync) {
            throw new \LogicException('You must use the HttpDownloader instance which is part of a Composer\Loop instance to be able to run async http requests');
        }

        // capture username/password from URL if there is one
        if (preg_match('{^https?://([^:/]+):([^@/]+)@([^/]+)}i', $request['url'], $match)) {
            $this->io->setAuthentication($job['origin'], rawurldecode($match[1]), rawurldecode($match[2]));
        }

        $rfs = $this->rfs;

        if ($this->canUseCurl($job)) {
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
                    $rfs->copy($job['origin'], $url, $job['request']['copyTo'], false /* TODO progress */, $options);

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
        $curl = $this->curl;

        $canceler = function () use (&$job, $curl) {
            if ($job['status'] === HttpDownloader::STATUS_QUEUED) {
                $job['status'] = HttpDownloader::STATUS_ABORTED;
            }
            if ($job['status'] !== HttpDownloader::STATUS_STARTED) {
                return;
            }
            $job['status'] = HttpDownloader::STATUS_ABORTED;
            if (isset($job['curl_id'])) {
                $curl->abortRequest($job['curl_id']);
            }
            throw new IrrecoverableDownloadException('Download of ' . Url::sanitize($job['request']['url']) . ' canceled');
        };

        $promise = new Promise($resolver, $canceler);
        $promise = $promise->then(function ($response) use (&$job, $downloader) {
            $job['status'] = HttpDownloader::STATUS_COMPLETED;
            $job['response'] = $response;

            // TODO 3.0 this should be done directly on $this when PHP 5.3 is dropped
            $downloader->markJobDone();

            return $response;
        }, function ($e) use (&$job, $downloader) {
            $job['status'] = HttpDownloader::STATUS_FAILED;
            $job['exception'] = $e;

            $downloader->markJobDone();

            throw $e;
        });
        $this->jobs[$job['id']] = &$job;

        if ($this->runningJobs < $this->maxJobs) {
            $this->startJob($job['id']);
        }

        return array($job, $promise);
    }

    /**
     * @param  int  $id
     * @return void
     */
    private function startJob($id)
    {
        $job = &$this->jobs[$id];
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
                $e = new TransportException('Network disabled, request canceled: '.Url::sanitize($url), 499);
                $e->setStatusCode(499);
                $reject($e);
            }

            return;
        }

        try {
            if ($job['request']['copyTo']) {
                $job['curl_id'] = $this->curl->download($resolve, $reject, $origin, $url, $options, $job['request']['copyTo']);
            } else {
                $job['curl_id'] = $this->curl->download($resolve, $reject, $origin, $url, $options);
            }
        } catch (\Exception $exception) {
            $reject($exception);
        }
    }

    /**
     * @private
     * @return void
     */
    public function markJobDone()
    {
        $this->runningJobs--;
    }

    /**
     * Wait for current async download jobs to complete
     *
     * @param int|null $index For internal use only, the job id
     *
     * @return void
     */
    public function wait($index = null)
    {
        do {
            $jobCount = $this->countActiveJobs($index);
        } while ($jobCount);
    }

    /**
     * @internal
     *
     * @return void
     */
    public function enableAsync()
    {
        $this->allowAsync = true;
    }

    /**
     * @internal
     *
     * @param  int|null $index For internal use only, the job id
     * @return int      number of active (queued or started) jobs
     */
    public function countActiveJobs($index = null)
    {
        if ($this->runningJobs < $this->maxJobs) {
            foreach ($this->jobs as $job) {
                if ($job['status'] === self::STATUS_QUEUED && $this->runningJobs < $this->maxJobs) {
                    $this->startJob($job['id']);
                }
            }
        }

        if ($this->curl) {
            $this->curl->tick();
        }

        if (null !== $index) {
            return $this->jobs[$index]['status'] < self::STATUS_COMPLETED ? 1 : 0;
        }

        $active = 0;
        foreach ($this->jobs as $job) {
            if ($job['status'] < self::STATUS_COMPLETED) {
                $active++;
            } elseif (!$job['sync']) {
                unset($this->jobs[$job['id']]);
            }
        }

        return $active;
    }

    /**
     * @param  int $index Job id
     * @return Response
     */
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

    /**
     * @internal
     *
     * @param  string                                                                                    $url
     * @param  array{warning?: string, info?: string, warning-versions?: string, info-versions?: string} $data
     * @return void
     */
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

            $io->writeError('<'.$type.'>'.ucfirst($type).' from '.Url::sanitize($url).': '.$data[$type].'</'.$type.'>');
        }
    }

    /**
     * @internal
     *
     * @return ?string[]
     */
    public static function getExceptionHints(\Exception $e)
    {
        if (!$e instanceof TransportException) {
            return null;
        }

        if (
            false !== strpos($e->getMessage(), 'Resolving timed out')
            || false !== strpos($e->getMessage(), 'Could not resolve host')
        ) {
            Silencer::suppress();
            $testConnectivity = file_get_contents('https://8.8.8.8', false, stream_context_create(array(
                'ssl' => array('verify_peer' => false),
                'http' => array('follow_location' => false, 'ignore_errors' => true),
            )));
            Silencer::restore();
            if (false !== $testConnectivity) {
                return array(
                    '<error>The following exception probably indicates you have misconfigured DNS resolver(s)</error>',
                );
            }

            return array(
                '<error>The following exception probably indicates you are offline or have misconfigured DNS resolver(s)</error>',
            );
        }

        return null;
    }

    /**
     * @param  Job  $job
     * @return bool
     */
    private function canUseCurl(array $job)
    {
        if (!$this->curl) {
            return false;
        }

        if (!preg_match('{^https?://}i', $job['request']['url'])) {
            return false;
        }

        if (!empty($job['request']['options']['ssl']['allow_self_signed'])) {
            return false;
        }

        return true;
    }

    /**
     * @internal
     * @return bool
     */
    public static function isCurlEnabled()
    {
        return \extension_loaded('curl') && \function_exists('curl_multi_exec') && \function_exists('curl_multi_init');
    }
}
