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

use Composer\Package\BasePackage;
use Composer\Repository\ArrayRepository;
use Composer\Repository\FilterRepository;
use Composer\Repository\RepositorySet;
use Composer\Semver\VersionParser;
use Composer\Test\TestCase;
use Composer\Config;
use Composer\DependencyResolver\Request;
use Composer\IO\NullIO;
use Composer\Repository\VcsRepository;
use Composer\Repository\Vcs\GitDriver;
use Composer\Repository\Vcs\GitHubDriver;
use Composer\Util\HttpDownloader;
use Composer\Repository\ComposerRepository;
use Composer\Test\Mock\FactoryMock;
use Composer\Util\Http\Response;
use Composer\Json\JsonFile;
use React\Promise\Deferred;

class RepositorySetTest extends TestCase
{
    public function testPreparedPackagesCanBeConsumedBeforeTheOtherRequestsFinish(): void
    {
        if (!HttpDownloader::isCurlEnabled()) {
            self::markTestSkipped('Prefetching requires curl.');
        }
        $pending = [];
        $http = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
        $http->method('get')->willReturn(new Response(['url' => 'https://example.org/packages.json'], 200, [], '{"metadata-url":"/p2/%package%.json"}'));
        $http->method('add')->willReturnCallback(static function (string $url) use (&$pending) {
            $pending[$url] = new Deferred;

            return $pending[$url]->promise();
        });
        $http->method('countActiveJobs')->willReturnCallback(static function () use (&$pending): int {
            $first = 'https://example.org/p2/vendor/first.json';
            self::assertArrayHasKey('https://example.org/p2/vendor/second.json', $pending);
            self::assertArrayHasKey($first, $pending, 'The first package must be yielded before waiting for the second.');
            $deferred = $pending[$first];
            unset($pending[$first]);
            $deferred->resolve(new Response(['url' => $first], 200, [], '{"packages":{"vendor/first":[{"name":"vendor/first","version":"2.0.0"}]}}'));

            return 1;
        });
        $set = new RepositorySet;
        $set->addRepository(new ComposerRepository(['url' => 'https://example.org'], new NullIO, FactoryMock::createConfig(), $http));
        $versions = [];
        foreach ($set->findPackagesForNames(['vendor/first' => null, 'vendor/second' => null]) as $name => $packages) {
            $versions[$name] = $packages[0]->getVersion();
            if ($name === 'vendor/first') {
                $second = 'https://example.org/p2/vendor/second.json';
                $pending[$second]->resolve(new Response(['url' => $second], 200, [], '{"packages":{"vendor/second":[{"name":"vendor/second","version":"3.0.0"}]}}'));
            }
        }
        self::assertSame(['vendor/first' => '2.0.0.0', 'vendor/second' => '3.0.0.0'], $versions);
    }

    public function testBatchedLookupKeepsCanonicalPriorityEvenWhenNoVersionMatches(): void
    {
        $first = self::getPackage('vendor/first', '1.0.0');
        $shadowed = self::getPackage('vendor/first', '2.0.0');
        $second = self::getPackage('vendor/second', '3.0.0');
        $dev = self::getPackage('vendor/dev', 'dev-main');
        $unstable = self::getPackage('vendor/unstable', 'dev-main');
        $set = new RepositorySet('stable', ['vendor/dev' => BasePackage::STABILITY_DEV]);
        $set->addRepository(new ArrayRepository([$first]));
        $set->addRepository(new ArrayRepository([$shadowed, $second, $dev, $unstable]));

        $packages = iterator_to_array($set->findPackagesForNames([
            'vendor/first' => (new VersionParser)->parseConstraints('>=2'),
            'vendor/second' => null,
            'vendor/dev' => null,
            'vendor/unstable' => null,
            'vendor/missing' => null,
        ]));

        self::assertSame([
            'vendor/first' => [],
            'vendor/second' => [$second],
            'vendor/dev' => [$dev],
            'vendor/unstable' => [],
            'vendor/missing' => [],
        ], $packages);
    }

    /**
     * @dataProvider provideFilters
     * @param array{only?: string[], exclude?: string[], canonical: bool} $filter
     */
    public function testBatchedLookupHonorsFiltersAndNonCanonicalRepositories(array $filter): void
    {
        $first = self::getPackage('vendor/first', '1.0.0');
        $excluded = self::getPackage('vendor/second', '9.0.0');
        $lowerFirst = self::getPackage('vendor/first', '2.0.0');
        $lowerSecond = self::getPackage('vendor/second', '3.0.0');
        $set = new RepositorySet;
        $set->addRepository(new FilterRepository(new ArrayRepository([$first, $excluded]), $filter));
        $set->addRepository(new ArrayRepository([$lowerFirst, $lowerSecond]));

        self::assertSame([
            'vendor/first' => [$first, $lowerFirst],
            'vendor/second' => [$lowerSecond],
        ], iterator_to_array($set->findPackagesForNames(['vendor/first' => null, 'vendor/second' => null])));
    }

    public static function provideFilters(): array
    {
        return [
            [['only' => ['vendor/first'], 'canonical' => false]],
            [['exclude' => ['vendor/second'], 'canonical' => false]],
        ];
    }

    /**
     * @dataProvider provideLookups
     */
    public function testUnusedVcsRepositoriesMakeNoRequests(string $lookup, bool $filtered): void
    {
        if (!HttpDownloader::isCurlEnabled()) {
            self::markTestSkipped('Prefetching requires curl.');
        }

        $http = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
        $http->expects(self::never())->method('add');
        $http->expects(self::never())->method('get');
        $package = self::getPackage('vendor/first', '1.0.0');
        $first = new ArrayRepository([$package]);
        $vcs = new VcsRepository(['type' => 'github', 'url' => 'https://github.com/example/unused'], new NullIO, new Config, $http);
        if ($filtered) {
            $first = new FilterRepository(new FilterRepository($first, []), ['only' => ['vendor/*']]);
            $vcs = new FilterRepository($vcs, ['only' => ['vendor/*']]);
        }
        $set = new RepositorySet;
        $set->addRepository($first);
        $set->addRepository($vcs);

        if ($lookup === 'single') {
            self::assertSame([$package], $set->findPackages('vendor/first'));
        } elseif ($lookup === 'batch') {
            self::assertSame(['vendor/first' => [$package]], iterator_to_array($set->findPackagesForNames(['vendor/first' => null])));
        } else {
            $request = new Request;
            $request->requireName('vendor/first', (new VersionParser)->parseConstraints('1.0.0'));
            self::assertSame([$package], $set->createPool($request, new NullIO)->getPackages());
        }
    }

    public static function provideLookups(): array
    {
        return [
            ['single', false],
            ['single', true],
            ['batch', false],
            ['batch', true],
            ['pool', false],
            ['pool', true],
        ];
    }

    public function testVcsDiscoveryStartsTogetherWhenLookupReachesTheGroup(): void
    {
        if (!HttpDownloader::isCurlEnabled()) {
            self::markTestSkipped('Prefetching requires curl.');
        }

        $config = FactoryMock::createConfig();
        /** @var array<non-empty-string, Deferred<Response>> $pending */
        $pending = [];
        $http = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
        $http->method('add')->willReturnCallback(static function (string $url) use (&$pending) {
            self::assertNotSame('', $url);
            self::assertArrayNotHasKey($url, $pending);
            $pending[$url] = new Deferred;

            return $pending[$url]->promise();
        });
        $http->expects(self::never())->method('get');
        $local = self::getPackage('vendor/local', '1.0.0');
        $set = new RepositorySet('dev');
        $set->addRepository(new ArrayRepository([$local]));
        $responses = [];
        foreach (['first' => 'a', 'second' => 'b'] as $name => $character) {
            $url = 'https://api.github.com/repos/example/'.$name;
            $sha = str_repeat($character, 40);
            $responses[$url] = JsonFile::encode(['default_branch' => 'main', 'owner' => ['login' => 'example'], 'name' => $name]);
            $responses[$url.'/tags?per_page=100'] = '[]';
            $responses[$url.'/git/refs/heads?per_page=100'] = JsonFile::encode([['ref' => 'refs/heads/main', 'object' => ['sha' => $sha]]]);
            $responses[$url.'/contents/composer.json?ref='.$sha] = JsonFile::encode(['encoding' => 'base64', 'content' => base64_encode(JsonFile::encode(['name' => 'example/'.$name, 'time' => '2026-01-01T00:00:00Z', 'funding' => []]))]);
            $repository = new VcsRepository(['type' => 'github', 'url' => 'https://github.com/example/'.$name], new NullIO, $config, $http);
            $set->addRepository(new FilterRepository($repository, ['only' => ['example/'.$name]]));
        }
        $ticks = 0;
        $http->method('countActiveJobs')->willReturnCallback(static function () use (&$pending, &$ticks, $responses): int {
            if ($ticks++ === 0) {
                self::assertCount(6, $pending, 'Both repositories must start discovery before waiting for responses.');
            }
            foreach (array_keys($pending) as $url) {
                $deferred = $pending[$url];
                unset($pending[$url]);
                $deferred->resolve(new Response(['url' => $url], 200, [], $responses[$url]));
            }

            return count($pending);
        });
        $references = [];
        foreach ($set->findPackagesForNames(['vendor/local' => null, 'example/first' => null, 'example/second' => null]) as $name => $packages) {
            if ($name === 'vendor/local') {
                self::assertSame([], $pending, 'Later VCS repositories must wait until earlier repositories have been consulted.');
            }
            $references[$name] = $packages[0]->getSourceReference();
        }

        self::assertSame(['vendor/local' => null, 'example/first' => str_repeat('a', 40), 'example/second' => str_repeat('b', 40)], $references);
    }

    public function testFilteredOutVcsRepositoryMakesNoRequests(): void
    {
        $http = $this->getHttpDownloaderMock();
        $http->expects([], true);
        $package = self::getPackage('vendor/first', '1.0.0');
        $vcs = new VcsRepository(['type' => 'github', 'url' => 'https://github.com/example/unused'], new NullIO, new Config, $http);
        $set = new RepositorySet;
        $set->addRepository(new FilterRepository($vcs, ['only' => ['other/*']]));
        $set->addRepository(new ArrayRepository([$package]));

        self::assertSame(['vendor/first' => [$package]], iterator_to_array($set->findPackagesForNames(['vendor/first' => null])));
    }

    /**
     * @dataProvider provideCanonicalPrefetch
     * @param array{available-packages?: string[], available-package-patterns?: string[]} $advertisement
     */
    public function testMetadataLookaheadHonorsCanonicalRepositories(bool $canonical, array $advertisement): void
    {
        if (!HttpDownloader::isCurlEnabled()) {
            self::markTestSkipped('Prefetching requires curl.');
        }
        $config = FactoryMock::createConfig();
        $http = $this->getMockBuilder(HttpDownloader::class)->disableOriginalConstructor()->getMock();
        $http->expects(self::exactly(2))->method('get')->willReturnCallback(static function (string $url) use ($advertisement) {
            self::assertNotSame('', $url);
            $root = ['metadata-url' => '/p2/%package%.json'];
            if ($url === 'https://first.example/packages.json') {
                $root += $advertisement;
            }

            return new Response(['url' => $url], 200, [], JsonFile::encode($root));
        });
        $requests = [];
        $http->method('add')->willReturnCallback(static function (string $url) use (&$requests) {
            self::assertNotSame('', $url);
            $requests[] = $url;
            $bodies = [
                'https://first.example/p2/vendor/first.json' => '{"packages":{"vendor/first":[{"name":"vendor/first","version":"1.0.0"}]}}',
                'https://second.example/p2/vendor/first.json' => '{"packages":{"vendor/first":[{"name":"vendor/first","version":"2.0.0"}]}}',
                'https://second.example/p2/vendor/second.json' => '{"packages":{"vendor/second":[{"name":"vendor/second","version":"3.0.0"}]}}',
            ];

            return \React\Promise\resolve(new Response(['url' => $url], 200, [], $bodies[$url] ?? '{"packages":{}}'));
        });
        $first = new ComposerRepository(['url' => 'https://first.example'], new NullIO, $config, $http);
        $second = new ComposerRepository(['url' => 'https://second.example'], new NullIO, $config, $http);
        $stable = ['stable' => BasePackage::STABILITY_STABLE];
        $first->loadPackages([], $stable, []);
        $second->loadPackages([], $stable, []);
        $set = new RepositorySet;
        $set->addRepository(new FilterRepository($first, ['canonical' => $canonical]));
        $set->addRepository($second);
        $names = ['vendor/first' => null, 'vendor/second' => null];
        $set->prefetchPackages($names);
        $result = iterator_to_array($set->findPackagesForNames($names));
        self::assertSame(!$canonical, in_array('https://second.example/p2/vendor/first.json', $requests, true));
        self::assertCount($canonical ? 1 : 2, $result['vendor/first']);
        self::assertSame('3.0.0.0', $result['vendor/second'][0]->getVersion());
    }

    public static function provideCanonicalPrefetch(): array
    {
        return [
            [true, ['available-packages' => ['vendor/first']]],
            [false, ['available-packages' => ['vendor/first']]],
            [true, []],
            [false, []],
            [true, ['available-package-patterns' => ['vendor/*']]],
            [false, ['available-package-patterns' => ['vendor/*']]],
            [true, ['available-packages' => ['other/package'], 'available-package-patterns' => ['vendor/*']]],
            [false, ['available-packages' => ['other/package'], 'available-package-patterns' => ['vendor/*']]],
        ];
    }

    public function testPrefetchDoesNotBypassAHigherPriorityVcsDriver(): void
    {
        $http = $this->getHttpDownloaderMock();
        $http->expects([], true);
        $config = new Config;
        $config->merge(['config' => ['home' => sys_get_temp_dir()]]);
        $vcs = new VcsRepository(
            ['type' => 'vcs', 'url' => 'https://github.com/example/package'],
            new NullIO,
            $config,
            $http,
            null,
            null,
            ['custom' => HigherPriorityGitDriver::class, 'github' => GitHubDriver::class]
        );

        $vcs->prefetchPackages(['vendor/package' => null]);

        self::assertInstanceOf(HigherPriorityGitDriver::class, $vcs->getDriver());
    }
}

class HigherPriorityGitDriver extends GitDriver
{
    public function initialize(): void
    {
    }
}
