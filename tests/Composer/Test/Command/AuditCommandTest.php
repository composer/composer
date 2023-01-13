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
use UnexpectedValueException;

class AuditCommandTest extends TestCase
{
    public function testSuccessfulResponseCodeWhenNoPackagesAreRequired(): void
    {
        $this->initTempComposer();

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'audit']);

        $appTester->assertCommandIsSuccessful();
        self::assertEquals('No packages - skipping audit.', trim($appTester->getDisplay(true)));
    }

    public function testErrorAuditingLockFileWhenItIsMissing(): void
    {
        $this->initTempComposer();
        $this->createInstalledJson([self::getPackage()]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            "Valid composer.json and composer.lock files are required to run this command with --locked"
        );

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'audit', '--locked' => true]);
    }
}
