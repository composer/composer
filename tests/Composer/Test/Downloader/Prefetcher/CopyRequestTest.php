<?php
namespace Composer\Test\Downloader\Prefetcher;

use Composer\Downloader\Prefetcher\CopyRequest;
use Prophecy\Argument as arg;

class CopyRequestTest extends \PHPUnit_Framework_TestCase
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

    public function testConstruct()
    {
        $tmpfile = tempnam(sys_get_temp_dir(), 'composer_unit_test_');
        $example = 'http://user:pass@example.com:80/p/a/t/h?a=b';

        $this->iop->setAuthentication('example.com', 'user', 'pass')
            ->will(function($args, $iop){
                $iop->getAuthentication($args[0])
                    ->willReturn(array('username' => $args[1], 'password' => $args[2]));
            })
            ->shouldBeCalled();
        $this->iop->hasAuthentication(arg::type('string'))
            ->willReturn(false);

        $req = new CopyRequest($example, $tmpfile, false, $this->iop->reveal(), $this->configp->reveal());
        $this->assertEquals($example, $req->getURL());
    }

    public function testDirectoryExists()
    {
        $rand = sha1(mt_rand());
        $tmpbase = sys_get_temp_dir();
        $tmpdir = $tmpbase . DIRECTORY_SEPARATOR . $rand;
        mkdir($tmpdir);

        try {
            // if is_dir(destination) then throws exception
            $req = new CopyRequest('http://example.com/', $tmpdir, false, $this->iop->reveal(), $this->configp->reveal());
            rmdir($tmpdir);
            $this->fail('expectedException: \Composer\Downloader\Prefetcher\FetchException');
        } catch (\Exception $e) {
            rmdir($tmpdir);
            $this->assertInstanceOf('Composer\Downloader\Prefetcher\FetchException', $e);
            $this->assertContains('Directory exists', $e->getMessage());
        }
    }

    public function testDestruct()
    {
        $tmpfile = tempnam(sys_get_temp_dir(), 'composer_unit_test_');

        $req = new CopyRequest('http://example.com/', $tmpfile, false, $this->iop->reveal(), $this->configp->reveal());
        $this->assertFileExists($tmpfile);

        // if $req->success === true ...
        $req->makeSuccess();
        unset($req);

        // then tmpfile remain
        $this->assertFileExists($tmpfile);
        unlink($tmpfile);

        $req = new CopyRequest('http://example.com/', $tmpfile, false, $this->iop->reveal(), $this->configp->reveal());
        // if $req->success === false (default) ...
        // $req->makeSuccess();
        unset($req);

        // then cleaned tmpfile automatically
        $this->assertFileNotExists($tmpfile);
    }

    public function testGetMaskedURL()
    {
        $tmpfile = tempnam(sys_get_temp_dir(), 'composer_unit_test_');

        $req = new CopyRequest('http://user:pass@example.com/p/a/t/h?token=opensesame', $tmpfile, false, $this->iop->reveal(), $this->configp->reveal());
        // user/pass/query masked
        $this->assertEquals('http://example.com/p/a/t/h', $req->getMaskedURL());
    }

    public function testGitHubRedirector()
    {
        $tmpfile = tempnam(sys_get_temp_dir(), 'composer_unit_test_');
        $example = 'https://api.github.com/repos/vendor/name/zipball/aaaa?a=b';

        $this->iop->hasAuthentication('github.com')->willReturn(true);
        $this->iop->getAuthentication('github.com')->willReturn(array('username' => 'at', 'password' => 'x-oauth-basic'));

        // user:pass -> query
        $req = new CopyRequest($example, $tmpfile, false, $this->iop->reveal(), $this->configp->reveal());
        $this->assertEquals("$example&access_token=at", $req->getURL());

        // api.github.com -> codeload.github.com
        $req = new CopyRequest($example, $tmpfile, true, $this->iop->reveal(), $this->configp->reveal());
        $this->assertEquals('https://codeload.github.com/vendor/name/legacy.zip/aaaa?a=b&access_token=at', $req->getURL());
    }

    public function testGitLab()
    {
        $tmpfile = tempnam(sys_get_temp_dir(), 'composer_unit_test_');
        $example = 'https://gitlab.com/p/a/t/h';

        $this->iop->hasAuthentication('gitlab.com')->willReturn(true);
        $this->iop->getAuthentication('gitlab.com')->willReturn(array('username' => 'at', 'password' => 'oauth2'));

        $req = new CopyRequest($example, $tmpfile, false, $this->iop->reveal(), $this->configp->reveal());
        $opts = $req->getCurlOptions();
        $this->assertContains('Authorization: Bearer at', $opts[CURLOPT_HTTPHEADER]);
    }

    public function testProxy()
    {
        $serverBackup = $_SERVER;

        $tmpfile = tempnam(sys_get_temp_dir(), 'composer_unit_test_');

        $_SERVER['no_proxy'] = 'example.com';
        $_SERVER['HTTP_PROXY'] = 'http://example.com:8080';
        $req = new CopyRequest('http://localhost', $tmpfile, false, $this->iop->reveal(), $this->configp->reveal());
        $this->assertArrayHasKey(CURLOPT_PROXY, $req->getCurlOptions());

        $req = new CopyRequest('http://example.com', $tmpfile, false, $this->iop->reveal(), $this->configp->reveal());
        $this->assertArrayNotHasKey(CURLOPT_PROXY, $req->getCurlOptions());

        $_SERVER = $serverBackup;
    }
}
