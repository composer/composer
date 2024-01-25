<?php declare(strict_types=1);

namespace Composer\Test\Command;

use Composer\Test\TestCase;

class SelfUpdateCommandTest extends TestCase
{
    public function testSuccessfulUpdate(): void
    {
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'self-update']);

        $appTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Upgrading to version', $appTester->getDisplay());
    }

    public function testUpdateToSpecificVersion(): void
    {
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'self-update', 'version' => '2.4.0']);
        
        $appTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Upgrading to version 2.4.0', $appTester->getDisplay());
    }

    public function testUpdateWithInvalidOptionThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "invalid-option" argument does not exist.');

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'self-update', 'invalid-option' => true]);   
    }

    public function testUpdateToDifferentChannel(): void
    {
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'self-update', '--stable' => true]);

        $appTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Upgrading to version', $appTester->getDisplay());
        $this->assertStringContainsString('stable channel', $appTester->getDisplay());
        
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'self-update', '--preview' => true]);
        
        $appTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Upgrading to version', $appTester->getDisplay());
        $this->assertStringContainsString('preview channel', $appTester->getDisplay());
        
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'self-update', '--snapshot' => true]);
        
        $appTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Upgrading to version', $appTester->getDisplay());
        $this->assertStringContainsString('snapshot channel', $appTester->getDisplay());
    }
}
