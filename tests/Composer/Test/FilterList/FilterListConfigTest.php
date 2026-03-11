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

    public function testListUsesConfigValues(): void
    {
        $config = new FilterListConfig($this->versionParser, [
            'lists' => ['test-list'],
            'ignore-unreachable' => true,
            'categories' => ['malware'],
            'exclude-categories' => ['other'],
            'dont-filter-packages' => ['foo/bar'],
        ]);

        $listConfig = $config->getListConfig('test-list', 'block');

        $this->assertNotNull($listConfig);
        $this->assertSame(['malware'], $listConfig->categories);
        $this->assertSame(['other'], $listConfig->excludeCategories);
        $this->assertCount(1, $listConfig->dontFilterPackages);
    }

    public function testListUsesListConfigValues(): void
    {
        $config = new FilterListConfig($this->versionParser, [
            'ignore-unreachable' => false,
            'categories' => ['other'],
            'exclude-categories' => ['malware'],
            'dont-filter-packages' => ['bar/foo'],
            'lists' => [[
                'name' => 'test-list',
                'categories' => ['malware'],
                'exclude-categories' => ['other'],
                'dont-filter-packages' => ['foo/bar'],
            ]],
        ]);

        $listConfig = $config->getListConfig('test-list', 'block');

        $this->assertNotNull($listConfig);
        $this->assertSame(['malware'], $listConfig->categories);
        $this->assertSame(['other'], $listConfig->excludeCategories);
        $this->assertCount(1, $listConfig->dontFilterPackages);
    }

    public function testListDoesntApply(): void
    {
        $config = new FilterListConfig($this->versionParser, [
            'ignore-unreachable' => false,
            'categories' => ['other'],
            'exclude-categories' => ['malware'],
            'dont-filter-packages' => ['bar/foo'],
            'lists' => [[
                'name' => 'test-list',
                'categories' => ['malware'],
                'exclude-categories' => ['other'],
                'dont-filter-packages' => ['foo/bar'],
                'apply' => 'audit',
            ]],
        ]);

        $listConfig = $config->getListConfig('test-list', 'block');

        $this->assertNull($listConfig);
    }

    public function testListIgnored(): void
    {
        $config = new FilterListConfig($this->versionParser, [
            'exclude-lists' => ['test-list'],
            'ignore-unreachable' => true,
            'categories' => ['malware'],
            'exclude-categories' => ['other'],
            'dont-filter-packages' => ['foo/bar'],
        ]);

        $this->assertNull($config->getListConfig('test-list', 'block'));
    }
}
