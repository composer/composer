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
use Composer\Util\Filesystem;
use Composer\Config;

class GitHubDriverTest extends \PHPUnit_Framework_TestCase
{
    private $config;

    public function setUp()
    {
        $this->config = new Config();
        $this->config->merge(array(
            'config' => array(
                'home' => sys_get_temp_dir() . '/composer-test',
            ),
        ));
    }

    public function tearDown()
    {
        $fs = new Filesystem;
        $fs->removeDirectory(sys_get_temp_dir() . '/composer-test');
    }

    public function testPrivateRepository()
    {
        $repoUrl = 'http://github.com/composer/packagist';
        $repoApiUrl = 'https://api.github.com/repos/composer/packagist';
        $repoSshUrl = 'git@github.com:composer/packagist.git';
        $identifier = 'v0.0.0';
        $sha = 'SOMESHA';

        $io = $this->getMock('Composer\IO\IOInterface');
        $io->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue(true));

        $remoteFilesystem = $this->getMockBuilder('Composer\Util\RemoteFilesystem')
            ->setConstructorArgs(array($io))
            ->getMock();

        $process = $this->getMock('Composer\Util\ProcessExecutor');
        $process->expects($this->any())
            ->method('execute')
            ->will($this->returnValue(1));

        $remoteFilesystem->expects($this->at(0))
            ->method('getContents')
            ->with($this->equalTo('github.com'), $this->equalTo($repoApiUrl), $this->equalTo(false))
            ->will($this->throwException(new TransportException('HTTP/1.1 404 Not Found', 404)));

        $io->expects($this->once())
            ->method('ask')
            ->with($this->equalTo('Username: '))
            ->will($this->returnValue('someuser'));

        $io->expects($this->once())
            ->method('askAndHideAnswer')
            ->with($this->equalTo('Password: '))
            ->will($this->returnValue('somepassword'));

        $io->expects($this->any())
            ->method('setAuthentication')
            ->with($this->equalTo('github.com'), $this->matchesRegularExpression('{someuser|abcdef}'), $this->matchesRegularExpression('{somepassword|x-oauth-basic}'));

        $remoteFilesystem->expects($this->at(1))
            ->method('getContents')
            ->with($this->equalTo('github.com'), $this->equalTo('https://api.github.com/authorizations'), $this->equalTo(false))
            ->will($this->returnValue('{"token": "abcdef"}'));

        $remoteFilesystem->expects($this->at(2))
            ->method('getContents')
            ->with($this->equalTo('github.com'), $this->equalTo($repoApiUrl), $this->equalTo(false))
            ->will($this->returnValue('{"master_branch": "test_master", "private": true}'));

        $configSource = $this->getMock('Composer\Config\ConfigSourceInterface');
        $this->config->setConfigSource($configSource);

        $repoConfig = array(
            'url' => $repoUrl,
        );

        $gitHubDriver = new GitHubDriver($repoConfig, $io, $this->config, $process, $remoteFilesystem);
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

        $io = $this->getMock('Composer\IO\IOInterface');
        $io->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue(true));

        $remoteFilesystem = $this->getMockBuilder('Composer\Util\RemoteFilesystem')
            ->setConstructorArgs(array($io))
            ->getMock();

        $remoteFilesystem->expects($this->at(0))
            ->method('getContents')
            ->with($this->equalTo('github.com'), $this->equalTo($repoApiUrl), $this->equalTo(false))
            ->will($this->returnValue('{"master_branch": "test_master"}'));

        $repoConfig = array(
            'url' => $repoUrl,
        );
        $repoUrl = 'https://github.com/composer/packagist.git';

        $gitHubDriver = new GitHubDriver($repoConfig, $io, $this->config, null, $remoteFilesystem);
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

        $io = $this->getMock('Composer\IO\IOInterface');
        $io->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue(true));

        $remoteFilesystem = $this->getMockBuilder('Composer\Util\RemoteFilesystem')
            ->setConstructorArgs(array($io))
            ->getMock();

        $remoteFilesystem->expects($this->at(0))
            ->method('getContents')
            ->with($this->equalTo('github.com'), $this->equalTo($repoApiUrl), $this->equalTo(false))
            ->will($this->returnValue('{"master_branch": "test_master"}'));

        $remoteFilesystem->expects($this->at(1))
            ->method('getContents')
            ->with($this->equalTo('github.com'), $this->equalTo('https://api.github.com/repos/composer/packagist/contents/composer.json?ref=feature%2F3.2-foo'), $this->equalTo(false))
            ->will($this->returnValue('{"encoding":"base64","content":"'.base64_encode('{"support": {"source": "'.$repoUrl.'" }}').'"}'));

        $remoteFilesystem->expects($this->at(2))
            ->method('getContents')
            ->with($this->equalTo('github.com'), $this->equalTo('https://api.github.com/repos/composer/packagist/commits/feature%2F3.2-foo'), $this->equalTo(false))
            ->will($this->returnValue('{"commit": {"committer":{ "date": "2012-09-10"}}}'));

        $repoConfig = array(
            'url' => $repoUrl,
        );
        $repoUrl = 'https://github.com/composer/packagist.git';

        $gitHubDriver = new GitHubDriver($repoConfig, $io, $this->config, null, $remoteFilesystem);
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

        $gitHubDriver->getComposerInformation($identifier);
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

        $io = $this->getMock('Composer\IO\IOInterface');
        $io->expects($this->any())
            ->method('isInteractive')
            ->will($this->returnValue(false));

        $remoteFilesystem = $this->getMockBuilder('Composer\Util\RemoteFilesystem')
            ->setConstructorArgs(array($io))
            ->getMock();

        $remoteFilesystem->expects($this->at(0))
            ->method('getContents')
            ->with($this->equalTo('github.com'), $this->equalTo($repoApiUrl), $this->equalTo(false))
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

        $gitHubDriver = new GitHubDriver($repoConfig, $io, $this->config, $process, $remoteFilesystem);
        $gitHubDriver->initialize();

        $this->assertEquals('test_master', $gitHubDriver->getRootIdentifier());

        // Dist is not available for GitDriver
        $dist = $gitHubDriver->getDist($identifier);
        $this->assertNull($dist);

        $source = $gitHubDriver->getSource($identifier);
        $this->assertEquals('git', $source['type']);
        $this->assertEquals($repoSshUrl, $source['url']);
        $this->assertEquals($identifier, $source['reference']);

        // Dist is not available for GitDriver
        $dist = $gitHubDriver->getDist($sha);
        $this->assertNull($dist);

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
