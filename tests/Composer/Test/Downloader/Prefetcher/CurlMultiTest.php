<?php
namespace Composer\Test\Downloader\Prefetcher;

use Composer\Downloader\Prefetcher\CurlMulti;

class CurlMultiTest extends \PHPUnit_Framework_TestCase
{
    private $iop;
    private $configp;

    protected function setUp()
    {
        $this->iop = $this->prophesize('Composer\IO\IOInterface');
        $this->configp = $configp = $this->prophesize('Composer\Config');
        $configp->get('github-domains')->willReturn(array('github.com'));
        $configp->get('gitlab-domains')->willReturn(array('gitlab.com'));
    }

    public function testRequestSuccess()
    {
        $tmpfile = tmpfile();
        $reqp = $this->prophesize('Composer\Downloader\Prefetcher\CopyRequest');
        $reqp->getCurlOptions()->willReturn(array(
            CURLOPT_URL => 'file://' . __DIR__ . '/test.txt',
            CURLOPT_FILE => $tmpfile,
        ));
        $reqp->getMaskedURL()->willReturn('file://' . __DIR__ . '/test.txt');
        $reqp->makeSuccess()->willReturn(null);
        $requests = array($reqp->reveal());

        $multi = new CurlMulti;
        $multi->setRequests($requests);

        do {
            $multi->setupEventLoop();
            $multi->wait();

            $result = $multi->getFinishedResults();
            $this->assertEquals(1, $result['successCnt']);
            $this->assertEquals(0, $result['failureCnt']);
        } while ($multi->remain());

        rewind($tmpfile);
        $content = stream_get_contents($tmpfile);
        $this->assertEquals(file_get_contents(__DIR__ . '/test.txt'), $content);
    }

    public function testWait()
    {
        $tmpfile = tmpfile();
        $reqp = $this->prophesize('Composer\Downloader\Prefetcher\CopyRequest');
        $reqp->getCurlOptions()->willReturn(array(
            CURLOPT_URL => 'file://uso800.txt',
            CURLOPT_FILE => $tmpfile,
        ));
        $reqp->getMaskedURL()->willReturn('file://uso800.txt');
        $reqp->makeSuccess()->willReturn(null);
        $requests = array($reqp->reveal());

        $multi = new CurlMulti;
        $multi->setRequests($requests);

        do {
            $multi->setupEventLoop();
            $multi->wait();

            $result = $multi->getFinishedResults();
            $this->assertEquals(0, $result['successCnt']);
            $this->assertEquals(1, $result['failureCnt']);
        } while ($multi->remain());

        rewind($tmpfile);
        $content = stream_get_contents($tmpfile);
        $this->assertEmpty($content);
    }
}
