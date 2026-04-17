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
use Composer\FilterList\FilterListAuditor;
use Composer\Package\Package;
use Composer\Policy\ListPolicyConfig;
use Composer\Policy\PolicyConfig;
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
        $filter = new FilterListPoolFilter($policyConfig, new FilterListAuditor(), $this->httpDownloaderMock, ListPolicyConfig::BLOCK_SCOPE_UPDATE, [$repository]);

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
        $filter = new FilterListPoolFilter($policyConfig, new FilterListAuditor(), $this->httpDownloaderMock, ListPolicyConfig::BLOCK_SCOPE_UPDATE, [$repository]);

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
        $filter = new FilterListPoolFilter($policyConfig, new FilterListAuditor(), $this->httpDownloaderMock, ListPolicyConfig::BLOCK_SCOPE_UPDATE, [$repository]);

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
        $filter = new FilterListPoolFilter($policyConfig, new FilterListAuditor(), $this->httpDownloaderMock, ListPolicyConfig::BLOCK_SCOPE_UPDATE, [$repository]);

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
        $installFilter = new FilterListPoolFilter($policyConfig, new FilterListAuditor(), $this->httpDownloaderMock, ListPolicyConfig::BLOCK_SCOPE_INSTALL, [$repository]);
        $installPool = $installFilter->filter(new Pool([$package]), $request);
        self::assertSame([], $installPool->getPackages(), 'install-scope filter must include locked packages');
        self::assertTrue($installPool->isFilterListRemovedPackageVersion('acme/locked', new Constraint('==', '1.0.0.0')));

        // Update scope: locked package is early-skipped, never reaches the filter.
        $updateFilter = new FilterListPoolFilter($policyConfig, new FilterListAuditor(), $this->httpDownloaderMock, ListPolicyConfig::BLOCK_SCOPE_UPDATE, [$repository]);
        $updatePool = $updateFilter->filter(new Pool([$package]), $request);
        self::assertSame([$package], $updatePool->getPackages(), 'update-scope filter must skip locked packages');
        self::assertCount(0, $updatePool->getAllFilterListRemovedPackageVersions());
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
