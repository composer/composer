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

use Composer\Composer;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Downloader\TransportException;
use React\Stream\Stream;
use React\Promise\Deferred;
use React\Promise\FulfilledPromise;

/**
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Nils Adermann <naderman@naderman.de>
 */
class RemoteFilesystem
{
    private $io;
    private $config;
    private $bytesMax;
    private $originUrl;
    private $fileUrl;
    private $fileName;
    private $retry;
    private $progress;
    private $lastProgress;
    private $options;
    private $retryAuthFailure;
    private $lastHeaders;
    private $storeAuth;

    /**
     * Constructor.
     *
     * @param IOInterface $io      The IO instance
     * @param Config      $config  The config
     * @param array       $options The options
     */
    public function __construct(IOInterface $io, Config $config = null, array $options = array())
    {
        $this->io = $io;
        $this->config = $config;
        $this->options = $options;
    }

    /**
     * Copy the remote file in local.
     *
     * @param string  $originUrl The origin URL
     * @param string  $fileUrl   The file URL
     * @param string  $fileName  the local filename
     * @param boolean $progress  Display the progression
     * @param array   $options   Additional context options
     *
     * @return bool true
     */
    public function copy($originUrl, $fileUrl, $fileName, $progress = true, $options = array(), $loop = null)
    {
        return $this->get($originUrl, $fileUrl, $options, $fileName, $progress, $loop);
    }

    /**
     * Get the content.
     *
     * @param string  $originUrl The origin URL
     * @param string  $fileUrl   The file URL
     * @param boolean $progress  Display the progression
     * @param array   $options   Additional context options
     *
     * @return bool|string The content
     */
    public function getContents($originUrl, $fileUrl, $progress = true, $options = array())
    {
        return $this->get($originUrl, $fileUrl, $options, null, $progress);
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
     * Returns the headers of the last request
     *
     * @return array
     */
    public function getLastHeaders()
    {
        return $this->lastHeaders;
    }

    /**
     * Get file content or copy action.
     *
     * @param string  $originUrl         The origin URL
     * @param string  $fileUrl           The file URL
     * @param array   $additionalOptions context options
     * @param string  $fileName          the local filename
     * @param boolean $progress          Display the progression
     *
     * @throws TransportException|\Exception
     * @throws TransportException            When the file could not be downloaded
     *
     * @return bool|string
     */
    protected function get($originUrl, $fileUrl, $additionalOptions = array(), $fileName = null, $progress = true, $loop = null)
    {
        if (strpos($originUrl, '.github.com') === (strlen($originUrl) - 11)) {
            $originUrl = 'github.com';
        }

        $that = clone $this;
        $that->bytesMax = 0;
        $that->originUrl = $originUrl;
        $that->fileUrl = $fileUrl;
        $that->fileName = $fileName;
        $that->progress = $progress;
        $that->lastProgress = null;
        $that->retryAuthFailure = true;

        $this->lastHeaders = array();

        // capture username/password from URL if there is one
        if (preg_match('{^https?://(.+):(.+)@([^/]+)}i', $fileUrl, $match)) {
            $that->io->setAuthentication($originUrl, urldecode($match[1]), urldecode($match[2]));
        }

        if (isset($additionalOptions['retry-auth-failure'])) {
            $that->retryAuthFailure = (bool) $additionalOptions['retry-auth-failure'];

            unset($additionalOptions['retry-auth-failure']);
        }

        $options = $that->getOptionsForUrl($originUrl, $additionalOptions);

        if ($that->io->isDebug()) {
            $that->io->writeError((substr($fileUrl, 0, 4) === 'http' ? 'Downloading ' : 'Reading ') . $fileUrl);
        }
        if (isset($options['github-token'])) {
            $fileUrl .= (false === strpos($fileUrl, '?') ? '?' : '&') . 'access_token='.$options['github-token'];
            unset($options['github-token']);
        }
        if (isset($options['http'])) {
            $options['http']['ignore_errors'] = true;
        }
        $ctx = StreamContextFactory::getContext($fileUrl, $options, array('notification' => array($that, 'callbackGet')));

        if ($that->progress) {
            $that->io->writeError("    Downloading: <comment>Connecting...</comment>", false);
        }

        $errorMessage = '';
        $errorCode = 0;
        $handle = false;
        set_error_handler(function ($code, $msg) use (&$errorMessage) {
            if ($errorMessage) {
                $errorMessage .= "\n";
            }
            $errorMessage .= preg_replace('{^file_get_contents\(.*?\): }', '', $msg);
        });
        try {
            $handle = fopen($fileUrl, 'rb', false, $ctx);
        } catch (\Exception $e) {
            // Re-thrown below
        }
        if ($errorMessage && !ini_get('allow_url_fopen')) {
            $errorMessage = 'allow_url_fopen must be enabled in php.ini ('.$errorMessage.')';
        }
        restore_error_handler();
        if (isset($e)) {
            if (!$that->retry) {
                if ($e instanceof TransportException && !empty($http_response_header[0])) {
                    $e->setHeaders($http_response_header);
                }
                if ($e instanceof TransportException && $handle !== false) {
                    $e->setResponse(stream_get_contents($handle));
                }
                throw $e;
            }
            $handle = false;
        }

        // fail 4xx and 5xx responses and capture the response
        if (!empty($http_response_header[0]) && preg_match('{^HTTP/\S+ ([45]\d\d)}i', $http_response_header[0], $match)) {
            $errorCode = $match[1];
            if (!$that->retry) {
                $e = new TransportException('The "'.$that->fileUrl.'" file could not be downloaded ('.$http_response_header[0].')', $errorCode);
                $e->setHeaders($http_response_header);
                $e->setResponse(stream_get_contents($handle));

                throw $e;
            }
            $handle = false;
        }

        if ($that->retry) {
            $that->retry = false;

            $result = $that->get($that->originUrl, $that->fileUrl, $additionalOptions, $that->fileName, $that->progress, $loop);

            $authHelper = new AuthHelper($that->io, $that->config);
            $authHelper->storeAuth($that->originUrl, $that->storeAuth);
            $that->storeAuth = false;

            return $result;
        }

        if (false === $handle) {
            $e = new TransportException('The "'.$that->fileUrl.'" file could not be downloaded: '.$errorMessage, $errorCode);
            if (!empty($http_response_header[0])) {
                $e->setHeaders($http_response_header);
            }

            throw $e;
        }

        if (!empty($http_response_header[0])) {
            $this->lastHeaders = $http_response_header;
        }

        $result = (object) array(
            'headers' => isset($http_response_header) ? $http_response_header : array(),
            'body' => '',
        );

        if ($loop) {
            $deferred = new Deferred();
            $stream = new Stream($handle, $loop);

            $stream->on('data', function ($data) use ($result) {
                $result->body .= $data;
            });
            $stream->on('error', function (\Exception $e) use ($deferred) {
                $deferred->reject($e);
            });
            $stream->on('close', function () use ($deferred, $result) {
                $deferred->resolve($result);
            });

            $promise = $deferred->promise();
        } else {
            $result->body = stream_get_contents($handle);
            fclose($handle);

            $promise = new FulfilledPromise($result);
        }

        // decode gzip
        if (extension_loaded('zlib') && substr($fileUrl, 0, 4) === 'http') {
            $promise = $promise->then(function ($result) {
                $decode = false;
                foreach ($result->headers as $header) {
                    if (preg_match('{^content-encoding: *gzip *$}i', $header)) {
                        $decode = true;
                        continue;
                    } elseif (preg_match('{^HTTP/}i', $header)) {
                        $decode = false;
                    }
                }

                if ($decode) {
                    if (PHP_VERSION_ID >= 50400) {
                        $result->body = zlib_decode($result->body);
                    } else {
                        // work around issue with gzuncompress & co that do not work with all gzip checksums
                        $result->body = file_get_contents('compress.zlib://data:application/octet-stream;base64,'.base64_encode($result->body));
                    }

                    if (!$result->body) {
                        throw new TransportException('Failed to decode zlib stream');
                    }
                }

                return $result;
            });
        }

        if ($progress) {
            $io = $that->io;
            $promise = $promise->then(function ($result) use ($io) {
                $io->overwriteError("    Downloading: <comment>100%</comment>");

                return $result;
            });
        }

        // handle copy command if download was successful
        if (null !== $fileName) {
            $fileUrl = $that->fileUrl;
            $promise = $promise->then(function ($result) use ($fileUrl, $fileName) {
                if ('' === $result->body) {
                    throw new TransportException('"'.$fileUrl.'" appears broken, and returned an empty 200 response');
                }

                $errorMessage = '';
                set_error_handler(function ($code, $msg) use (&$errorMessage) {
                    if ($errorMessage) {
                        $errorMessage .= "\n";
                    }
                    $errorMessage .= preg_replace('{^file_put_contents\(.*?\): }', '', $msg);
                });
                $result->body = (bool) file_put_contents($fileName, $result->body);
                restore_error_handler();
                if (false === $result->body) {
                    throw new TransportException('The "'.$fileUrl.'" file could not be written to '.$fileName.': '.$errorMessage);
                }

                return $result;
            });
        };

        if ($loop) {
            return $promise;
        }

        $promise->then(null, function (\Exception $e) use ($result) {$result->error = $e;});

        if (isset($result->error)) {
            throw $result->error;
        }

        return $result->body;
    }

    /**
     * Get notification action.
     *
     * @param  integer            $notificationCode The notification code
     * @param  integer            $severity         The severity level
     * @param  string             $message          The message
     * @param  integer            $messageCode      The message code
     * @param  integer            $bytesTransferred The loaded size
     * @param  integer            $bytesMax         The total size
     * @throws TransportException
     */
    public function callbackGet($notificationCode, $severity, $message, $messageCode, $bytesTransferred, $bytesMax)
    {
        switch ($notificationCode) {
            case STREAM_NOTIFY_FAILURE:
            case STREAM_NOTIFY_AUTH_REQUIRED:
                if (401 === $messageCode) {
                    // Bail if the caller is going to handle authentication failures itself.
                    if (!$this->retryAuthFailure) {
                        break;
                    }

                    $this->promptAuthAndRetry($messageCode);
                    break;
                }
                break;

            case STREAM_NOTIFY_AUTH_RESULT:
                if (403 === $messageCode) {
                    // Bail if the caller is going to handle authentication failures itself.
                    if (!$this->retryAuthFailure) {
                        break;
                    }

                    $this->promptAuthAndRetry($messageCode, $message);
                    break;
                }
                break;

            case STREAM_NOTIFY_FILE_SIZE_IS:
                if ($this->bytesMax < $bytesMax) {
                    $this->bytesMax = $bytesMax;
                }
                break;

            case STREAM_NOTIFY_PROGRESS:
                if ($this->bytesMax > 0 && $this->progress) {
                    $progression = 0;

                    if ($this->bytesMax > 0) {
                        $progression = round($bytesTransferred / $this->bytesMax * 100);
                    }

                    if ((0 === $progression % 5) && 100 !== $progression && $progression !== $this->lastProgress) {
                        $this->lastProgress = $progression;
                        $this->io->overwriteError("    Downloading: <comment>$progression%</comment>", false);
                    }
                }
                break;

            default:
                break;
        }
    }

    protected function promptAuthAndRetry($httpStatus, $reason = null)
    {
        if ($this->config && in_array($this->originUrl, $this->config->get('github-domains'), true)) {
            $message = "\n".'Could not fetch '.$this->fileUrl.', please create a GitHub OAuth token '.($httpStatus === 404 ? 'to access private repos' : 'to go over the API rate limit');
            $gitHubUtil = new GitHub($this->io, $this->config, null);
            if (!$gitHubUtil->authorizeOAuth($this->originUrl)
                && (!$this->io->isInteractive() || !$gitHubUtil->authorizeOAuthInteractively($this->originUrl, $message))
            ) {
                throw new TransportException('Could not authenticate against '.$this->originUrl, 401);
            }
        } else {
            // 404s are only handled for github
            if ($httpStatus === 404) {
                return;
            }

            // fail if the console is not interactive
            if (!$this->io->isInteractive()) {
                if ($httpStatus === 401) {
                    $message = "The '" . $this->fileUrl . "' URL required authentication.\nYou must be using the interactive console to authenticate";
                }
                if ($httpStatus === 403) {
                    $message = "The '" . $this->fileUrl . "' URL could not be accessed: " . $reason;
                }

                throw new TransportException($message, $httpStatus);
            }
            // fail if we already have auth
            if ($this->io->hasAuthentication($this->originUrl)) {
                throw new TransportException("Invalid credentials for '" . $this->fileUrl . "', aborting.", $httpStatus);
            }

            $this->io->overwriteError('    Authentication required (<info>'.parse_url($this->fileUrl, PHP_URL_HOST).'</info>):');
            $username = $this->io->ask('      Username: ');
            $password = $this->io->askAndHideAnswer('      Password: ');
            $this->io->setAuthentication($this->originUrl, $username, $password);
            $this->storeAuth = $this->config->get('store-auths');
        }

        $this->retry = true;
        throw new TransportException('RETRY');
    }

    protected function getOptionsForUrl($originUrl, $additionalOptions)
    {
        if (defined('HHVM_VERSION')) {
            $phpVersion = 'HHVM ' . HHVM_VERSION;
        } else {
            $phpVersion = 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
        }

        $headers = array(
            sprintf(
                'User-Agent: Composer/%s (%s; %s; %s)',
                Composer::VERSION === '@package_version@' ? 'source' : Composer::VERSION,
                php_uname('s'),
                php_uname('r'),
                $phpVersion
            )
        );

        if (extension_loaded('zlib')) {
            $headers[] = 'Accept-Encoding: gzip';
        }

        $options = array_replace_recursive($this->options, $additionalOptions);
        $options['http']['protocol_version'] = 1.1;
        $headers[] = 'Connection: close';

        if ($this->io->hasAuthentication($originUrl)) {
            $auth = $this->io->getAuthentication($originUrl);
            if ('github.com' === $originUrl && 'x-oauth-basic' === $auth['password']) {
                $options['github-token'] = $auth['username'];
            } else {
                $authStr = base64_encode($auth['username'] . ':' . $auth['password']);
                $headers[] = 'Authorization: Basic '.$authStr;
            }
        }

        if (isset($options['http']['header']) && !is_array($options['http']['header'])) {
            $options['http']['header'] = explode("\r\n", trim($options['http']['header'], "\r\n"));
        }
        foreach ($headers as $header) {
            $options['http']['header'][] = $header;
        }

        return $options;
    }
}
