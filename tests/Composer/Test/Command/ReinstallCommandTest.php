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

class ReinstallCommandTest extends TestCase
{
    public function testReinstallCommand(): void
    {
        $this->initTempComposer(['require' => ['root/req' => '1.*']]);

        $rootReqPackage = self::getPackage('root/req');
        $rootReqPackage->setType('metapackage');

        $this->createComposerLock([$rootReqPackage], []);
        $this->createInstalledJson([$rootReqPackage], []);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'reinstall',
            '--no-progress' => true,
            'packages' => ['root/req']
        ]);

        $this->assertSame('- Removing root/req (1.0.0)
  - Installing root/req (1.0.0)', trim($appTester->getDisplay(true)));
    }

    public function testReinstallCommandWherePackageNotCurrentlyInstalled(): void 
    {
        
        $this->initTempComposer(['require' => ['root/req' => '1.*']]);

        $rootReqPackage = self::getPackage('root/req');
        $rootReqPackage->setType('metapackage');

        $this->createComposerLock([$rootReqPackage], []);
        $this->createInstalledJson([$rootReqPackage], []);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'reinstall',
            '--no-progress' => true,
            'packages' => ['root/anotherreq']
        ]);

        $this->assertSame('<warning>Pattern "root/anotherreq" does not match any currently installed packages.</warning>
<warning>Found no packages to reinstall, aborting.</warning>', trim($appTester->getDisplay(true)));
    }
}
