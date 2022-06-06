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

class DiagnoseCommandTest extends TestCase
{
    public function testCmdFail(): void
    {
        $this->initTempComposer(['name' => 'foo/bar', 'description' => 'test pkg']);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'diagnose']);

        $this->assertSame(1, $appTester->getStatusCode());

        $output = $appTester->getDisplay(true);
        $this->assertStringContainsString('Checking composer.json: <warning>WARNING</warning>
<warning>No license specified, it is recommended to do so. For closed-source software you may use "proprietary" as license.</warning>', $output);

        $this->assertStringContainsString('Checking git settings: OK
Checking http connectivity to packagist: OK
Checking https connectivity to packagist: OK
Checking github.com rate limit: ', $output);
    }

    public function testCmdSuccess(): void
    {
        $this->initTempComposer(['name' => 'foo/bar', 'description' => 'test pkg', 'license' => 'MIT']);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'diagnose']);

        $appTester->assertCommandIsSuccessful();

        $output = $appTester->getDisplay(true);
        $this->assertStringContainsString('Checking composer.json: OK', $output);

        $this->assertStringContainsString('Checking git settings: OK
Checking http connectivity to packagist: OK
Checking https connectivity to packagist: OK
Checking github.com rate limit: ', $output);
    }
}
