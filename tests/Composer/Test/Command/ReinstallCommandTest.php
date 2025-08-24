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
use Generator;

class ReinstallCommandTest extends TestCase
{
    /**
     * @dataProvider caseProvider
     * @param array<mixed> $options
     */
    public function testReinstallCommand(array $options, string $expected): void
    {
        $this->initTempComposer([
            'require' => [
                'root/req' => '1.*',
            ],
            'require-dev' => [
                'root/anotherreq' => '2.*',
                'root/anotherreq2' => '2.*',
                'root/lala' => '2.*',
            ],
        ]);

        $rootReqPackage = self::getPackage('root/req');
        $anotherReqPackage = self::getPackage('root/anotherreq');
        $anotherReqPackage2 = self::getPackage('root/anotherreq2');
        $anotherReqPackage3 = self::getPackage('root/lala');
        $rootReqPackage->setType('metapackage');
        $anotherReqPackage->setType('metapackage');
        $anotherReqPackage2->setType('metapackage');
        $anotherReqPackage3->setType('metapackage');

        $this->createComposerLock([$rootReqPackage], [$anotherReqPackage, $anotherReqPackage2, $anotherReqPackage3]);
        $this->createInstalledJson([$rootReqPackage], [$anotherReqPackage, $anotherReqPackage2, $anotherReqPackage3]);

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge([
            'command' => 'reinstall',
            '--no-progress' => true,
            '--no-plugins' => true,
        ], $options));

        self::assertSame($expected, trim($appTester->getDisplay(true)));
    }

    public function caseProvider(): Generator
    {
        yield 'reinstall a package by name' => [
            ['packages' => ['root/req', 'root/anotherreq*']],
'- Removing root/req (1.0.0)
  - Removing root/anotherreq2 (1.0.0)
  - Removing root/anotherreq (1.0.0)
  - Installing root/anotherreq (1.0.0)
  - Installing root/anotherreq2 (1.0.0)
  - Installing root/req (1.0.0)',
        ];

        yield 'reinstall packages by type' => [
            ['--type' => ['metapackage']],
'- Removing root/req (1.0.0)
  - Removing root/lala (1.0.0)
  - Removing root/anotherreq2 (1.0.0)
  - Removing root/anotherreq (1.0.0)
  - Installing root/anotherreq (1.0.0)
  - Installing root/anotherreq2 (1.0.0)
  - Installing root/lala (1.0.0)
  - Installing root/req (1.0.0)',
        ];

        yield 'reinstall a package that is not installed' => [
            ['packages' => ['root/unknownreq']],
            '<warning>Pattern "root/unknownreq" does not match any currently installed packages.</warning>
<warning>Found no packages to reinstall, aborting.</warning>',
        ];
    }
}
