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

namespace Composer\Test\Repository\Vcs;

use Composer\Downloader\TransportException;
use Composer\Repository\Vcs\GitHubDriver;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\Http\Response;
use Composer\Config;

class GitHubDriverTest extends TestCase
{
    private $home;
    private $config;

    public function setUp()
    {
        $this->home = $this->getUniqueTmpDirectory();
        $this->config = new Config();
        $this->config->merge(array(
            'config' => array(
                'home' => $this->home,
            ),
        ));
    }

    public function tearDown()
    {
        $fs = new Filesystem;
        $fs->removeDirectory($this->home);
    }

    public function testPrivateRepository()
    {
        $repoUrl = 'http://github.com/composer/packagist';
        $repoApiUrl = 'https://api.github.com/repos/composer/packagist';
        $repoSshUrl = 'git@github.com:composer/packagist.git';
        $identifier = 'v0.0.0';
        $sha = 'SOMESHA';

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $io->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue(true));

        $httpDownloader = $this->getMockBuilder('Composer\Util\HttpDownloader')
            ->setConstructorArgs(array($io, $this->config))
            ->getMock();

        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $process->expects($this->any())
            ->method('execute')
            ->will($this->returnValue(1));

        $httpDownloader->expects($this->at(0))
            ->method('get')
            ->with($this->equalTo($repoApiUrl))
            ->will($this->throwException(new TransportException('HTTP/1.1 404 Not Found', 404)));

        $io->expects($this->once())
            ->method('askAndHideAnswer')
            ->with($this->equalTo('Token (hidden): '))
            ->will($this->returnValue('sometoken'));

        $io->expects($this->any())
            ->method('setAuthentication')
            ->with($this->equalTo('github.com'), $this->matchesRegularExpression('{sometoken}'), $this->matchesRegularExpression('{x-oauth-basic}'));

        $httpDownloader->expects($this->at(1))
            ->method('get')
            ->with($this->equalTo($url = 'https://api.github.com/'))
            ->will($this->returnValue(new Response(array('url' => $url), 200, array(), '{}')));

        $httpDownloader->expects($this->at(2))
            ->method('get')
            ->with($this->equalTo($url = $repoApiUrl))
            ->will($this->returnValue(new Response(array('url' => $url), 200, array(), '{"master_branch": "test_master", "private": true, "owner": {"login": "composer"}, "name": "packagist"}')));

        $configSource = $this->getMockBuilder('Composer\Config\ConfigSourceInterface')->getMock();
        $authConfigSource = $this->getMockBuilder('Composer\Config\ConfigSourceInterface')->getMock();
        $this->config->setConfigSource($configSource);
        $this->config->setAuthConfigSource($authConfigSource);

        $repoConfig = array(
            'url' => $repoUrl,
        );

        $gitHubDriver = new GitHubDriver($repoConfig, $io, $this->config, $httpDownloader, $process);
        $gitHubDriver->initialize();
        $this->setAttribute($gitHubDriver, 'tags', array($identifier => $sha));

        $this->assertEquals('test_master', $gitHubDriver->getRootIdentifier());

        $dist = $gitHubDriver->getDist($sha);
        $this->assertEquals('zip', $dist['type']);
        $this->assertEquals('https://api.github.com/repos/composer/packagist/zipball/SOMESHA', $dist['url']);
        $this->assertEquals('SOMESHA', $dist['reference']);

        $source = $gitHubDriver->getSource($sha);
        $this->assertEquals('git', $source['type']);
        $this->assertEquals($repoSshUrl, $source['url']);
        $this->assertEquals('SOMESHA', $source['reference']);
    }

    public function testPublicRepository()
    {
        $repoUrl = 'http://github.com/composer/packagist';
        $repoApiUrl = 'https://api.github.com/repos/composer/packagist';
        $identifier = 'v0.0.0';
        $sha = 'SOMESHA';

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $io->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue(true));

        $httpDownloader = $this->getMockBuilder('Composer\Util\HttpDownloader')
            ->setConstructorArgs(array($io, $this->config))
            ->getMock();

        $httpDownloader->expects($this->at(0))
            ->method('get')
            ->with($this->equalTo($repoApiUrl))
            ->will($this->returnValue(new Response(array('url' => $repoApiUrl), 200, array(), '{"master_branch": "test_master", "owner": {"login": "composer"}, "name": "packagist"}')));

        $repoConfig = array(
            'url' => $repoUrl,
        );
        $repoUrl = 'https://github.com/composer/packagist.git';

        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')
            ->disableOriginalConstructor()
            ->getMock();

        $gitHubDriver = new GitHubDriver($repoConfig, $io, $this->config, $httpDownloader, $process);
        $gitHubDriver->initialize();
        $this->setAttribute($gitHubDriver, 'tags', array($identifier => $sha));

        $this->assertEquals('test_master', $gitHubDriver->getRootIdentifier());

        $dist = $gitHubDriver->getDist($sha);
        $this->assertEquals('zip', $dist['type']);
        $this->assertEquals('https://api.github.com/repos/composer/packagist/zipball/SOMESHA', $dist['url']);
        $this->assertEquals($sha, $dist['reference']);

        $source = $gitHubDriver->getSource($sha);
        $this->assertEquals('git', $source['type']);
        $this->assertEquals($repoUrl, $source['url']);
        $this->assertEquals($sha, $source['reference']);
    }

    public function testPublicRepository2()
    {
        $repoUrl = 'http://github.com/composer/packagist';
        $repoApiUrl = 'https://api.github.com/repos/composer/packagist';
        $identifier = 'feature/3.2-foo';
        $sha = 'SOMESHA';

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $io->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue(true));

        $httpDownloader = $this->getMockBuilder('Composer\Util\HttpDownloader')
            ->setConstructorArgs(array($io, $this->config))
            ->getMock();

        $httpDownloader->expects($this->at(0))
            ->method('get')
            ->with($this->equalTo($url = $repoApiUrl))
            ->will($this->returnValue(new Response(array('url' => $url), 200, array(), '{"master_branch": "test_master", "owner": {"login": "composer"}, "name": "packagist"}')));

        $httpDownloader->expects($this->at(1))
            ->method('get')
            ->with($this->equalTo($url = 'https://api.github.com/repos/composer/packagist/contents/composer.json?ref=feature%2F3.2-foo'))
            ->will($this->returnValue(new Response(array('url' => $url), 200, array(), '{"encoding":"base64","content":"'.base64_encode('{"support": {"source": "'.$repoUrl.'" }}').'"}')));

        $httpDownloader->expects($this->at(2))
            ->method('get')
            ->with($this->equalTo($url = 'https://api.github.com/repos/composer/packagist/commits/feature%2F3.2-foo'))
            ->will($this->returnValue(new Response(array('url' => $url), 200, array(), '{"commit": {"committer":{ "date": "2012-09-10"}}}')));

        $httpDownloader->expects($this->at(3))
            ->method('get')
            ->with($this->equalTo($url = 'https://api.github.com/repos/composer/packagist/contents/.github/FUNDING.yml'))
            ->will($this->returnValue(new Response(array('url' => $url), 200, array(), '{"encoding": "base64", "content": "'.base64_encode("custom: https://example.com").'"}')));

        $repoConfig = array(
            'url' => $repoUrl,
        );
        $repoUrl = 'https://github.com/composer/packagist.git';

        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')
            ->disableOriginalConstructor()
            ->getMock();

        $gitHubDriver = new GitHubDriver($repoConfig, $io, $this->config, $httpDownloader, $process);
        $gitHubDriver->initialize();
        $this->setAttribute($gitHubDriver, 'tags', array($identifier => $sha));

        $this->assertEquals('test_master', $gitHubDriver->getRootIdentifier());

        $dist = $gitHubDriver->getDist($sha);
        $this->assertEquals('zip', $dist['type']);
        $this->assertEquals('https://api.github.com/repos/composer/packagist/zipball/SOMESHA', $dist['url']);
        $this->assertEquals($sha, $dist['reference']);

        $source = $gitHubDriver->getSource($sha);
        $this->assertEquals('git', $source['type']);
        $this->assertEquals($repoUrl, $source['url']);
        $this->assertEquals($sha, $source['reference']);

        $data = $gitHubDriver->getComposerInformation($identifier);

        $this->assertArrayNotHasKey('abandoned', $data);
    }

    public function testPublicRepositoryArchived()
    {
        $repoUrl = 'http://github.com/composer/packagist';
        $repoApiUrl = 'https://api.github.com/repos/composer/packagist';
        $identifier = 'v0.0.0';
        $sha = 'SOMESHA';
        $composerJsonUrl = 'https://api.github.com/repos/composer/packagist/contents/composer.json?ref=' . $sha;

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $io->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue(true));

        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')
            ->disableOriginalConstructor()
            ->getMock();

        $httpDownloader = $this->getMockBuilder('Composer\Util\HttpDownloader')
            ->setConstructorArgs(array($io, $this->config))
            ->getMock();

        $httpDownloader->expects($this->at(0))
            ->method('get')
            ->with($this->equalTo($repoApiUrl))
            ->will($this->returnValue(new Response(array('url' => $repoApiUrl), 200, array(), '{"master_branch": "test_master", "owner": {"login": "composer"}, "name": "packagist", "archived": true}')));

        $httpDownloader->expects($this->at(1))
            ->method('get')
            ->with($this->equalTo($composerJsonUrl))
            ->will($this->returnValue(new Response(array('url' => $composerJsonUrl), 200, array(), '{"encoding": "base64", "content": "' . base64_encode('{"name": "composer/packagist"}') . '"}')));

        $httpDownloader->expects($this->at(2))
            ->method('get')
            ->with($this->equalTo($url = 'https://api.github.com/repos/composer/packagist/commits/'.$sha))
            ->will($this->returnValue(new Response(array('url' => $url), 200, array(), '{"commit": {"committer":{ "date": "2012-09-10"}}}')));

        $httpDownloader->expects($this->at(3))
            ->method('get')
            ->with($this->equalTo($url = 'https://api.github.com/repos/composer/packagist/contents/.github/FUNDING.yml'))
            ->will($this->returnValue(new Response(array('url' => $url), 200, array(), '{"encoding": "base64", "content": "'.base64_encode("custom: https://example.com").'"}')));

        $repoConfig = array(
            'url' => $repoUrl,
        );

        $gitHubDriver = new GitHubDriver($repoConfig, $io, $this->config, $httpDownloader, $process);
        $gitHubDriver->initialize();
        $this->setAttribute($gitHubDriver, 'tags', array($identifier => $sha));

        $data = $gitHubDriver->getComposerInformation($sha);

        $this->assertTrue($data['abandoned']);
    }

    public function testPrivateRepositoryNoInteraction()
    {
        $repoUrl = 'http://github.com/composer/packagist';
        $repoApiUrl = 'https://api.github.com/repos/composer/packagist';
        $repoSshUrl = 'git@github.com:composer/packagist.git';
        $identifier = 'v0.0.0';
        $sha = 'SOMESHA';

        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')
            ->disableOriginalConstructor()
            ->getMock();

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $io->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue(false));

        $httpDownloader = $this->getMockBuilder('Composer\Util\HttpDownloader')
            ->setConstructorArgs(array($io, $this->config))
            ->getMock();

        $httpDownloader->expects($this->at(0))
            ->method('get')
            ->with($this->equalTo($repoApiUrl))
            ->will($this->throwException(new TransportException('HTTP/1.1 404 Not Found', 404)));

        // clean local clone if present
        $fs = new Filesystem();
        $fs->removeDirectory(sys_get_temp_dir() . '/composer-test');

        $process->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo('git config github.accesstoken'))
            ->will($this->returnValue(1));

        $process->expects($this->at(1))
            ->method('execute')
            ->with($this->stringContains($repoSshUrl))
            ->will($this->returnValue(0));

        $process->expects($this->at(2))
            ->method('execute')
            ->with($this->stringContains('git show-ref --tags'));

        $process->expects($this->at(3))
            ->method('splitLines')
            ->will($this->returnValue(array($sha.' refs/tags/'.$identifier)));

        $process->expects($this->at(4))
            ->method('execute')
            ->with($this->stringContains('git branch --no-color --no-abbrev -v'));

        $process->expects($this->at(5))
            ->method('splitLines')
            ->will($this->returnValue(array('  test_master     edf93f1fccaebd8764383dc12016d0a1a9672d89 Fix test & behavior')));

        $process->expects($this->at(6))
            ->method('execute')
            ->with($this->stringContains('git branch --no-color'));

        $process->expects($this->at(7))
            ->method('splitLines')
            ->will($this->returnValue(array('* test_master')));

        $repoConfig = array(
            'url' => $repoUrl,
        );

        $gitHubDriver = new GitHubDriver($repoConfig, $io, $this->config, $httpDownloader, $process);
        $gitHubDriver->initialize();

        $this->assertEquals('test_master', $gitHubDriver->getRootIdentifier());

        $dist = $gitHubDriver->getDist($sha);
        $this->assertEquals('zip', $dist['type']);
        $this->assertEquals('https://api.github.com/repos/composer/packagist/zipball/SOMESHA', $dist['url']);
        $this->assertEquals($sha, $dist['reference']);

        $source = $gitHubDriver->getSource($identifier);
        $this->assertEquals('git', $source['type']);
        $this->assertEquals($repoSshUrl, $source['url']);
        $this->assertEquals($identifier, $source['reference']);

        $source = $gitHubDriver->getSource($sha);
        $this->assertEquals('git', $source['type']);
        $this->assertEquals($repoSshUrl, $source['url']);
        $this->assertEquals($sha, $source['reference']);
    }

    protected function setAttribute($object, $attribute, $value)
    {
        $attr = new \ReflectionProperty($object, $attribute);
        $attr->setAccessible(true);
        $attr->setValue($object, $value);
    }
}
