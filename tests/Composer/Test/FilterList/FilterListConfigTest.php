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

    public function testParseConfig(): void
    {
        $config = new FilterListConfig($this->versionParser, [
            'ignore-unreachable' => true,
            'dont-filter-packages' => ['foo/bar'],
        ]);

        $listConfig = $config->getConfig('block');

        $this->assertNotNull($listConfig);
        $this->assertCount(1, $listConfig->dontFilterPackages);
    }
}
