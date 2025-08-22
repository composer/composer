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

use Composer\Composer;
use Composer\Test\TestCase;

class AboutCommandTest extends TestCase
{
    public function testAbout(): void
    {
        $composerVersion = Composer::getVersion();
        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(['command' => 'about']));
        self::assertStringContainsString("Composer - Dependency Manager for PHP - version $composerVersion", $appTester->getDisplay());

        self::assertStringContainsString("Composer is a dependency manager tracking local dependencies of your projects and libraries.", $appTester->getDisplay());
        self::assertStringContainsString("See https://getcomposer.org/ for more information.", $appTester->getDisplay());
    }
}
