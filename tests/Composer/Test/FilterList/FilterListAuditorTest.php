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
use Composer\FilterList\FilterListEntry;
use Composer\Package\CompletePackage;
use Composer\Policy\PolicyConfig;
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
            'acme/other fully ignore' => [['acme/other'], 1],
            'acme/package fully ignore' => [['acme/package'], 0],
            'acme/package fully ignore but not on-block with block operation' => [['acme/package' => ['on-block' => false]], 1],
            'acme/package fully ignore but not on-audit with block operation' => [['acme/package' => ['on-audit' => false]], 0],
            'acme/* fully ignore' => [['acme/*'], 0],
            'acme/package 1.0 ignore' => [['acme/package' => ['constraint' => '1.0']], 0],
            'acme/* 1.0 ignore' => [['acme/*' => ['constraint' => '1.0']], 0],
            'acme/package 1.1 ignore' => [['acme/package' => ['constraint' => '1.1']], 1],
            'acme/* 1.1 ignore' => [['acme/*' => ['constraint' => '1.1']], 1],
            'multiple acme/package entries' => [['acme/package' => ['constraint' => '*'], ['package' => 'acme/package', 'constraint' => '1.1']], 0],
        ];
    }

    /**
     * @dataProvider provideUnfilteredPackages
     * @param list<array{package: string, constraint: string}>|list<string> $ignorePackageCOnfig
     */
    public function testGetMatchingEntriesUnfilteredPackages(array $ignorePackageCOnfig, int $expectedCount): void
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
        $config->merge(['config' => ['policy' => [
            'list' => [
                'ignore' => $ignorePackageCOnfig,
            ]
        ]]]);
        $policyConfig = PolicyConfig::fromConfig($config);

        $entries = $this->filterListAuditor->getMatchingEntries($package, $filterListMap, $policyConfig, 'block');
        $this->assertCount($expectedCount, $entries);
    }
}
