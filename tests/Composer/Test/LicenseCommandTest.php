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

use Composer\Json\JsonFile;
use Composer\Test\TestCase;
use PHPUnit\Util\Json;

class LicenseCommandTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempComposer([
            'name' => 'test/pkg',
            'license' => 'MIT',
            'require' => [
                'first/pkg' => '^2.0',
                'second/pkg' => '3.*',
                'third/pkg' => '^1.3',
            ],
            'require-dev' => [
                'dev/pkg' => '~2.0',
            ],
        ]);

        $first = $this->getPackage('first/pkg', '2.3.4');
        $first->setLicense(['MIT']);

        $second = $this->getPackage('second/pkg', '3.4.0');
        $second->setLicense(['LGPL-2.0-only']);
        $second->setHomepage('https://example.org');

        $third = $this->getPackage('third/pkg', '1.5.4');

        $dev = $this->getPackage('dev/pkg', '2.3.4.5');
        $dev->setLicense(['MIT']);

        $this->createInstalledJson([$first, $second, $third], [$dev]);
        $this->createComposerLock([$first, $second, $third], [$dev]);
    }

    public function testBasicRun()
    {
        $appTester = $this->getApplicationTester();
        $this->assertSame(0, $appTester->run(['command' => 'license']));

        $expected = <<<TEXT
Name: test/pkg
Version: 1.0.0+no-version-set
Licenses: MIT
Dependencies:

Name       Version Licenses      
dev/pkg    2.3.4.5 MIT           
first/pkg  2.3.4   MIT           
second/pkg 3.4.0   LGPL-2.0-only 
third/pkg  1.5.4   none          

TEXT;

        $this->assertSame($expected, $appTester->getDisplay());
    }

    public function testNoDev()
    {
        $appTester = $this->getApplicationTester();
        $this->assertSame(0, $appTester->run(['command' => 'license', '--no-dev' => true]));

        $expected = <<<TEXT
Name: test/pkg
Version: 1.0.0+no-version-set
Licenses: MIT
Dependencies:

Name       Version Licenses      
first/pkg  2.3.4   MIT           
second/pkg 3.4.0   LGPL-2.0-only 
third/pkg  1.5.4   none          

TEXT;

        $this->assertSame($expected, $appTester->getDisplay());
    }

    public function testFormatJson()
    {
        $appTester = $this->getApplicationTester();
        $this->assertSame(0, $appTester->run(['command' => 'license', '--format' => 'json'], ['capture_stderr_separately' => true]));

        $expected = <<<JSON
{
    "name": "test/pkg",
    "version": "1.0.0+no-version-set",
    "license": [
        "MIT"
    ],
    "dependencies": {
        "dev/pkg": {
            "version": "2.3.4.5",
            "license": [
                "MIT"
            ]
        },
        "first/pkg": {
            "version": "2.3.4",
            "license": [
                "MIT"
            ]
        },
        "second/pkg": {
            "version": "3.4.0",
            "license": [
                "LGPL-2.0-only"
            ]
        },
        "third/pkg": {
            "version": "1.5.4",
            "license": []
        }
    }
}

JSON;

        $this->assertSame($expected, $appTester->getDisplay());
    }

    public function testFormatSummary()
    {
        $appTester = $this->getApplicationTester();
        $this->assertSame(0, $appTester->run(['command' => 'license', '--format' => 'summary']));

        $expected = <<<TEXT
 --------------- ------------------------ 
  License         Number of dependencies  
 --------------- ------------------------ 
  MIT             2                       
  LGPL-2.0-only   1                       
  none            1                       
 --------------- ------------------------ 


TEXT;

        $this->assertSame($expected, $appTester->getDisplay());
    }

    public function testFormatUnknown()
    {
        $this->expectException(\RuntimeException::class);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'license', '--format' => 'unknown']);
    }
}
