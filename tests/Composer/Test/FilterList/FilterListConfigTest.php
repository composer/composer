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
use Composer\FilterList\FilterListConfig;
use Composer\Package\Version\VersionParser;
use Composer\Test\TestCase;

class FilterListConfigTest extends TestCase
{
    /**
     * @var VersionParser
     */
    private $versionParser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->versionParser = new VersionParser();
    }

    public function testParseConfig(): void
    {
        $config = new Config();
        $config->merge(['config' => ['filter' => [
            'ignore-unreachable' => true,
            'unfiltered-packages' => ['foo/bar'],
            'sources' => ['name' => ['type' => 'url', 'url' => 'https://example.org']],
        ]]]);

        $filterConfig = FilterListConfig::fromConfig($config, $this->versionParser);

        $this->assertNotNull($filterConfig);
        $this->assertTrue($filterConfig->ignoreUnreachable);
        $this->assertCount(1, $filterConfig->unfilteredPackages);
        $this->assertCount(1, $filterConfig->sources);

        $listConfig = $filterConfig->getOperationConfig('block');
        $this->assertCount(1, $listConfig->unfilteredPackages);
        $this->assertCount(1, $listConfig->sources);
    }

    public function testParseConfigDoesntApply(): void
    {
        $config = new Config();
        $config->merge(['config' => ['filter' => [
            'unfiltered-packages' => [['package' => 'foo/bar', 'constraint' => '*', 'apply' => 'audit'],
        ]]]]);

        $filterConfig = FilterListConfig::fromConfig($config, $this->versionParser);

        $this->assertNotNull($filterConfig);
        $this->assertCount(1, $filterConfig->unfilteredPackages);

        $listConfig = $filterConfig->getOperationConfig('block');

        $this->assertCount(0, $listConfig->unfilteredPackages);
    }

    public function testParseConfigWithInvalidSourceDefinition(): void
    {
        $config = new Config();
        $config->merge(['config' => ['filter' => [
            'sources' => ['name' => ['type' => 'url', 'url' => 'http://example.org']],
        ]]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid source config. "url" is required and must start with "https://".');

        FilterListConfig::fromConfig($config, $this->versionParser);
    }
}
