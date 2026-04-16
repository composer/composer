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

    public function provideIgnoredPackages(): array
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
            'multiple acme/package entries, first rule matches' => [['acme/package' => [['constraint' => '1.0'], ['constraint' => '1.1']]], 0],
            'multiple acme/package entries, second rule matches' => [['acme/package' => [['constraint' => '1.1'], ['constraint' => '1.0']]], 0],
            'multiple acme/package entries, no rule matches' => [['acme/package' => [['constraint' => '1.1'], ['constraint' => '1.2']]], 1],
        ];
    }

    /**
     * @dataProvider provideIgnoredPackages
     * @param list<array{package: string, constraint: string}>|list<string> $ignorePackageConfig
     */
    public function testGetMatchingEntriesUnfilteredPackages(array $ignorePackageConfig, int $expectedCount): void
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
                'ignore' => $ignorePackageConfig,
            ]
        ]]]);
        $policyConfig = PolicyConfig::fromConfig($config);

        $entries = $this->filterListAuditor->getMatchingEntries($package, $filterListMap, $policyConfig, 'block');
        $this->assertCount($expectedCount, $entries);
    }

    public function provideIgnoreSource(): array
    {
        return [
            'ignore matching source' => ['untrusted', ['untrusted'], 0],
            'do not ignore non-matching source' => ['trusted', ['untrusted'], 1],
            'do not ignore null source' => [null, ['untrusted'], 1],
            'no ignore-source configured' => ['untrusted', [], 1],
        ];
    }

    /**
     * @dataProvider provideIgnoreSource
     * @param list<string> $ignoreSource
     */
    public function testGetMatchingEntriesIgnoreSource(?string $entrySource, array $ignoreSource, int $expectedCount): void
    {
        $package = new CompletePackage('acme/package', '1.0.0.0', '1.0');
        $filterListMap = [
            'acme/package' => [
                'malware' => [FilterListEntry::create('malware', ['package' => 'acme/package', 'constraint' => '*', 'source' => $entrySource], self::getVersionParser())],
            ],
        ];

        $config = new Config();
        $config->merge(['config' => ['policy' => [
            'malware' => [
                'ignore-source' => $ignoreSource,
            ],
        ]]]);
        $policyConfig = PolicyConfig::fromConfig($config);

        $filterListMap = $this->filterListAuditor->applyIgnoreSourceFilter($filterListMap, $policyConfig, 'block');

        $entries = $this->filterListAuditor->getMatchingEntries($package, $filterListMap, $policyConfig, 'block');
        $this->assertCount($expectedCount, $entries);
    }

    public function testApplyIgnoreSourceFilterDropsIgnoredEntries(): void
    {
        $filterListMap = [
            'acme/package' => [
                'malware' => [
                    FilterListEntry::create('malware', ['package' => 'acme/package', 'constraint' => '*', 'source' => 'untrusted'], self::getVersionParser()),
                    FilterListEntry::create('malware', ['package' => 'acme/package', 'constraint' => '*', 'source' => 'trusted'], self::getVersionParser()),
                ],
            ],
            'acme/other' => [
                'malware' => [
                    FilterListEntry::create('malware', ['package' => 'acme/other', 'constraint' => '*', 'source' => 'untrusted'], self::getVersionParser()),
                ],
            ],
        ];

        $config = new Config();
        $config->merge(['config' => ['policy' => [
            'malware' => [
                'ignore-source' => ['untrusted'],
            ],
        ]]]);
        $policyConfig = PolicyConfig::fromConfig($config);

        $filtered = $this->filterListAuditor->applyIgnoreSourceFilter($filterListMap, $policyConfig, 'block');

        self::assertArrayHasKey('acme/package', $filtered);
        self::assertCount(1, $filtered['acme/package']['malware']);
        self::assertSame('trusted', $filtered['acme/package']['malware'][0]->source);

        self::assertArrayNotHasKey('malware', $filtered['acme/other']);
    }

    public function testApplyIgnoreSourceFilterIsNoopWhenNothingConfigured(): void
    {
        $filterListMap = [
            'acme/package' => [
                'malware' => [
                    FilterListEntry::create('malware', ['package' => 'acme/package', 'constraint' => '*', 'source' => 'untrusted'], self::getVersionParser()),
                ],
            ],
        ];

        $policyConfig = PolicyConfig::fromConfig(new Config());

        $filtered = $this->filterListAuditor->applyIgnoreSourceFilter($filterListMap, $policyConfig, 'block');

        self::assertSame($filterListMap, $filtered);
    }

    public function testGetMatchingEntriesIgnoresUnconfiguredLists(): void
    {
        $package = new CompletePackage('acme/package', '1.0.0.0', '1.0');
        $filterListMap = [
            'acme/package' => [
                'unconfigured' => [FilterListEntry::create('unconfigured', ['package' => 'acme/package', 'constraint' => '*'], self::getVersionParser())],
            ],
        ];

        $config = new Config();
        $config->merge(['config' => ['policy' => [
            'list' => [
                'ignore' => ['acme/package'],
            ]
        ]]]);
        $policyConfig = PolicyConfig::fromConfig($config);

        $entries = $this->filterListAuditor->getMatchingEntries($package, $filterListMap, $policyConfig, 'block');
        $this->assertSame([], $entries);
    }
}
