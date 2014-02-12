<?php

namespace Composer\Transfer;

use Composer\IO\IOInterface;

class Curl implements TransferInterface {

    /**
     * @param string      $fileUrl
     * @param IOInterface $io
     * @param bool        $progress
     * @param string      $userAgent
     *
     * @return array
     */
    public function download($fileUrl, $io, $progress, $userAgent)
    {
        $result = [
            'content' => '',
            'headers' => []
        ];

        if (strpos($fileUrl, '//')===false) {
            $result['content'] = file_get_contents($fileUrl);
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
            }

            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 20);
            curl_setopt($ch, CURLOPT_HEADER, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
            $response = curl_exec($ch);

            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $result['headers'] = explode("\r\n", substr($response, 0, $headerSize - 4));
            $result['content'] = substr($response, $headerSize);

            curl_close($ch);
        }

        return $result;
    }
}