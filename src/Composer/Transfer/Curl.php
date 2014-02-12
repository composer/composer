<?php

namespace Composer\Transfer;

use Composer\IO\IOInterface;

class Curl implements TransferInterface
{
    /** @var array */
    protected $headers;

    /** @var int */
    protected $errorCode = 0;

    /**
     * @param string      $fileUrl
     * @param array       $options
     * @param IOInterface $io
     * @param bool        $progress
     * @param string      $userAgent
     *
     * @return string
     */
    public function download($fileUrl, $options, $io, $progress, $userAgent)
    {
        if (strpos($fileUrl, '//')===false) {
            $result = file_get_contents($fileUrl);
        } else {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $fileUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // TODO: Finish functionality
            if (false && $progress) {
                $progress = function ($downloadSize, $downloaded) use ($io) {
                    $percent =  $downloadSize ? round($downloaded / $downloadSize  * 100, 2) . '%' : 'progress...';

                    $io->overwrite("    Downloading: <comment>$percent</comment>");
                };

                curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, $progress);
                curl_setopt($ch, CURLOPT_NOPROGRESS, false);
                curl_setopt($ch, CURLOPT_BUFFERSIZE, 128);
            }

            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 20);
            curl_setopt($ch, CURLOPT_HEADER, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
            $response = curl_exec($ch);

            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $this->headers = explode("\r\n", substr($response, 0, $headerSize - 4));
            $result = substr($response, $headerSize);

            $this->errorCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);
        }

        return $result;
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
        return $this->errorCode;
    }
}