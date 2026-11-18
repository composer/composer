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
use Composer\Policy\ListPolicyConfig;
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

        $entries = $this->filterListAuditor->getMatchingBlockEntries($package, $filterListMap, $policyConfig, ListPolicyConfig::BLOCK_SCOPE_UPDATE);
        $this->assertCount($expectedCount, $entries);
    }

    public function provideIgnoreSource(): array
    {
        $cases = [];
        foreach (['block', 'audit'] as $operation) {
            $cases[$operation.': ignore matching source'] = [$operation, 'untrusted', ['untrusted'], 0];
            $cases[$operation.': do not ignore non-matching source'] = [$operation, 'trusted', ['untrusted'], 1];
            $cases[$operation.': do not ignore null source'] = [$operation, null, ['untrusted'], 1];
            $cases[$operation.': no ignore-source configured'] = [$operation, 'untrusted', [], 1];
        }

        return $cases;
    }

    /**
     * @dataProvider provideIgnoreSource
     * @param 'block'|'audit' $operation
     * @param list<string> $ignoreSource
     */
    public function testGetMatchingEntriesIgnoreSource(string $operation, ?string $entrySource, array $ignoreSource, int $expectedCount): void
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

        switch ($operation) {
            case 'audit':
                $entries = $this->filterListAuditor->getMatchingAuditEntries($package, $filterListMap, $policyConfig);
                break;
            case 'block':
                $entries = $this->filterListAuditor->getMatchingBlockEntries($package, $filterListMap, $policyConfig, ListPolicyConfig::BLOCK_SCOPE_UPDATE);
                break;
        }

        $this->assertCount($expectedCount, $entries);
    }

    public function testGetMatchingEntriesKeepsNonIgnoredSourcesAndDropsIgnored(): void
    {
        $package = new CompletePackage('acme/package', '1.0.0.0', '1.0');
        $filterListMap = [
            'acme/package' => [
                'malware' => [
                    FilterListEntry::create('malware', ['package' => 'acme/package', 'constraint' => '*', 'source' => 'untrusted'], self::getVersionParser()),
                    FilterListEntry::create('malware', ['package' => 'acme/package', 'constraint' => '*', 'source' => 'trusted'], self::getVersionParser()),
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

        $entries = $this->filterListAuditor->getMatchingBlockEntries($package, $filterListMap, $policyConfig, ListPolicyConfig::BLOCK_SCOPE_UPDATE);

        self::assertCount(1, $entries);
        self::assertSame('trusted', $entries[0]->source);
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

        $entries = $this->filterListAuditor->getMatchingBlockEntries($package, $filterListMap, $policyConfig, ListPolicyConfig::BLOCK_SCOPE_UPDATE);
        $this->assertSame([], $entries);
    }

    public function testGetMatchingEntriesDropsUnconfiguredListEntriesForNonIgnoredPackage(): void
    {
        $package = new CompletePackage('acme/package', '1.0.0.0', '1.0');
        $filterListMap = [
            'acme/package' => [
                'configured' => [FilterListEntry::create('configured', ['package' => 'acme/package', 'constraint' => '*'], self::getVersionParser())],
                'unconfigured' => [FilterListEntry::create('unconfigured', ['package' => 'acme/package', 'constraint' => '*'], self::getVersionParser())],
            ],
        ];

        $config = new Config();
        $config->merge(['config' => ['policy' => [
            'configured' => true,
        ]]]);
        $policyConfig = PolicyConfig::fromConfig($config);

        $entries = $this->filterListAuditor->getMatchingBlockEntries($package, $filterListMap, $policyConfig, ListPolicyConfig::BLOCK_SCOPE_UPDATE);

        self::assertCount(1, $entries);
        self::assertSame('configured', $entries[0]->listName);
    }
}
