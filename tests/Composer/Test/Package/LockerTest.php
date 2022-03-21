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

namespace Composer\Test\Package;

use Composer\Json\JsonFile;
use Composer\Package\Locker;
use Composer\Plugin\PluginInterface;
use Composer\IO\NullIO;
use Composer\Test\TestCase;

class LockerTest extends TestCase
{
    public function testIsLocked(): void
    {
        $json = $this->createJsonFileMock();
        $locker = new Locker(
            new NullIO,
            $json,
            $this->createInstallationManagerMock(),
            $this->getJsonContent()
        );

        $json
            ->expects($this->any())
            ->method('exists')
            ->will($this->returnValue(true));
        $json
            ->expects($this->any())
            ->method('read')
            ->will($this->returnValue(['packages' => []]));

        $this->assertTrue($locker->isLocked());
    }

    public function testGetNotLockedPackages(): void
    {
        $json = $this->createJsonFileMock();
        $inst = $this->createInstallationManagerMock();

        $locker = new Locker(new NullIO, $json, $inst, $this->getJsonContent());

        $json
            ->expects($this->once())
            ->method('exists')
            ->will($this->returnValue(false));

        self::expectException('LogicException');

        $locker->getLockedRepository();
    }

    public function testGetLockedPackages(): void
    {
        $json = $this->createJsonFileMock();
        $inst = $this->createInstallationManagerMock();

        $locker = new Locker(new NullIO, $json, $inst, $this->getJsonContent());

        $json
            ->expects($this->once())
            ->method('exists')
            ->will($this->returnValue(true));
        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue([
                'packages' => [
                    ['name' => 'pkg1', 'version' => '1.0.0-beta'],
                    ['name' => 'pkg2', 'version' => '0.1.10'],
                ],
            ]));

        $repo = $locker->getLockedRepository();
        $this->assertNotNull($repo->findPackage('pkg1', '1.0.0-beta'));
        $this->assertNotNull($repo->findPackage('pkg2', '0.1.10'));
    }

    public function testSetLockData(): void
    {
        $json = $this->createJsonFileMock();
        $inst = $this->createInstallationManagerMock();

        $jsonContent = $this->getJsonContent() . '  ';
        $locker = new Locker(new NullIO, $json, $inst, $jsonContent);

        $package1 = $this->getPackage('pkg1', '1.0.0-beta');
        $package2 = $this->getPackage('pkg2', '0.1.10');

        $contentHash = md5(trim($jsonContent));

        $json
            ->expects($this->once())
            ->method('write')
            ->with([
                '_readme' => ['This file locks the dependencies of your project to a known state',
                                   'Read more about it at https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies',
                                   'This file is @gener'.'ated automatically', ],
                'content-hash' => $contentHash,
                'packages' => [
                    ['name' => 'pkg1', 'version' => '1.0.0-beta', 'type' => 'library'],
                    ['name' => 'pkg2', 'version' => '0.1.10', 'type' => 'library'],
                ],
                'packages-dev' => [],
                'aliases' => [],
                'minimum-stability' => 'dev',
                'stability-flags' => [],
                'platform' => [],
                'platform-dev' => [],
                'platform-overrides' => ['foo/bar' => '1.0'],
                'prefer-stable' => false,
                'prefer-lowest' => false,
                'plugin-api-version' => PluginInterface::PLUGIN_API_VERSION,
            ]);

        $locker->setLockData([$package1, $package2], [], [], [], [], 'dev', [], false, false, ['foo/bar' => '1.0']);
    }

    public function testLockBadPackages(): void
    {
        $json = $this->createJsonFileMock();
        $inst = $this->createInstallationManagerMock();

        $locker = new Locker(new NullIO, $json, $inst, $this->getJsonContent());

        $package1 = $this->createPackageMock();
        $package1
            ->expects($this->once())
            ->method('getPrettyName')
            ->will($this->returnValue('pkg1'));

        self::expectException('LogicException');

        $locker->setLockData([$package1], [], [], [], [], 'dev', [], false, false, []);
    }

    public function testIsFresh(): void
    {
        $json = $this->createJsonFileMock();
        $inst = $this->createInstallationManagerMock();

        $jsonContent = $this->getJsonContent();
        $locker = new Locker(new NullIO, $json, $inst, $jsonContent);

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(['hash' => md5($jsonContent)]));

        $this->assertTrue($locker->isFresh());
    }

    public function testIsFreshFalse(): void
    {
        $json = $this->createJsonFileMock();
        $inst = $this->createInstallationManagerMock();

        $locker = new Locker(new NullIO, $json, $inst, $this->getJsonContent());

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(['hash' => $this->getJsonContent(['name' => 'test2'])]));

        $this->assertFalse($locker->isFresh());
    }

    public function testIsFreshWithContentHash(): void
    {
        $json = $this->createJsonFileMock();
        $inst = $this->createInstallationManagerMock();

        $jsonContent = $this->getJsonContent();
        $locker = new Locker(new NullIO, $json, $inst, $jsonContent);

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(['hash' => md5($jsonContent . '  '), 'content-hash' => md5($jsonContent)]));

        $this->assertTrue($locker->isFresh());
    }

    public function testIsFreshWithContentHashAndNoHash(): void
    {
        $json = $this->createJsonFileMock();
        $inst = $this->createInstallationManagerMock();

        $jsonContent = $this->getJsonContent();
        $locker = new Locker(new NullIO, $json, $inst, $jsonContent);

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(['content-hash' => md5($jsonContent)]));

        $this->assertTrue($locker->isFresh());
    }

    public function testIsFreshFalseWithContentHash(): void
    {
        $json = $this->createJsonFileMock();
        $inst = $this->createInstallationManagerMock();

        $locker = new Locker(new NullIO, $json, $inst, $this->getJsonContent());

        $differentHash = md5($this->getJsonContent(['name' => 'test2']));

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(['hash' => $differentHash, 'content-hash' => $differentHash]));

        $this->assertFalse($locker->isFresh());
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\Json\JsonFile
     */
    private function createJsonFileMock()
    {
        return $this->getMockBuilder('Composer\Json\JsonFile')
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\Installer\InstallationManager
     */
    private function createInstallationManagerMock()
    {
        $mock = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->disableOriginalConstructor()
            ->getMock();

        return $mock;
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\Package\PackageInterface
     */
    private function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
    }

    /**
     * @param array<string, string> $customData
     */
    private function getJsonContent(array $customData = []): string
    {
        $data = array_merge([
            'minimum-stability' => 'beta',
            'name' => 'test',
        ], $customData);

        ksort($data);

        return JsonFile::encode($data, 0);
    }
}
