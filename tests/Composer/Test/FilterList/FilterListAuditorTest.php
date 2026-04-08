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

namespace Composer\Test\FilterList;

use Composer\Config;
use Composer\FilterList\FilterListAuditor;
use Composer\FilterList\FilterListConfig;
use Composer\FilterList\FilterListEntry;
use Composer\Package\CompletePackage;
use Composer\Test\TestCase;

class FilterListAuditorTest extends TestCase
{
    /** @var FilterListAuditor */
    private $filterListAuditor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filterListAuditor = new FilterListAuditor();
    }

    public function provideUnfilteredPackages(): array
    {
        return [
            'acme/other fully unfiltered' => [['acme/other'], 1],
            'acme/package fully unfiltered' => [['acme/package'], 0],
            'acme/* fully unfiltered' => [['acme/*'], 0],
            'acme/package 1.0 unfiltered' => [[['package' => 'acme/package', 'constraint' => '1.0']], 0],
            'acme/* 1.0 unfiltered' => [[['package' => 'acme/*', 'constraint' => '1.0']], 0],
            'acme/package 1.1 unfiltered' => [[['package' => 'acme/package', 'constraint' => '1.1']], 1],
            'acme/* 1.1 unfiltered' => [[['package' => 'acme/*', 'constraint' => '1.1']], 1],
            'multiple acme/package entries' => [[['package' => 'acme/package', 'constraint' => '*'], ['package' => 'acme/package', 'constraint' => '1.1']], 0],
        ];
    }

    /**
     * @dataProvider provideUnfilteredPackages
     * @param list<array{package: string, constraint: string}>|list<string> $unfilteredPackageConfig
     */
    public function testGetMatchingEntriesUnfilteredPackages(array $unfilteredPackageConfig, int $expectedCount): void
    {
        $package = new CompletePackage('acme/package', '1.0.0.0', '1.0');
        $filterListMap = [
            'acme/package' => [
                'list' => [FilterListEntry::create('list', ['package' => 'acme/package', 'constraint' => '*'], self::getVersionParser())],
            ],
            'acme/other' => [
                'list' => [FilterListEntry::create('list', ['package' => 'acme/other', 'constraint' => '*'], self::getVersionParser())],
            ],
        ];

        $config = new Config();
        $config->merge(['config' => ['filter' => [
            'unfiltered-packages' => $unfilteredPackageConfig,
        ]]]);
        $filterListConfig = FilterListConfig::fromConfig($config, self::getVersionParser());
        $this->assertNotNull($filterListConfig);

        $entries = $this->filterListAuditor->getMatchingEntries($package, $filterListMap, $filterListConfig, 'block');
        $this->assertCount($expectedCount, $entries);
    }
}
