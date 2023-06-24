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

use Composer\Test\TestCase;

class StatusCommandTest extends TestCase
{
    public function testNoLocalChanges(): void 
    {
        $this->initTempComposer(['require' => ['root/req' => '1.*']]);

        $package = self::getPackage('root/req');
        $package->setType('metapackage');

        $this->createComposerLock([$package], []);
        $this->createInstalledJson([$package], []);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'status']);

        $this->assertSame('No local changes', trim($appTester->getDisplay(true)));
    }
}
