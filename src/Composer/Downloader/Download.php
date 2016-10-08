<?php namespace Composer\Downloader;

use Composer\Util\DownloadUnitsFormatter;

class Download
{
    /** @var int */
    private $startMicroTime = null;

    /** @var int */
    private $finishMicroTime = null;

    /** @var int */
    private $sizeInBytes = null;

    /** @var array */
    private $progresses = array();

    /** @var boolean */
    private $failed = false;

    /** @var int */
    private $failureCode = null;

    /** @var string */
    private $failureMessage = null;
    /**
     * @var DownloadUnitsFormatter
     */
    private $unitsTransformer;

    /**
     * Download constructor.
     * @param DownloadUnitsFormatter $unitsTransformer
     */
    public function __construct(DownloadUnitsFormatter $unitsTransformer)
    {
        $this->unitsTransformer = $unitsTransformer;
    }

    /**
     * @param int $sizeInBytes
     * @param $time
     */
    public function start($sizeInBytes, $time = null) {
        $this->sizeInBytes = $sizeInBytes;

        if(is_null($time)) {
            $time = microtime(true);
        }

        $this->startMicroTime = $time;
        $this->progresses[] = array('time' => $time, 'progress' => 0);
    }

    /**
     * @param $time
     */
    public function stop($time = null) {
        if(is_null($time)) {
            $time = microtime(true);
        }
        $this->finishMicroTime = $time;
    }

    /**
     * @return int
     */
    public function getStartMicroTime() {
        return $this->startMicroTime;
    }

    /**
     * @return int
     */
    public function getFinishMicroTime() {
        return $this->finishMicroTime;
    }

    /**
     * @return boolean
     */
    public function isFailed()
    {
        return $this->failed;
    }

    /**
     * @param boolean $failed
     */
    public function setFailed($failed)
    {
        $this->failed = $failed;
    }

    /**
     * @return int
     */
    public function getFailureCode()
    {
        return $this->failureCode;
    }

    /**
     * @param int $failureCode
     */
    public function setFailureCode($failureCode)
    {
        $this->failureCode = $failureCode;
    }

    /**
     * @return string
     */
    public function getFailureMessage()
    {
        return $this->failureMessage;
    }

    /**
     * @param string $failureMessage
     */
    public function setFailureMessage($failureMessage)
    {
        $this->failureMessage = $failureMessage;
    }

    /**
     * @return int
     */
    public function getTotalBytesTransferredAmount()
    {
        $totalBytesTransferred = 0;

        if(count($this->progresses) > 0) {
            $totalBytesTransferred = $this->progresses[count($this->progresses)-1]['progress'];
        }

        return $totalBytesTransferred;
    }

    /**
     * @param int $failureCode
     * @param string $failureMessage
     */
    public function fail($failureCode, $failureMessage) {
        $this->failed = true;
        $this->failureCode = $failureCode;
        $this->failureMessage = $failureMessage;
    }

    /**
     * @return bool
     */
    public function isStarted() {
        return !is_null($this->startMicroTime);
    }

    /**
     * @param int $bytesTransferred
     * @param null $time
     */
    public function progress($bytesTransferred, $time = null) {
        if(is_null($time)) {
            $time = microtime(true);
        }

        $this->progresses[] = array('time' => $time, 'progress' => $bytesTransferred);
    }

    /**
     * Returns number of bytes transferred in the last second
     *
     * @return int
     */
    public function getSpeedInBytesPerSecond() {
        $speed = 0;

        $nProgresses = count($this->progresses);

        if($nProgresses >= 1) {
            $elapsedTime = $this->progresses[$nProgresses - 1]['time'] - $this->progresses[$nProgresses - 2]['time'];
            $bytes = $this->progresses[$nProgresses - 1]['progress'] - $this->progresses[$nProgresses - 2]['progress'];

            if($elapsedTime > 0) {
                $speed = $bytes / $elapsedTime;
            }
        }

        return $speed;
    }

    /**
     * Returns estimated seconds to finish. Returns -1 for infinite times (not progress yet)
     * @return int
     */
    public function getETAInSeconds() {
        $eta = -1;

        $nProgresses = count($this->progresses);

        if($nProgresses >= 2) {
            $pendingBytes = $this->sizeInBytes - $this->progresses[$nProgresses - 1]['progress'];

            $speed = $this->getSpeedInBytesPerSecond();

            $eta = round($pendingBytes / $speed);
        }

        return $eta;
    }

    /**
     * @return int
     */
    public function getProgressPercentage() {
        $nProgresses = count($this->progresses);

        $sizeInBytes = $this->sizeInBytes;
        $transferred = $this->progresses[$nProgresses - 1]['progress'];

        return round($transferred / $sizeInBytes * 100);
    }

    /**
     * Returns speed in b|Kb|Mb|Gb/s, for example 2.3Mb/s
     *
     * @return string
     */
    public function getSpeedFormatted() {
        return $this->unitsTransformer->bytesPerSecondToProperUnits($this->getSpeedInBytesPerSecond());
    }

    /**
     * Returns ETA (Estimated Time to Arrive) rounded in s|m|h, for example 5m
     *
     * @return string
     */
    public function getETAFormatted() {
        return $this->unitsTransformer->secondsToProperUnits($this->getETAInSeconds());
    }

    /**
     * @return int
     */
    public function getFileSizeInBytes() {
        return $this->sizeInBytes;
    }
}