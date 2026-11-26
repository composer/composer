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

namespace Composer\Test\Command;

use Composer\Package\LinkedPackages;
use Composer\Test\TestCase;

class UnlinkCommandTest extends TestCase
{
    public function testUnlink(): void
    {
        $dir = $this->initTempComposer(['name' => 'acme/foo']);
        $linkedPackages = new LinkedPackages($dir.'/composer-home');
        $linkedPackages->link('acme/foo', $dir);
        $linkedPackages->link('acme/bar', $dir);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'unlink']);
        $appTester->assertCommandIsSuccessful();

        self::assertSame('Unlinked acme/foo from '.$linkedPackages->getFile(), trim($appTester->getDisplay(true)));
        self::assertSame(['acme/bar'], array_keys($linkedPackages->getLinks()));
    }

    public function testUnlinkFailsIfNotLinked(): void
    {
        $dir = $this->initTempComposer();

        $appTester = $this->getApplicationTester();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('acme/foo is not linked in '.$dir.'/composer-home/linked-packages.json.');
        $appTester->run(['command' => 'unlink', 'package' => 'acme/foo']);
    }
}
