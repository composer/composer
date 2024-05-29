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

namespace Composer\Test\Util;

use Composer\MetadataMinifier\MetadataMinifier;
use Composer\Package\CompletePackage;
use Composer\Package\Dumper\ArrayDumper;
use PHPUnit\Framework\TestCase;

class MetadataMinifierTest extends TestCase
{
    public function testMinifyExpand(): void
    {
        $package1 = new CompletePackage('foo/bar', '2.0.0.0', '2.0.0');
        $package1->setScripts(['foo' => ['bar']]);
        $package1->setLicense(['MIT']);
        $package2 = new CompletePackage('foo/bar', '1.2.0.0', '1.2.0');
        $package2->setLicense(['GPL']);
        $package2->setHomepage('https://example.org');
        $package3 = new CompletePackage('foo/bar', '1.0.0.0', '1.0.0');
        $package3->setLicense(['GPL']);
        $dumper = new ArrayDumper();

        $minified = [
            ['name' => 'foo/bar', 'version' => '2.0.0', 'version_normalized' => '2.0.0.0', 'type' => 'library', 'scripts' => ['foo' => ['bar']], 'license' => ['MIT']],
            ['version' => '1.2.0', 'version_normalized' => '1.2.0.0', 'license' => ['GPL'], 'homepage' => 'https://example.org', 'scripts' => '__unset'],
            ['version' => '1.0.0', 'version_normalized' => '1.0.0.0', 'homepage' => '__unset'],
        ];

        $source = [$dumper->dump($package1), $dumper->dump($package2), $dumper->dump($package3)];

        self::assertSame($minified, MetadataMinifier::minify($source));
        self::assertSame($source, MetadataMinifier::expand($minified));
    }
}
