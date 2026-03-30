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
    public function testExclude(): void
    {
        $list = new ListConfig('list', 'all', 'reason');
        $this->assertSame('list', $list->name);
        $this->assertFalse($list->exclude);

        $list = new ListConfig('!list', 'all', 'reason');
        $this->assertSame('list', $list->name);
        $this->assertTrue($list->exclude);
    }

    public function testExpandDefaults(): void
    {
        $list = new ListConfig('list', 'all', 'reason');
        $this->assertSame([$list], $list->expandDefaults(['default-list']));

        $list = new ListConfig('defaults', 'all', 'reason');
        $this->assertEquals([
            new ListConfig('default-list-one', 'all', 'reason', true),
            new ListConfig('default-list-two', 'all', 'reason', true),
        ], $list->expandDefaults(['default-list-one', 'default-list-two']));
    }

    public function testFromConfig(): void
    {
        $this->assertEquals(new ListConfig('list'), ListConfig::fromConfig('list'));
        $this->assertEquals(new ListConfig('list'), ListConfig::fromConfig(['name' => 'list']));
        $this->assertEquals(new ListConfig('list', 'audit', 'reason'), ListConfig::fromConfig(['name' => 'list', 'apply' => 'audit', 'reason' => 'reason']));
        $this->assertEquals(new ListConfig('list', 'audit', 'reason', true), ListConfig::fromConfig(['name' => 'list', 'apply' => 'audit', 'reason' => 'reason'], ['list']));
    }
}
