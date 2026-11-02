<?php declare(strict_types=1);

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
use Composer\Util\Platform;

class FactoryTest extends TestCase
{
    public function tearDown(): void
    {
        parent::tearDown();
        Platform::clearEnv('COMPOSER');
        Platform::clearEnv('COMPOSER_AUTH');
    }

    /**
     * @group TLS
     */
    public function testDefaultValuesAreAsExpected(): void
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

    public function testGetComposerJsonPath(): void
    {
        self::assertSame('./composer.json', Factory::getComposerFile());
    }

    public function testGetComposerJsonPathFailsIfDir(): void
    {
        Platform::putEnv('COMPOSER', __DIR__);
        self::expectException('RuntimeException');
        self::expectExceptionMessage('The COMPOSER environment variable is set to '.__DIR__.' which is a directory, this variable should point to a composer.json or be left unset.');
        Factory::getComposerFile();
    }

    public function testGetComposerJsonPathFromEnv(): void
    {
        Platform::putEnv('COMPOSER', ' foo.json ');
        self::assertSame('foo.json', Factory::getComposerFile());
    }

    public function testHomeCannotBeSetByComposerJson(): void
    {
        $dir = $this->initTempComposer(['config' => ['home' => 'custom-home']]);
        $io = new BufferIO();

        $composer = (new Factory())->createComposer($io, null, true, null, false);

        self::assertSame($dir.'/composer-home', $composer->getConfig()->get('home'));
        self::assertStringContainsString('Ignoring the "home" config set in ./composer.json', $io->getOutput());
    }

    public function testAuthJsonOnlyAppliesAuthConfig(): void
    {
        $dir = $this->initTempComposer([], [
            'http-basic' => ['example.org' => ['username' => 'foo', 'password' => 'bar']],
            'gitlab-domains' => ['gitlab.example.org'],
            'vendor-dir' => 'custom-vendor',
        ]);
        $io = new BufferIO();

        $config = (new Factory())->createComposer($io, null, true, null, false)->getConfig();

        self::assertSame(['example.org' => ['username' => 'foo', 'password' => 'bar']], $config->get('http-basic'));
        self::assertContains('gitlab.example.org', $config->get('gitlab-domains'));
        self::assertSame($dir.'/vendor', $config->get('vendor-dir'));
        self::assertStringContainsString('Ignoring the "vendor-dir" config set in '.$dir.'/auth.json', $io->getOutput());
    }

    public function testComposerAuthEnvOnlyAppliesAuthConfig(): void
    {
        $dir = $this->initTempComposer();
        Platform::putEnv('COMPOSER_AUTH', (string) json_encode(['bearer' => ['example.org' => 'token'], 'vendor-dir' => 'custom-vendor']));
        $io = new BufferIO();

        $config = (new Factory())->createComposer($io, null, true, null, false)->getConfig();

        self::assertSame(['example.org' => 'token'], $config->get('bearer'));
        self::assertSame($dir.'/vendor', $config->get('vendor-dir'));
        self::assertStringContainsString('Ignoring the "vendor-dir" config set in COMPOSER_AUTH', $io->getOutput());
    }
}
