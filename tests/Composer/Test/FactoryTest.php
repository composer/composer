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
use Composer\Util\Platform;

class FactoryTest extends TestCase
{
    public function tearDown(): void
    {
        parent::tearDown();
        Platform::clearEnv('COMPOSER');
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
}
