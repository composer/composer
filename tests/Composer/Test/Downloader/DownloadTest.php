<?php

namespace Composer\Test\Downloader;

use Composer\Downloader\Download;
use Composer\Util\DownloadUnitsFormatter;

class DownloadTest extends \PHPUnit_Framework_TestCase
{
    public function testStart() {
        // Arrange
        $download = new Download(new DownloadUnitsFormatter());
        $sizeInBytes = 100;

        // Act
        $download->start($sizeInBytes);

        // Assert
        $this->assertEquals($sizeInBytes, $download->getFileSizeInBytes());
        $this->assertNotNull($download->getStartMicroTime());
    }

    public function testStop() {
        // Arrange
        $download = new Download(new DownloadUnitsFormatter());

        // Act
        $download->stop();

        // Assert
        $this->assertFalse($download->isStarted());
        $this->assertNotNull($download->getFinishMicroTime());
    }

    public function testFail() {
        // Arrange
        $download = new Download(new DownloadUnitsFormatter());
        $errorCode = 100;
        $errorMessage = "KO";

        // Act
        $download->fail($errorCode, $errorMessage);

        // Assert
        $this->assertTrue($download->isFailed());
        $this->assertEquals($errorCode, $download->getFailureCode());
        $this->assertEquals($errorMessage, $download->getFailureMessage());
    }

    public function testIsStarted() {
        // Arrange
        $download = new Download(new DownloadUnitsFormatter());

        // Act and Assert
        $this->assertFalse($download->isStarted());
        $download->start(100);
        $this->assertTrue($download->isStarted());
    }

    public function testProgress() {
        // Arrange
        $download = new Download(new DownloadUnitsFormatter());

        // Act
        $download->start(100);
        $download->progress(10);

        // Assert
        $this->assertEquals(10, $download->getTotalBytesTransferredAmount());
    }

    public function testGetSpeedInBytesPerSecondProvider() {
        return array(
            array(0, 0, 0),
            array(10, 0.25, 40),
            array(25, 0.5, 50),
            array(20, 2, 10),
            array(50, 0.1, 500),
            array(30, 3, 10)
        );
    }

    /**
     * @param $transferred
     * @param $time
     * @param $expectedSpeed
     * @dataProvider testGetSpeedInBytesPerSecondProvider
     */
    public function testGetSpeedInBytesPerSecond($transferred, $time, $expectedSpeed) {
        // Arrange
        $download = new Download(new DownloadUnitsFormatter());
        
        // Act
        $download->start(100, 0);
        $download->progress($transferred, $time);
        $speed = $download->getSpeedInBytesPerSecond();

        // Assert
        $this->assertEquals($expectedSpeed, $speed);
    }

    public function testGetETAInSecondsProvider() {
        return array(
            array(100, 10, 0.25, 2),
            array(100, 25, 0.5, 2),
            array(100, 20, 2, 8),
            array(100, 50, 0.1, 0),
            array(100, 30, 10, 23)
        );
    }

    /**
     * @param $sizeInBytes
     * @param $transferred
     * @param $time
     * @param $eta
     * @dataProvider testGetETAInSecondsProvider
     */
    public function testGetETAInSeconds($sizeInBytes, $transferred, $time, $eta) {
        // Arrange
        $download = new Download(new DownloadUnitsFormatter());

        // Act
        $download->start($sizeInBytes, 0);
        $download->progress($transferred, $time);

        // Assert
        $this->assertEquals($eta, $download->getETAInSeconds());
    }

    public function testGetProgressPercentageProvider() {
        return array(
            array(100, 50, 50),
            array(100, 20, 20),
            array(250, 50, 20),
            array(120, 60, 50),
            array(100, 2, 2),
            array(200, 2, 1)
        );
    }

    /**
     * @param $sizeInBytes
     * @param $transferred
     * @param $expectedPercentage
     * @dataProvider testGetProgressPercentageProvider
     */
    public function testGetProgressPercentage($sizeInBytes, $transferred, $expectedPercentage) {
        // Arrange
        $download = new Download(new DownloadUnitsFormatter());

        // Act
        $download->start($sizeInBytes, 0);
        $download->progress($transferred);
        $percentage = $download->getProgressPercentage();

        // Assert
        $this->assertEquals($expectedPercentage, $percentage);
    }

    public function testGetSizeInBytes() {
        // Arrange
        $download = new Download(new DownloadUnitsFormatter());
        $size = 100;

        // Act
        $download->start($size);

        // Assert
        $this->assertEquals($size, $download->getFileSizeInBytes());
    }
}
