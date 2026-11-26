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

use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\Package\LinkedPackages;
use Composer\Repository\RepositoryFactory;
use Composer\Test\TestCase;

class LinkedPackagesTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = self::getUniqueTmpDirectory();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::removeTestDirectory($this->dir);
    }

    public function testLinkAndUnlink(): void
    {
        $foo = $this->createPackageDir('acme/foo');
        $bar = $this->createPackageDir('acme/bar');

        $linkedPackages = new LinkedPackages($this->dir.'/home');
        $linkedPackages->link('acme/foo', $foo, '1.x-dev');
        $linkedPackages->link('acme/bar', $bar);

        self::assertSame($this->dir.'/home/linked-packages.json', $linkedPackages->getFile());
        self::assertSame([
            'packages' => [
                'acme/foo' => ['url' => $foo, 'version' => '1.x-dev'],
                'acme/bar' => ['url' => $bar],
            ],
        ], (new JsonFile($linkedPackages->getFile()))->read());

        $linkedPackages->link('acme/bar', $bar, '2.0.x-dev');
        self::assertTrue($linkedPackages->unlink('acme/foo'));
        self::assertFalse($linkedPackages->unlink('acme/foo'));
        self::assertSame(['acme/bar' => ['url' => $bar, 'version' => '2.0.x-dev']], $linkedPackages->getLinks());
    }

    public function testLinkRejectsInvalidVersion(): void
    {
        $linkedPackages = new LinkedPackages($this->dir);

        self::expectException('UnexpectedValueException');
        $linkedPackages->link('acme/foo', $this->createPackageDir('acme/foo'), 'foo');
    }

    public function testGetLinksFiltersByPattern(): void
    {
        $linkedPackages = new LinkedPackages($this->dir);
        $linkedPackages->link('acme/foo', $this->createPackageDir('acme/foo'));
        $linkedPackages->link('acme/bar', $this->createPackageDir('acme/bar'));
        $linkedPackages->link('other/baz', $this->createPackageDir('other/baz'));

        self::assertSame(['acme/foo', 'acme/bar'], array_keys($linkedPackages->getLinks(['acme/*'])));
    }

    public function testCreateRepositories(): void
    {
        $extra = ['branch-alias' => ['dev-main' => '2.x-dev']];
        $foo = $this->createPackageDir('acme/foo', ['version' => 'dev-main', 'extra' => $extra]);
        $bar = $this->createPackageDir('acme/bar', ['version' => 'dev-main', 'extra' => $extra]);

        $linkedPackages = new LinkedPackages($this->dir);
        $linkedPackages->link('acme/foo', $foo);
        $linkedPackages->link('acme/bar', $bar, '1.x-dev');

        $config = $this->getConfig(['linked-packages' => ['symlink' => false]]);
        $rm = RepositoryFactory::manager(new NullIO(), $config, $this->getHttpDownloaderMock());
        $repositories = $linkedPackages->createRepositories($rm, $config, null);

        self::assertCount(2, $repositories);

        $versions = array_map(static function ($package): string {
            return $package->getPrettyVersion();
        }, $repositories[0]->getPackages());
        self::assertSame(['2.x-dev', 'dev-main'], $versions);

        $packages = $repositories[1]->getPackages();
        self::assertCount(1, $packages);
        self::assertSame('acme/bar', $packages[0]->getName());
        self::assertSame('1.x-dev', $packages[0]->getPrettyVersion());
        self::assertSame(['symlink' => false, 'relative' => false], $packages[0]->getTransportOptions());
    }

    /**
     * @param array<string, mixed> $composerJson
     */
    private function createPackageDir(string $name, array $composerJson = []): string
    {
        $path = $this->dir.'/packages/'.$name;
        mkdir($path, 0777, true);
        file_put_contents($path.'/composer.json', JsonFile::encode(['name' => $name] + $composerJson));

        return (string) realpath($path);
    }
}
