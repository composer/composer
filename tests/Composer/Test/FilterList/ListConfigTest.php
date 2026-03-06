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

use Composer\FilterList\ListConfig;
use Composer\Test\TestCase;

class ListConfigTest extends TestCase
{
    public function testApply(): void
    {
        $config = (new ListConfig())->apply([
            'ignore-unreachable' => true,
            'categories' => ['malware'],
            'exclude-categories' => ['other'],
            'dont-filter-packages' => ['foo/bar', ['package' => 'foo/other', 'constraint' => '*', 'apply' => 'block']],
            'apply' => 'all',
        ], 'audit');

        $this->assertNotNull($config);
        $this->assertSame(['malware'], $config->categories);
        $this->assertSame(['other'], $config->excludeCategories);
        $this->assertCount(1, $config->dontFilterPackages);
        $this->assertArrayHasKey('foo/bar', $config->dontFilterPackages);
    }

    public function testDoesntApply(): void
    {
        $this->assertNull((new ListConfig())->apply(['apply' => 'block'], 'audit'));
    }
}
