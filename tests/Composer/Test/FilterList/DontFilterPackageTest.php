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

use Composer\FilterList\DontFilterPackage;
use Composer\Package\Version\VersionParser;
use Composer\Test\TestCase;

class DontFilterPackageTest extends TestCase
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
        $ignorePackage = DontFilterPackage::fromConfig('foo/bar', $this->versionParser);

        $this->assertSame('foo/bar', $ignorePackage->packageName);
        $this->assertSame('*', $ignorePackage->constraint->getPrettyString());
        $this->assertNull($ignorePackage->reason);
        $this->assertSame('all', $ignorePackage->apply);
    }

    public function testFromConfigObject(): void
    {
        $config = DontFilterPackage::fromConfig('foo/bar', $this->versionParser);
        $ignorePackage = DontFilterPackage::fromConfig($config, $this->versionParser);

        $this->assertSame($config, $ignorePackage);
    }

    public function testFromConfigSimple(): void
    {
        $dontFilterPackage = DontFilterPackage::fromConfig(['foo/bar' => '>1.0'], $this->versionParser);

        $this->assertSame('foo/bar', $dontFilterPackage->packageName);
        $this->assertSame('>1.0', $dontFilterPackage->constraint->getPrettyString());
        $this->assertNull($dontFilterPackage->reason);
        $this->assertSame('all', $dontFilterPackage->apply);
    }

    public function testFromConfigFullDefinition(): void
    {
        $dontFilterPackage = DontFilterPackage::fromConfig(['package' => 'foo/bar', 'constraint' => '<1.0', 'reason' => 'test reason', 'apply' => 'block'], $this->versionParser);

        $this->assertSame('foo/bar', $dontFilterPackage->packageName);
        $this->assertSame('<1.0', $dontFilterPackage->constraint->getPrettyString());
        $this->assertSame('test reason', $dontFilterPackage->reason);
        $this->assertSame('block', $dontFilterPackage->apply);
    }
}
