<?php

namespace Composer\Test\Command;

use Composer\Composer;
use Composer\Test\TestCase;

class AboutCommandTest extends TestCase
{

    public function testAbout(): void
    {
        $composerVersion = Composer::getVersion();
        $appTester = $this->getApplicationTester();
        $this->assertSame(0, $appTester->run(['command' => 'about']));
        $this->assertStringContainsString("Composer - Dependency Manager for PHP - version $composerVersion", $appTester->getDisplay());

        $this->assertStringContainsString("Composer is a dependency manager tracking local dependencies of your projects and libraries.", $appTester->getDisplay());
        $this->assertStringContainsString("See https://getcomposer.org/ for more information.", $appTester->getDisplay());
    }
}
