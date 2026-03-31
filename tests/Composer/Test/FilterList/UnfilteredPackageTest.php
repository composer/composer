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

use Composer\FilterList\UnfilteredPackage;
use Composer\Package\Version\VersionParser;
use Composer\Test\TestCase;

class UnfilteredPackageTest extends TestCase
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

    public function testFromConfigString(): void
    {
        $unfilteredPackage = UnfilteredPackage::fromConfig('foo/bar', $this->versionParser);

        $this->assertSame('foo/bar', $unfilteredPackage->packageName);
        $this->assertSame('*', $unfilteredPackage->constraint->getPrettyString());
        $this->assertNull($unfilteredPackage->reason);
        $this->assertSame('all', $unfilteredPackage->apply);
    }

    public function testFromConfigMissingPackage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid unfiltered package config. "package" and "constraint" are requried.');

        UnfilteredPackage::fromConfig(['constraint' => '*'], $this->versionParser);
    }

    public function testFromConfigObject(): void
    {
        $config = UnfilteredPackage::fromConfig('foo/bar', $this->versionParser);
        $unfilteredPackage = UnfilteredPackage::fromConfig($config, $this->versionParser);

        $this->assertSame($config, $unfilteredPackage);
    }

    public function testFromConfigSimple(): void
    {
        $unfilteredPackage = UnfilteredPackage::fromConfig(['foo/bar' => '>1.0'], $this->versionParser);

        $this->assertSame('foo/bar', $unfilteredPackage->packageName);
        $this->assertSame('>1.0', $unfilteredPackage->constraint->getPrettyString());
        $this->assertNull($unfilteredPackage->reason);
        $this->assertSame('all', $unfilteredPackage->apply);
    }

    public function testFromConfigFullDefinition(): void
    {
        $unfilteredPackage = UnfilteredPackage::fromConfig(['package' => 'foo/bar', 'constraint' => '<1.0', 'reason' => 'test reason', 'apply' => 'block'], $this->versionParser);

        $this->assertSame('foo/bar', $unfilteredPackage->packageName);
        $this->assertSame('<1.0', $unfilteredPackage->constraint->getPrettyString());
        $this->assertSame('test reason', $unfilteredPackage->reason);
        $this->assertSame('block', $unfilteredPackage->apply);
    }
}
