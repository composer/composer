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
use Composer\FilterList\FilterListConfig;
use Composer\Package\Package;
use Composer\Package\Version\VersionParser;
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
        $filterListConfig = FilterListConfig::fromConfig($config, new VersionParser());
        $this->assertNotNull($filterListConfig);

        $filter = new FilterListPoolFilter($filterListConfig, new FilterListAuditor(), $this->httpDownloaderMock);

        $repository = $this->generatePackageRepository('1.0');
        $pool = new Pool([
            new Package('acme/package', '1.0.0.0', '1.0'),
            $expectedPackage1 = new Package('acme/package', '2.0.0.0', '2.0'),
            $expectedPackage2 = new Package('acme/other', '1.0.0.0', '1.0'),
        ]);
        $filteredPool = $filter->filter($pool, [$repository], new Request());

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
        $config->merge(['config' => ['filter' => $filterConfig]]);

        $filterListConfig = FilterListConfig::fromConfig($config, new VersionParser());
        $this->assertNotNull($filterListConfig);

        $filter = new FilterListPoolFilter($filterListConfig, new FilterListAuditor(), $this->httpDownloaderMock);

        $repository = $this->generatePackageRepository('*');
        $pool = new Pool([
            $expectedPackage1 = new Package('acme/package', '1.0.0.0', '1.0'),
            $expectedPackage2 = new Package('acme/package', '1.1.0.0', '1.1'),
        ]);
        $filteredPool = $filter->filter($pool, [$repository], new Request());

        $this->assertSame([$expectedPackage1, $expectedPackage2], $filteredPool->getPackages());
        $this->assertCount(0, $filteredPool->getAllFilterListRemovedPackageVersions());
    }

    public static function unfilteredProvider(): array
    {
        return [
            'unfiltered-packages' => [['unfiltered-packages' => ['acme/package']]],
            'unfiltered-packages-version' => [['unfiltered-packages' => [['package' => 'acme/package', 'constraint' => '*']]]],
        ];
    }

    public function testUnfilteredPackagesConfigIntersection(): void
    {
        $config = new Config();
        $config->merge(['config' => ['filter' => [
            'unfiltered-packages' => [['package' => 'acme/package', 'constraint' => '<=1.0']],
        ]]]);

        $filterListConfig = FilterListConfig::fromConfig($config, new VersionParser());
        $this->assertNotNull($filterListConfig);

        $filter = new FilterListPoolFilter($filterListConfig, new FilterListAuditor(), $this->httpDownloaderMock);

        $repository = $this->generatePackageRepository('>=1.0');
        $pool = new Pool([
            $expectedPackage = new Package('acme/package', '1.0.0.0', '1.0'),
            new Package('acme/package', '1.1.0.0', '1.1'),
        ]);
        $filteredPool = $filter->filter($pool, [$repository], new Request());

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
        $config->merge(['config' => ['filter' => [
            'sources' => ['source-list' => [
                'type' => 'url',
                'url' => 'https://example.org/malware/acme/package',
            ]],
        ]]]);

        $filterListConfig = FilterListConfig::fromConfig($config, new VersionParser());
        $this->assertNotNull($filterListConfig);

        $filter = new FilterListPoolFilter($filterListConfig, new FilterListAuditor(), $this->httpDownloaderMock);

        $repository = $this->generatePackageRepository('0.0.1');
        $pool = new Pool([
            new Package('acme/package', '3.0.0.0', '3.0'),
            $expectedPackage1 = new Package('acme/package', '2.0.0.0', '2.0'),
            $expectedPackage2 = new Package('acme/other', '1.0.0.0', '1.0'),
        ]);
        $filteredPool = $filter->filter($pool, [$repository], new Request());

        $this->assertSame([$expectedPackage1, $expectedPackage2], $filteredPool->getPackages());
        $this->assertTrue($filteredPool->isFilterListRemovedPackageVersion('acme/package', new Constraint('==', '3.0.0.0')));
        $this->assertCount(1, $filteredPool->getAllFilterListRemovedPackageVersions());
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
