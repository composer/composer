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

namespace Composer\Test\DependencyResolver;

use Composer\Config;
use Composer\DependencyResolver\FilterListPoolFilter;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\Downloader\TransportException;
use Composer\FilterList\FilterListAuditor;
use Composer\IO\BufferIO;
use Composer\IO\NullIO;
use Composer\Package\Package;
use Composer\Policy\ListPolicyConfig;
use Composer\Policy\PolicyConfig;
use Composer\Repository\LockArrayRepository;
use Composer\Repository\PackageRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Test\Mock\HttpDownloaderMock;
use Composer\Test\TestCase;

class FilterListPoolFilterTest extends TestCase
{
    /** @var HttpDownloaderMock */
    private $httpDownloaderMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpDownloaderMock = $this->getHttpDownloaderMock();
    }

    public function testFilterPackages(): void
    {
        $config = new Config();
        $config->merge(['config' => ['policy' => ['test-list' => true]]]);
        $policyConfig = PolicyConfig::fromConfig($config);

        $repository = $this->generatePackageRepository('1.0');
        $filter = new FilterListPoolFilter($policyConfig, new FilterListAuditor(), $this->httpDownloaderMock, ListPolicyConfig::BLOCK_SCOPE_UPDATE, [$repository], new NullIO());

        $pool = new Pool([
            new Package('acme/package', '1.0.0.0', '1.0'),
            $expectedPackage1 = new Package('acme/package', '2.0.0.0', '2.0'),
            $expectedPackage2 = new Package('acme/other', '1.0.0.0', '1.0'),
        ]);
        $filteredPool = $filter->filter($pool, new Request());

        $this->assertSame([$expectedPackage1, $expectedPackage2], $filteredPool->getPackages());
        $this->assertTrue($filteredPool->isFilterListRemovedPackageVersion('acme/package', new Constraint('==', '1.0.0.0')));
        $this->assertCount(1, $filteredPool->getAllFilterListRemovedPackageVersions());
    }

    /**
     * @dataProvider unfilteredProvider
     * @param bool|array<string, mixed> $filterConfig
     */
    public function testUnfilteredPackagesConfig($filterConfig): void
    {
        $config = new Config();
        $config->merge(['config' => ['policy' => ['test-list' => $filterConfig]]]);

        $policyConfig = PolicyConfig::fromConfig($config);
        $repository = $this->generatePackageRepository('*');
        $filter = new FilterListPoolFilter($policyConfig, new FilterListAuditor(), $this->httpDownloaderMock, ListPolicyConfig::BLOCK_SCOPE_UPDATE, [$repository], new NullIO());

        $pool = new Pool([
            $expectedPackage1 = new Package('acme/package', '1.0.0.0', '1.0'),
            $expectedPackage2 = new Package('acme/package', '1.1.0.0', '1.1'),
        ]);
        $filteredPool = $filter->filter($pool, new Request());

        $this->assertSame([$expectedPackage1, $expectedPackage2], $filteredPool->getPackages());
        $this->assertCount(0, $filteredPool->getAllFilterListRemovedPackageVersions());
    }

    public static function unfilteredProvider(): array
    {
        return [
            'ignore-packages' => [['ignore' => ['acme/package']]],
            'ignore-packages-version' => [['ignore' => ['acme/package' => ['constraint' => '*']]]],
        ];
    }

    public function testUnfilteredPackagesConfigIntersection(): void
    {
        $config = new Config();
        $config->merge([
            'config' => [
                'policy' => [
                    'test-list' => [
                        'ignore' => ['acme/package' => ['constraint' => '<=1.0']],
                    ],
                ],
            ],
        ]);

        $policyConfig = PolicyConfig::fromConfig($config);
        $repository = $this->generatePackageRepository('>=1.0');
        $filter = new FilterListPoolFilter($policyConfig, new FilterListAuditor(), $this->httpDownloaderMock, ListPolicyConfig::BLOCK_SCOPE_UPDATE, [$repository], new NullIO());

        $pool = new Pool([
            $expectedPackage = new Package('acme/package', '1.0.0.0', '1.0'),
            new Package('acme/package', '1.1.0.0', '1.1'),
        ]);
        $filteredPool = $filter->filter($pool, new Request());

        $this->assertSame([$expectedPackage], $filteredPool->getPackages());
        $this->assertCount(1, $filteredPool->getAllFilterListRemovedPackageVersions());
    }

    public function testFilterWithAdditionalSources(): void
    {
        $this->httpDownloaderMock->expects([[
            'url' => 'https://example.org/malware/acme/package',
            'body' => (string) json_encode(['filter' => [[
                'package' => 'acme/package',
                'constraint' => '3.0.0.0',
                'reason' => 'malware',
            ]]]),
        ]]);

        $config = new Config();
        $config->merge(['config' => ['policy' => [
            'test-list' => [
                'sources' => ['source-list' => [
                    'type' => 'url',
                    'url' => 'https://example.org/malware/acme/package',
                ]],
            ]
        ]]]);

        $policyConfig = PolicyConfig::fromConfig($config);
        $repository = $this->generatePackageRepository('0.0.1');
        $filter = new FilterListPoolFilter($policyConfig, new FilterListAuditor(), $this->httpDownloaderMock, ListPolicyConfig::BLOCK_SCOPE_UPDATE, [$repository], new NullIO());

        $pool = new Pool([
            new Package('acme/package', '3.0.0.0', '3.0'),
            $expectedPackage1 = new Package('acme/package', '2.0.0.0', '2.0'),
            $expectedPackage2 = new Package('acme/other', '1.0.0.0', '1.0'),
        ]);
        $filteredPool = $filter->filter($pool, new Request());

        $this->assertSame([$expectedPackage1, $expectedPackage2], $filteredPool->getPackages());
        $this->assertTrue($filteredPool->isFilterListRemovedPackageVersion('acme/package', new Constraint('==', '3.0.0.0')));
        $this->assertCount(1, $filteredPool->getAllFilterListRemovedPackageVersions());
    }

    public function testInstallScopeFiltersLockedPackagesAgainstMalwareList(): void
    {
        $repository = new PackageRepository([
            'package' => [],
            'filter' => [
                'malware' => [
                    [
                        'package' => 'acme/locked',
                        'constraint' => '*',
                        'reason' => 'malware',
                        'url' => 'https://example.org/malware/acme/locked',
                    ],
                ],
            ],
        ]);

        $config = new Config();
        $config->merge(['config' => ['policy' => ['malware' => true]]]);
        $policyConfig = PolicyConfig::fromConfig($config);

        $package = new Package('acme/locked', '1.0.0.0', '1.0');
        $request = new Request();
        $request->fixLockedPackage($package);

        // Install scope: locked package IS checked, gets filter-list-removed.
        $installFilter = new FilterListPoolFilter($policyConfig, new FilterListAuditor(), $this->httpDownloaderMock, ListPolicyConfig::BLOCK_SCOPE_INSTALL, [$repository], new NullIO());
        $installPool = $installFilter->filter(new Pool([$package]), $request);
        self::assertSame([], $installPool->getPackages(), 'install-scope filter must include locked packages');
        self::assertTrue($installPool->isFilterListRemovedPackageVersion('acme/locked', new Constraint('==', '1.0.0.0')));

        // Update scope: locked packages are checked against install-scope filter lists too,
        // so a malware-flagged locked package is still removed from the pool.
        $updateFilter = new FilterListPoolFilter($policyConfig, new FilterListAuditor(), $this->httpDownloaderMock, ListPolicyConfig::BLOCK_SCOPE_UPDATE, [$repository], new NullIO());
        $updatePool = $updateFilter->filter(new Pool([$package]), $request);
        self::assertSame([], $updatePool->getPackages(), 'update-scope filter must apply install-scope rules to locked packages');
        self::assertTrue($updatePool->isFilterListRemovedPackageVersion('acme/locked', new Constraint('==', '1.0.0.0')));
    }

    public function testUpdateScopeAppliesInstallScopeToPackagesInLockedRepository(): void
    {
        $repository = new PackageRepository([
            'package' => [],
            'filter' => [
                'malware' => [
                    [
                        'package' => 'acme/mirrored',
                        'constraint' => '*',
                        'reason' => 'malware',
                        'url' => 'https://example.org/malware/acme/mirrored',
                    ],
                ],
            ],
        ]);

        // block-scope: install means the malware list is NOT in getActiveBlockFilterListNames(UPDATE)
        $config = new Config();
        $config->merge(['config' => ['policy' => ['malware' => ['block' => true, 'block-scope' => ListPolicyConfig::BLOCK_SCOPE_INSTALL]]]]);
        $policyConfig = PolicyConfig::fromConfig($config);

        // PoolBuilder loads fresh package instances from configured repos for `update mirrors`
        // Use distinct instances to mirror that in this unit test — the locked
        // package and the pool package have the same name+version but are not the same object.
        $lockedPackage = new Package('acme/mirrored', '1.0.0.0', '1.0');
        $poolPackage = new Package('acme/mirrored', '1.0.0.0', '1.0');
        $lockedRepo = new LockArrayRepository();
        $lockedRepo->addPackage($lockedPackage);
        $request = new Request($lockedRepo);
        $request->requireName('acme/mirrored', new Constraint('==', '1.0.0.0'));

        $updateFilter = new FilterListPoolFilter($policyConfig, new FilterListAuditor(), $this->httpDownloaderMock, ListPolicyConfig::BLOCK_SCOPE_UPDATE, [$repository], new NullIO());
        $updatePool = $updateFilter->filter(new Pool([$poolPackage]), $request);
        self::assertSame([], $updatePool->getPackages(), 'packages from the locked repo must be checked against install-scope filter lists');
        self::assertTrue($updatePool->isFilterListRemovedPackageVersion('acme/mirrored', new Constraint('==', '1.0.0.0')));
    }

    public function testUpdateScopeIgnoresInstallOnlyListsForNonLockedPackages(): void
    {
        $repository = new PackageRepository([
            'package' => [],
            'filter' => [
                'malware' => [
                    [
                        'package' => 'acme/free',
                        'constraint' => '*',
                        'reason' => 'malware',
                        'url' => 'https://example.org/malware/acme/free',
                    ],
                ],
            ],
        ]);

        // block-scope: install — only locked-equivalent packages should be
        // filtered. A package that is not in the locked repository must pass
        // through the UPDATE-scope filter unmodified.
        $config = new Config();
        $config->merge(['config' => ['policy' => ['malware' => ['block' => true, 'block-scope' => ListPolicyConfig::BLOCK_SCOPE_INSTALL]]]]);
        $policyConfig = PolicyConfig::fromConfig($config);

        $package = new Package('acme/free', '1.0.0.0', '1.0');
        $request = new Request();

        $updateFilter = new FilterListPoolFilter($policyConfig, new FilterListAuditor(), $this->httpDownloaderMock, ListPolicyConfig::BLOCK_SCOPE_UPDATE, [$repository], new NullIO());
        $updatePool = $updateFilter->filter(new Pool([$package]), $request);
        self::assertSame([$package], $updatePool->getPackages(), 'install-only lists must not block non-locked packages during update scope');
        self::assertCount(0, $updatePool->getAllFilterListRemovedPackageVersions());
    }

    public function testWarnsWhenUnreachableSourcesAreIgnored(): void
    {
        $unreachable = new class([
            'package' => [],
            'filter' => [
                'test-list' => [
                    ['package' => 'acme/package', 'constraint' => '*', 'reason' => 'malware'],
                ],
            ],
        ]) extends PackageRepository {
            public function getFilter(array $packageConstraintMap): array
            {
                throw new TransportException('The "https://example.org/filter.json" file could not be downloaded: HTTP/1.1 500 Internal Server Error', 500);
            }

            public function getRepoName(): string
            {
                return 'unreachable filter list repo';
            }
        };

        // ignore-unreachable defaults to ["update", "install"], so the transport error is swallowed.
        $config = new Config();
        $config->merge(['config' => ['policy' => ['test-list' => true]]]);
        $policyConfig = PolicyConfig::fromConfig($config);

        $io = new BufferIO();
        $filter = new FilterListPoolFilter($policyConfig, new FilterListAuditor(), $this->httpDownloaderMock, ListPolicyConfig::BLOCK_SCOPE_UPDATE, [$unreachable], $io);
        $filter->filter(new Pool([new Package('acme/package', '1.0.0.0', '1.0')]), new Request());

        $output = $io->getOutput();
        self::assertStringContainsString('Filter list data could not be fetched from some sources', $output);
        self::assertStringContainsString('HTTP/1.1 500 Internal Server Error', $output);
    }

    public function testRethrowsTransportErrorWhenUnreachableIsNotIgnored(): void
    {
        $unreachable = new class([
            'package' => [],
            'filter' => [
                'test-list' => [
                    ['package' => 'acme/package', 'constraint' => '*', 'reason' => 'malware'],
                ],
            ],
        ]) extends PackageRepository {
            public function getFilter(array $packageConstraintMap): array
            {
                throw new TransportException('boom', 500);
            }

            public function getRepoName(): string
            {
                return 'unreachable filter list repo';
            }
        };

        // ignore-unreachable: false — transport errors bubble up; no warning needed.
        $config = new Config();
        $config->merge(['config' => ['policy' => ['test-list' => true, 'ignore-unreachable' => false]]]);
        $policyConfig = PolicyConfig::fromConfig($config);

        $filter = new FilterListPoolFilter($policyConfig, new FilterListAuditor(), $this->httpDownloaderMock, ListPolicyConfig::BLOCK_SCOPE_UPDATE, [$unreachable], new BufferIO());

        $this->expectException(TransportException::class);
        $filter->filter(new Pool([new Package('acme/package', '1.0.0.0', '1.0')]), new Request());
    }

    private function generatePackageRepository(string $constraint): PackageRepository
    {
        return new PackageRepository([
            'package' => [],
            'filter' => [
                'test-list' => [
                    [
                        'package' => 'acme/package',
                        'constraint' => $constraint,
                        'reason' => 'malware',
                        'url' => 'https://example.org/malware/acme/package',
                    ],
                ],
            ],
        ]);
    }
}
