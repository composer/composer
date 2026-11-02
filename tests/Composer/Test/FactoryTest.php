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

namespace Composer\Test;

use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\Util\Filesystem;
use Composer\Util\Platform;

class FactoryTest extends TestCase
{
    /** @var string|null */
    private $dir;

    protected function tearDown()
    {
        Platform::clearEnv('COMPOSER_HOME');
        Platform::clearEnv('COMPOSER_AUTH');
        if (null !== $this->dir) {
            $fs = new Filesystem();
            $fs->removeDirectory($this->dir);
            $this->dir = null;
        }
    }

    /**
     * @group TLS
     */
    public function testDefaultValuesAreAsExpected()
    {
        $ioMock = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();

        $ioMock->expects($this->once())
            ->method("writeError")
            ->with($this->equalTo('<warning>You are running Composer with SSL/TLS protection disabled.</warning>'));

        $config = $this
            ->getMockBuilder('Composer\Config')
            ->getMock();

        $config->method('get')
            ->with($this->equalTo('disable-tls'))
            ->will($this->returnValue(true));

        Factory::createHttpDownloader($ioMock, $config);
    }

    public function testHomeCannotBeSetByComposerJson()
    {
        $this->createProject(array('config' => array('home' => 'custom-home')));
        $io = new BufferIO();

        $factory = new Factory();
        $composer = $factory->createComposer($io, $this->dir.'/composer.json', true, $this->dir, false);

        $this->assertSame($this->dir.'/composer-home', $composer->getConfig()->get('home'));
        $this->assertStringContainsString('Ignoring the "home" config set in '.$this->dir.'/composer.json', $io->getOutput());
    }

    public function testAuthJsonOnlyAppliesAuthConfig()
    {
        $this->createProject(array(), array(
            'http-basic' => array('example.org' => array('username' => 'foo', 'password' => 'bar')),
            'gitlab-domains' => array('gitlab.example.org'),
            'vendor-dir' => 'custom-vendor',
        ));
        $io = new BufferIO();

        $factory = new Factory();
        $config = $factory->createComposer($io, $this->dir.'/composer.json', true, $this->dir, false)->getConfig();

        $this->assertSame(array('example.org' => array('username' => 'foo', 'password' => 'bar')), $config->get('http-basic'));
        $this->assertContains('gitlab.example.org', $config->get('gitlab-domains'));
        $this->assertSame($this->dir.'/vendor', $config->get('vendor-dir'));
        $this->assertStringContainsString('Ignoring the "vendor-dir" config set in '.$this->dir.'/auth.json', $io->getOutput());
    }

    public function testComposerAuthEnvOnlyAppliesAuthConfig()
    {
        $this->createProject();
        Platform::putEnv('COMPOSER_AUTH', (string) json_encode(array('bearer' => array('example.org' => 'token'), 'vendor-dir' => 'custom-vendor')));
        $io = new BufferIO();

        $factory = new Factory();
        $config = $factory->createComposer($io, $this->dir.'/composer.json', true, $this->dir, false)->getConfig();

        $this->assertSame(array('example.org' => 'token'), $config->get('bearer'));
        $this->assertSame($this->dir.'/vendor', $config->get('vendor-dir'));
        $this->assertStringContainsString('Ignoring the "vendor-dir" config set in COMPOSER_AUTH', $io->getOutput());
    }

    /**
     * @param array<string, mixed> $composerJson
     * @param array<string, mixed> $authJson
     * @return void
     */
    private function createProject(array $composerJson = array(), array $authJson = array())
    {
        $this->dir = self::getUniqueTmpDirectory();
        Platform::putEnv('COMPOSER_HOME', $this->dir.'/composer-home');
        file_put_contents($this->dir.'/composer.json', count($composerJson) > 0 ? json_encode($composerJson) : '{}');
        if (count($authJson) > 0) {
            file_put_contents($this->dir.'/auth.json', json_encode($authJson));
        }
    }
}
