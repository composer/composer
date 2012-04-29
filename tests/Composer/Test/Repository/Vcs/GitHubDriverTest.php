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

/**
 * @author Beau Simensen <beau@dflydev.com>
 */
class GitHubDriverTest extends \PHPUnit_Framework_TestCase
{
    public function testPrivateRepository()
    {
        $scheme = extension_loaded('openssl') ? 'https' : 'http';

        $repoUrl = 'http://github.com/composer/packagist';
        $repoApiUrl = $scheme.'://api.github.com/repos/composer/packagist';
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

        $remoteFilesystem->expects($this->at(0))
            ->method('getContents')
            ->with($this->equalTo($repoUrl), $this->equalTo($repoApiUrl), $this->equalTo(false))
            ->will($this->throwException(new TransportException('HTTP/1.1 404 Not Found', 404)));

        $io->expects($this->once())
            ->method('ask')
            ->with($this->equalTo('Username: '))
            ->will($this->returnValue('someuser'));

        $io->expects($this->once())
            ->method('askAndHideAnswer')
            ->with($this->equalTo('Password: '))
            ->will($this->returnValue('somepassword'));

        $io->expects($this->once())
            ->method('setAuthorization')
            ->with($this->equalTo($repoUrl), 'someuser', 'somepassword');

        $remoteFilesystem->expects($this->at(1))
            ->method('getContents')
            ->with($this->equalTo($repoUrl), $this->equalTo($repoApiUrl), $this->equalTo(false))
            ->will($this->returnValue('{"master_branch": "test_master"}'));

        $gitHubDriver = new GitHubDriver($repoUrl, $io, new Config(), null, $remoteFilesystem);
        $gitHubDriver->initialize();
        $this->setAttribute($gitHubDriver, 'tags', array($identifier => $sha));

        $this->assertEquals('test_master', $gitHubDriver->getRootIdentifier());

        $dist = $gitHubDriver->getDist($identifier);
        $this->assertEquals('zip', $dist['type']);
        $this->assertEquals($scheme.'://github.com/composer/packagist/zipball/v0.0.0', $dist['url']);
        $this->assertEquals('v0.0.0', $dist['reference']);

        $source = $gitHubDriver->getSource($identifier);
        $this->assertEquals('git', $source['type']);
        $this->assertEquals($repoSshUrl, $source['url']);
        $this->assertEquals('v0.0.0', $source['reference']);

        $dist = $gitHubDriver->getDist($sha);
        $this->assertEquals('zip', $dist['type']);
        $this->assertEquals($scheme.'://github.com/composer/packagist/zipball/v0.0.0', $dist['url']);
        $this->assertEquals('v0.0.0', $dist['reference']);

        $source = $gitHubDriver->getSource($sha);
        $this->assertEquals('git', $source['type']);
        $this->assertEquals($repoSshUrl, $source['url']);
        $this->assertEquals('v0.0.0', $source['reference']);
    }

    public function testPublicRepository()
    {
        $scheme = extension_loaded('openssl') ? 'https' : 'http';

        $repoUrl = 'http://github.com/composer/packagist';
        $repoApiUrl = $scheme.'://api.github.com/repos/composer/packagist';
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
            ->with($this->equalTo($repoUrl), $this->equalTo($repoApiUrl), $this->equalTo(false))
            ->will($this->returnValue('{"master_branch": "test_master"}'));

        $gitHubDriver = new GitHubDriver($repoUrl, $io, new Config(), null, $remoteFilesystem);
        $gitHubDriver->initialize();
        $this->setAttribute($gitHubDriver, 'tags', array($identifier => $sha));

        $this->assertEquals('test_master', $gitHubDriver->getRootIdentifier());

        $dist = $gitHubDriver->getDist($identifier);
        $this->assertEquals('zip', $dist['type']);
        $this->assertEquals($scheme.'://github.com/composer/packagist/zipball/v0.0.0', $dist['url']);
        $this->assertEquals($identifier, $dist['reference']);

        $source = $gitHubDriver->getSource($identifier);
        $this->assertEquals('git', $source['type']);
        $this->assertEquals($repoUrl, $source['url']);
        $this->assertEquals($identifier, $source['reference']);

        $dist = $gitHubDriver->getDist($sha);
        $this->assertEquals('zip', $dist['type']);
        $this->assertEquals($scheme.'://github.com/composer/packagist/zipball/v0.0.0', $dist['url']);
        $this->assertEquals($identifier, $dist['reference']);

        $source = $gitHubDriver->getSource($sha);
        $this->assertEquals('git', $source['type']);
        $this->assertEquals($repoUrl, $source['url']);
        $this->assertEquals($identifier, $source['reference']);
    }

    public function testPrivateRepositoryNoInteraction()
    {
        $scheme = extension_loaded('openssl') ? 'https' : 'http';

        $repoUrl = 'http://github.com/composer/packagist';
        $repoApiUrl = $scheme.'://api.github.com/repos/composer/packagist';
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
            ->with($this->equalTo($repoUrl), $this->equalTo($repoApiUrl), $this->equalTo(false))
            ->will($this->throwException(new TransportException('HTTP/1.1 404 Not Found', 404)));

        // clean local clone if present
        $fs = new Filesystem();
        $fs->removeDirectory(sys_get_temp_dir() . '/composer-test');

        $config = new Config();
        $config->merge(array(
            'config' => array(
                'home' => sys_get_temp_dir() . '/composer-test',
            ),
        ));

        $process->expects($this->at(0))
            ->method('execute')
            ->with($this->stringContains($repoSshUrl))
            ->will($this->returnValue(0));

        $process->expects($this->at(1))
            ->method('execute')
            ->with($this->stringContains('git tag'));

        $process->expects($this->at(2))
            ->method('splitLines')
            ->will($this->returnValue(array($identifier)));

        $process->expects($this->at(3))
            ->method('execute')
            ->with($this->stringContains('git branch --no-color --no-abbrev -v'));

        $process->expects($this->at(4))
            ->method('splitLines')
            ->will($this->returnValue(array('  test_master     edf93f1fccaebd8764383dc12016d0a1a9672d89 Fix test & behavior')));

        $process->expects($this->at(5))
            ->method('execute')
            ->with($this->stringContains('git branch --no-color'));

        $process->expects($this->at(6))
            ->method('splitLines')
            ->will($this->returnValue(array('* test_master')));

        $gitHubDriver = new GitHubDriver($repoUrl, $io, $config, $process, $remoteFilesystem);
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
