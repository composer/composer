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

namespace Composer\Test\Util\Mocks;

use Composer\IO\IOInterface;
use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\Util\HttpDownloader;
use Composer\Util\ProcessExecutor;
use PHPUnit\Framework\ExpectationFailedException;
use RuntimeException;

/**
 * @author Michael Chekin <mchekin@gmail.com>
 */
class GitHubMock
{
    static private $authorizeOathResponse;
    static private $authorizeOathInteractivelyResponse;
    static private $rateLimitResponse;
    static private $isRateLimitedResponse;

    /**
     * Constructor.
     *
     * @param IOInterface $io The IO instance
     * @param Config $config The composer configuration
     * @param ProcessExecutor $process Process instance, injectable for mocking
     * @param HttpDownloader $httpDownloader Remote Filesystem, injectable for mocking
     */
    public function __construct(IOInterface $io, Config $config, ProcessExecutor $process = null, HttpDownloader $httpDownloader = null)
    {

    }

    /**
     * @param bool $authorizeOathResponse
     */
    public static function setAuthorizeOathResponse($authorizeOathResponse)
    {
        self::$authorizeOathResponse = $authorizeOathResponse;
    }

    /**
     * @param bool $authorizeOathInteractivelyResponse
     */
    public static function setAuthorizeOathInteractivelyResponse($authorizeOathInteractivelyResponse)
    {
        self::$authorizeOathInteractivelyResponse = $authorizeOathInteractivelyResponse;
    }

    /**
     * @param array $rateLimitResponse
     */
    public static function setRateLimitResponse($rateLimitResponse)
    {
        self::$rateLimitResponse = $rateLimitResponse;
    }

    /**
     * @param bool $isRateLimitedResponse
     */
    public static function setIsRateLimitedResponse($isRateLimitedResponse)
    {
        self::$isRateLimitedResponse = $isRateLimitedResponse;
    }

    /**
     * Attempts to authorize a GitHub domain via OAuth
     *
     * @param  string $originUrl The host this GitHub instance is located at
     * @return bool   true on success
     */
    public function authorizeOAuth($originUrl)
    {
        if (self::$authorizeOathResponse === null) {
            throw new ExpectationFailedException('No expectations set for ' . __METHOD__ . ' call response.');
        }

        return self::$authorizeOathResponse;
    }

    /**
     * Authorizes a GitHub domain interactively via OAuth
     *
     * @param  string $originUrl The host this GitHub instance is located at
     * @param  string $message The reason this authorization is required
     * @throws RuntimeException
     * @throws TransportException|\Exception
     * @return bool                          true on success
     */
    public function authorizeOAuthInteractively($originUrl, $message = null)
    {
        if (self::$authorizeOathInteractivelyResponse === null) {
            throw new ExpectationFailedException('No expectations set for ' . __METHOD__ . ' call response.');
        }

        return self::$authorizeOathInteractivelyResponse;
    }

    /**
     * Extract ratelimit from response.
     *
     * @param array $headers Headers from Composer\Downloader\TransportException.
     *
     * @return array Associative array with the keys limit and reset.
     */
    public function getRateLimit(array $headers)
    {
        if (self::$rateLimitResponse === null) {
            throw new ExpectationFailedException('No expectations set for ' . __METHOD__ . ' call response.');
        }

        return self::$rateLimitResponse;
    }

    /**
     * Finds whether a request failed due to rate limiting
     *
     * @param array $headers Headers from Composer\Downloader\TransportException.
     *
     * @return bool
     */
    public function isRateLimited(array $headers)
    {
        if (self::$isRateLimitedResponse === null) {
            throw new ExpectationFailedException('No expectations set for ' . __METHOD__ . ' call response.');
        }

        return self::$isRateLimitedResponse;
    }
}
