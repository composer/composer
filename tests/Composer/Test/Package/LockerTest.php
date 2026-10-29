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

use Composer\IO\BufferIO;
use Composer\Json\JsonFile;
use Composer\Package\Locker;
use Composer\Package\Package;
use Composer\Plugin\PluginInterface;
use Composer\IO\NullIO;
use Composer\Repository\ComposerRepository;
use Composer\Repository\FilterRepository;
use Composer\Repository\RepositoryManager;
use Composer\Test\Mock\FactoryMock;
use Composer\Test\TestCase;
use Composer\Util\HttpDownloader;

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

        self::assertTrue($locker->isLocked());
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
        self::assertNotNull($repo->findPackage('pkg1', '1.0.0-beta'));
        self::assertNotNull($repo->findPackage('pkg2', '0.1.10'));
    }

    public function testSetLockData(): void
    {
        $json = $this->createJsonFileMock();
        $inst = $this->createInstallationManagerMock();

        $jsonContent = $this->getJsonContent() . '  ';
        $locker = new Locker(new NullIO, $json, $inst, $jsonContent);

        $package1 = self::getPackage('pkg1', '1.0.0-beta');
        $package2 = self::getPackage('pkg2', '0.1.10');

        $contentHash = hash('md5', trim($jsonContent));

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
                'stability-flags' => new \stdClass,
                'platform' => new \stdClass,
                'platform-dev' => new \stdClass,
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
            ->will($this->returnValue(['hash' => hash('md5', $jsonContent)]));

        self::assertTrue($locker->isFresh());
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

        self::assertFalse($locker->isFresh());
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
            ->will($this->returnValue(['hash' => hash('md5', $jsonContent . '  '), 'content-hash' => hash('md5', $jsonContent)]));

        self::assertTrue($locker->isFresh());
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
            ->will($this->returnValue(['content-hash' => hash('md5', $jsonContent)]));

        self::assertTrue($locker->isFresh());
    }

    public function testIsFreshFalseWithContentHash(): void
    {
        $json = $this->createJsonFileMock();
        $inst = $this->createInstallationManagerMock();

        $locker = new Locker(new NullIO, $json, $inst, $this->getJsonContent());

        $differentHash = hash('md5', $this->getJsonContent(['name' => 'test2']));

        $json
            ->expects($this->once())
            ->method('read')
            ->will($this->returnValue(['hash' => $differentHash, 'content-hash' => $differentHash]));

        self::assertFalse($locker->isFresh());
    }

    public function testContentHashIncludesPolicyConfig(): void
    {
        $base = ['name' => 'test', 'config' => ['platform' => ['php' => '8.1.0']]];
        $withPolicy = $base;
        $withPolicy['config']['policy'] = ['cooldown' => ['period' => '7days']];
        $withOtherConfig = $base;
        $withOtherConfig['config']['sort-packages'] = true;

        self::assertNotSame(Locker::getContentHash(JsonFile::encode($base)), Locker::getContentHash(JsonFile::encode($withPolicy)));
        self::assertSame(Locker::getContentHash(JsonFile::encode($base)), Locker::getContentHash(JsonFile::encode($withOtherConfig)));
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&JsonFile
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

    public function testSetLockDataMovesTimestampsToTheEnd(): void
    {
        $json = $this->createJsonFileMock();
        $inst = $this->createInstallationManagerMock();

        $locker = new Locker(new NullIO, $json, $inst, $this->getJsonContent());

        $package = self::getPackage('pkg1', '1.0.0');
        $package->setReleaseDate(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $package->setPublishedDate(new \DateTimeImmutable('2026-01-02T00:00:00+00:00'));

        $written = null;
        $json
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(static function (array $data) use (&$written): void {
                $written = $data;
            });

        $locker->setLockData([$package], [], [], [], [], 'dev', [], false, false, []);

        self::assertNotNull($written);
        self::assertSame(['name', 'version', 'type', 'time', 'published-time'], array_keys($written['packages'][0]));
        self::assertSame('2026-01-02T00:00:00+00:00', $written['packages'][0]['published-time']);
    }

    public function testSetLockDataRemovesRestorableHttpHeaders(): void
    {
        $json = $this->createJsonFileMock();
        $io = new BufferIO();

        $locker = new Locker($io, $json, $this->createInstallationManagerMock(), $this->getJsonContent());
        $locker->setRepositoryManager($this->createRepositoryManagerWithHeaders());

        $packages = [
            self::getPackageWithDist('a/base', 'https://example.org/dists/a.zip', ['http' => ['header' => ['API-TOKEN: base']]]),
            self::getPackageWithDist('b/team', 'https://example.org/team/dists/b.zip', ['http' => ['header' => ['API-TOKEN: team'], 'timeout' => 10], 'ssl' => ['verify_peer' => false]]),
            self::getPackageWithDist('c/other-header', 'https://example.org/dists/c.zip', ['http' => ['header' => ['API-TOKEN: other']]]),
            self::getPackageWithDist('d/inline', 'https://inline.example.net/d.zip', ['http' => ['header' => ['API-TOKEN: inline']]]),
        ];

        $written = null;
        $json
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(static function (array $data) use (&$written): void {
                $written = $data;
            });

        $locker->setLockData($packages, [], [], [], [], 'dev', [], false, false, []);

        self::assertNotNull($written);
        $transportOptions = [];
        foreach ($written['packages'] as $package) {
            $transportOptions[$package['name']] = $package['transport-options'] ?? null;
        }
        self::assertSame([
            'a/base' => null,
            'b/team' => ['http' => ['timeout' => 10], 'ssl' => ['verify_peer' => false]],
            'c/other-header' => ['http' => ['header' => ['API-TOKEN: other']]],
            'd/inline' => ['http' => ['header' => ['API-TOKEN: inline']]],
        ], $transportOptions);
        self::assertStringContainsString('The transport options of c/other-header, d/inline contain http headers which will be stored in composer.lock', $io->getOutput());
        // the packages themselves keep their options for the install that follows
        self::assertSame(['http' => ['header' => ['API-TOKEN: base']]], $packages[0]->getTransportOptions());
    }

    public function testGetLockedRepositoryRestoresHttpHeaders(): void
    {
        $json = $this->createJsonFileMock();

        $locker = new Locker(new NullIO, $json, $this->createInstallationManagerMock(), $this->getJsonContent());
        $locker->setRepositoryManager($this->createRepositoryManagerWithHeaders());

        $ssl = ['ssl' => ['verify_peer' => false]];
        $json->method('exists')->willReturn(true);
        $json->method('read')->willReturn([
            'packages' => [
                ['name' => 'a/base', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://example.org/dists/a.zip']],
                ['name' => 'b/team', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://example.org/team/dists/b.zip'], 'transport-options' => $ssl],
                ['name' => 'c/stored-header', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://example.org/dists/c.zip'], 'transport-options' => ['http' => ['header' => ['API-TOKEN: stored']]]],
                ['name' => 'd/no-header-repo', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://no-header.example.org/dists/d.zip'], 'transport-options' => $ssl],
                ['name' => 'e/elsewhere', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://elsewhere.example.net/e.zip']],
            ],
        ]);

        $transportOptions = [];
        foreach ($locker->getLockedRepository()->getPackages() as $package) {
            $transportOptions[$package->getName()] = $package->getTransportOptions();
        }
        self::assertSame([
            'a/base' => ['http' => ['header' => ['API-TOKEN: base']]],
            'b/team' => $ssl + ['http' => ['header' => ['API-TOKEN: team']]],
            'c/stored-header' => ['http' => ['header' => ['API-TOKEN: stored']]],
            'd/no-header-repo' => $ssl,
            'e/elsewhere' => [],
        ], $transportOptions);
    }

    private function createRepositoryManagerWithHeaders(): RepositoryManager
    {
        $io = new NullIO;
        $config = FactoryMock::createConfig();
        $httpDownloader = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();

        $rm = new RepositoryManager($io, $config, $httpDownloader);
        $rm->addRepository(new ComposerRepository(['url' => 'https://example.org', 'options' => ['http' => ['header' => ['API-TOKEN: base']]]], $io, $config, $httpDownloader));
        // added after its parent URL so the longest match has to win, and filtered as only/exclude/canonical would do
        $rm->addRepository(new FilterRepository(
            new ComposerRepository(['url' => 'https://example.org/team', 'options' => ['http' => ['header' => ['API-TOKEN: team']]]], $io, $config, $httpDownloader),
            ['canonical' => false]
        ));
        $rm->addRepository(new ComposerRepository(['url' => 'https://no-header.example.org'], $io, $config, $httpDownloader));

        return $rm;
    }

    /**
     * @param mixed[] $transportOptions
     */
    private static function getPackageWithDist(string $name, string $distUrl, array $transportOptions): Package
    {
        $package = new Package($name, '1.0.0.0', '1.0.0');
        $package->setDistType('zip');
        $package->setDistUrl($distUrl);
        $package->setTransportOptions($transportOptions);

        return $package;
    }
}
