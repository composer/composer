<?php

namespace Composer\Transfer;

use Composer\IO\IOInterface;
use Composer\Util\StreamContextFactory;

class StreamContext implements TransferInterface
{
    /** @var array */
    protected $headers;

    /** @var int */
    protected $errorCode = 0;

    /** @var array */
    protected $defaultParams = array();

    /**
     * @param array $defaultParams
     */
    public function setDefaultParams(array $defaultParams)
    {
        $this->defaultParams = $defaultParams;
    }

    /**
     * @param string      $fileUrl
     * @param array       $options
     * @param IOInterface $io
     * @param bool        $progress
     * @param string      $userAgent
     *
     * @return array
     */
    public function download($fileUrl, $options, $io, $progress, $userAgent)
    {
        $ctx = StreamContextFactory::getContext($fileUrl, $options, $this->defaultParams);

        $result = file_get_contents($fileUrl, false, $ctx);

        if (isset($http_response_header)) {
            $this->headers = $http_response_header;
        }

        return $this->getErrorCode()===0 ? $result : false;
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * @return array
     */
    public function getErrorCode()
    {
        // fix for 5.4.0 https://bugs.php.net/bug.php?id=61336
        if (!empty($this->headers[0]) && preg_match('{^HTTP/\S+ ([45]\d\d)}i', $this->headers[0], $match)) {
            $this->errorCode = $match[1];
        }

        return $this->errorCode;
    }
}