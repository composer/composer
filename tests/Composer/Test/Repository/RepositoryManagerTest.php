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

namespace Composer\Test\Repository;

use Composer\Repository\RepositoryManager;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Config;

class RepositoryManagerTest extends TestCase
{
    /** @var string */
    protected $tmpdir;

    public function setUp(): void
    {
        $this->tmpdir = self::getUniqueTmpDirectory();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tmpdir)) {
            $fs = new Filesystem();
            $fs->removeDirectory($this->tmpdir);
        }
    }

    public function testPrepend(): void
    {
        $rm = new RepositoryManager(
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            new Config,
            $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock()
        );

        $repository1 = $this->getMockBuilder('Composer\Repository\RepositoryInterface')->getMock();
        $repository2 = $this->getMockBuilder('Composer\Repository\RepositoryInterface')->getMock();
        $rm->addRepository($repository1);
        $rm->prependRepository($repository2);

        self::assertEquals([$repository2, $repository1], $rm->getRepositories());
    }

    /**
     * @dataProvider provideRepoCreationTestCases
     *
     * @doesNotPerformAssertions
     * @param array<string, mixed> $options
     */
    public function testRepoCreation(string $type, array $options): void
    {
        $rm = new RepositoryManager(
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $config = new Config,
            $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock()
        );

        $tmpdir = $this->tmpdir;
        $config->merge(['config' => ['cache-repo-dir' => $tmpdir]]);

        $rm->setRepositoryClass('composer', 'Composer\Repository\ComposerRepository');
        $rm->setRepositoryClass('vcs', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('package', 'Composer\Repository\PackageRepository');
        $rm->setRepositoryClass('pear', 'Composer\Repository\PearRepository');
        $rm->setRepositoryClass('git', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('svn', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('perforce', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('hg', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('artifact', 'Composer\Repository\ArtifactRepository');

        $rm->createRepository('composer', ['url' => 'http://example.org']);
        $rm->createRepository($type, $options);
    }

    public static function provideRepoCreationTestCases(): array
    {
        $cases = [
            ['composer', ['url' => 'http://example.org']],
            ['vcs', ['url' => 'http://github.com/foo/bar']],
            ['git', ['url' => 'http://github.com/foo/bar']],
            ['git', ['url' => 'git@example.org:foo/bar.git']],
            ['svn', ['url' => 'svn://example.org/foo/bar']],
            ['package', ['package' => []]],
        ];

        if (class_exists('ZipArchive')) {
            $cases[] = ['artifact', ['url' => '/path/to/zips']];
        }

        return $cases;
    }

    /**
     * @dataProvider provideInvalidRepoCreationTestCases
     *
     * @param array<string, mixed> $options
     */
    public function testInvalidRepoCreationThrows(string $type, array $options): void
    {
        self::expectException('InvalidArgumentException');

        $rm = new RepositoryManager(
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $config = new Config,
            $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock()
        );

        $tmpdir = $this->tmpdir;
        $config->merge(['config' => ['cache-repo-dir' => $tmpdir]]);

        $rm->createRepository($type, $options);
    }

    public static function provideInvalidRepoCreationTestCases(): array
    {
        return [
            ['pear', ['url' => 'http://pear.example.org/foo']],
            ['invalid', []],
        ];
    }

    public function testFilterRepoWrapping(): void
    {
        $rm = new RepositoryManager(
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $config = $this->getMockBuilder('Composer\Config')->onlyMethods(['get'])->getMock(),
            $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock()
        );

        $rm->setRepositoryClass('path', 'Composer\Repository\PathRepository');
        /** @var \Composer\Repository\FilterRepository $repo */
        $repo = $rm->createRepository('path', ['type' => 'path', 'url' => __DIR__, 'only' => ['foo/bar']]);

        self::assertInstanceOf('Composer\Repository\FilterRepository', $repo);
        self::assertInstanceOf('Composer\Repository\PathRepository', $repo->getRepository());
    }
}
