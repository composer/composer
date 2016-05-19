<?php namespace Composer\Util;

class DownloadUnitsFormatter
{
    /**
     * @param int $bytes
     * @return string
     */
    public function bytesPerSecondToProperUnits($bytes) {
        $units = array('B/s', 'KB/s', 'MB/s', 'GB/s');

        $output = $bytes;

        $transformed = 0;
        while($output >= 1024 && $transformed < count($units) - 1) {
            $transformed++;
            $output = $output / 1024;
        }

        return number_format($output, 2) . $units[$transformed];
    }

    /**
     * @param int $seconds
     * @return string
     */
    public function secondsToProperUnits($seconds) {
        $output = 'unknown time';
        if($seconds >= 0) {
            $hours = floor($seconds / 60 / 60);
            $minutes = floor(($seconds - ($hours * 60 * 60)) / 60);
            $seconds = floor(($seconds - ($hours * 60 * 60) - ($minutes * 60)));

            $output = $hours . 'h ' . $minutes . 'm ' . $seconds . 's';
        }

        return $output;
    }
}