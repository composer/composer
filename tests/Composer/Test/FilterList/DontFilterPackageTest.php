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
use Composer\Test\TestCase;

class DontFilterPackageTest extends TestCase
{
    public function testFromConfigString(): void
    {
        $ignorePackage = DontFilterPackage::fromConfig('foo/bar');

        $this->assertSame('foo/bar', $ignorePackage->packageName);
        $this->assertSame('*', $ignorePackage->constraint);
        $this->assertNull($ignorePackage->reason);
        $this->assertSame('all', $ignorePackage->apply);
    }

    public function testFromConfigObject(): void
    {
        $config = DontFilterPackage::fromConfig('foo/bar');
        $ignorePackage = DontFilterPackage::fromConfig($config);

        $this->assertSame($config, $ignorePackage);
    }

    public function testFromConfigSimple(): void
    {
        $dontFilterPackage = DontFilterPackage::fromConfig(['foo/bar' => '>1.0']);

        $this->assertSame('foo/bar', $dontFilterPackage->packageName);
        $this->assertSame('>1.0', $dontFilterPackage->constraint);
        $this->assertNull($dontFilterPackage->reason);
        $this->assertSame('all', $dontFilterPackage->apply);
    }

    public function testFromConfigFullDefinition(): void
    {
        $dontFilterPackage = DontFilterPackage::fromConfig(['package' => 'foo/bar', 'constraint' => '<1.0', 'reason' => 'test reason', 'apply' => 'block']);

        $this->assertSame('foo/bar', $dontFilterPackage->packageName);
        $this->assertSame('<1.0', $dontFilterPackage->constraint);
        $this->assertSame('test reason', $dontFilterPackage->reason);
        $this->assertSame('block', $dontFilterPackage->apply);
    }
}
