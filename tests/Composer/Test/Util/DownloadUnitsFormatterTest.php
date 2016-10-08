<?php

namespace Composer\Test\Util;

use Composer\Util\DownloadUnitsFormatter;

class DownloadUnitsFormatterTest extends \PHPUnit_Framework_TestCase
{
    public function testBytesPerSecondToProperUnitsProvider() {
        return array(
            array(658, '658.00B/s'),
            array(6, '6.00B/s'),
            array(1024, '1.00KB/s'),
            array(1040, '1.02KB/s'),
            array(2048, '2.00KB/s'),
            array(1048576, '1.00MB/s'),
            array(1232323, '1.18MB/s'),
            array(1073741824, '1.00GB/s'),
            array(1231243423, '1.15GB/s'),
            array(12323235233, '11.48GB/s')
        );
    }

    /**
     * @param int $bytes
     * @param string $expectedOutput
     * @dataProvider testBytesPerSecondToProperUnitsProvider
     */
    public function testBytesPerSecondToProperUnits($bytes, $expectedOutput) {
        // Arrange
        $formatter = new DownloadUnitsFormatter();

        // Act
        $output = $formatter->bytesPerSecondToProperUnits($bytes);

        // Assert
        $this->assertEquals($expectedOutput, $output);
    }

    public function testSecondsToProperUnitsProvider() {
        return array(
            array(0, '0h 0m 0s'),
            array(-1, 'unknown time'),
            array(5400, '1h 30m 0s'),
            array(100, '0h 1m 40s'),
            array(5705, '1h 35m 5s'),
            array(6600, '1h 50m 0s')
        );
    }

    /**
     * @param int $seconds
     * @param string $expectedOutput
     * @dataProvider testSecondsToProperUnitsProvider
     */
    public function testSecondsToProperUnits($seconds, $expectedOutput) {
        // Arrange
        $formatter = new DownloadUnitsFormatter();

        // Act
        $output = $formatter->secondsToProperUnits($seconds);

        // Assert
        $this->assertEquals($expectedOutput, $output);
    }
}
